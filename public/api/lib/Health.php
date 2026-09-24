<?php
/**
 * Wie es den fremden Diensten in den letzten 24 Stunden ging.
 *
 * DAS PROBLEM, aus dem das hier entstanden ist: Jeder Provider fällt bei
 * jedem Fehler stillschweigend zurück. Das ist als Verhalten richtig — eine
 * kaputte Wagenreihung darf die Suche nicht mitreißen —, aber es hat einen
 * Preis: bahn.expert hat seine Schnittstelle verschoben, und es ist
 * WOCHENLANG niemandem aufgefallen. Die Baureihe fehlte einfach. Ein Dienst,
 * der leise degradiert, braucht ein lautes Gegenstück.
 *
 * Also: jeder Aufruf nach draußen wird gezählt, nach Dienst und Stunde, und
 * `check.php` zeigt das Ergebnis. Aus „seit Wochen kaputt" wird „in zehn
 * Sekunden sichtbar".
 *
 * WARUM STATISCH: Der Haken sitzt in Http::request(), und dort ist weder das
 * Cache-Verzeichnis noch der Providername bekannt. Beides über alle
 * Konstruktoren durchzureichen wäre viel Umbau für eine Nebensache — und
 * hätte genau die Lücken, um die es geht: Overpass baut sich seinen
 * Http-Client selbst, und der nächste Provider tut es wieder. Ein statischer
 * Haken erwischt jeden Aufruf, auch die, die man vergessen würde. Der
 * Zustand lebt genau eine Anfrage lang.
 *
 * SPEICHERFORM: eine JSON-Datei im Cache-Verzeichnis, Stundeneimer, 24
 * Stück. Kein Datenbankserver, damit es auf jedem Shared Hosting läuft.
 */
final class Health
{
    /** So viele Stundeneimer werden behalten. */
    private const HOURS = 24;

    /**
     * Host -> Dienstname. Der Host steht in jeder URL, also braucht keine
     * einzige Aufrufstelle angefasst zu werden.
     */
    private const PROVIDERS = [
        'fahrplan.oebb.at'  => 'oebb',
        'int.bahn.de'       => 'db',
        'www.bahn.de'       => 'db',
        'bahn.expert'       => 'wagenreihung',
        'www.mvg.de'        => 'mvg',
        'strecken-info.de'  => 'streckeninfo',
        'www.strecken-info.de' => 'streckeninfo',
        // Schweizer Prognosen: der Dienst drosselt (HTTP 429) - das soll
        // sichtbar sein, bevor jemand fragt, warum die Echtzeit fehlt.
        'transport.opendata.ch' => 'opendata.ch',
    ];

    private static ?string $dir = null;
    private static array $puffer = [];

    /** Einmal je Anfrage aufrufen, dann zählt alles Weitere von selbst. */
    public static function watch(string $cacheDir): void
    {
        self::$dir = rtrim($cacheDir, '/');
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * Einen Aufruf verbuchen. Wird aus Http::request() gerufen und ist
     * absichtlich billig — geschrieben wird erst am Ende der Anfrage.
     */
    public static function note(string $url, bool $ok, string $error = ''): void
    {
        if (self::$dir === null) {
            return;
        }
        $dienst = self::providerOf($url);
        if ($dienst === null) {
            return; // fremder Host, geht uns nichts an
        }

        $eimer = gmdate('Y-m-d\TH');
        $e = self::$puffer[$dienst][$eimer] ?? ['ok' => 0, 'fail' => 0, 'err' => ''];
        $e[$ok ? 'ok' : 'fail']++;
        if (!$ok && $error !== '') {
            $e['err'] = mb_substr($error, 0, 120);
        }
        self::$puffer[$dienst][$eimer] = $e;
    }

    /**
     * Den zuletzt verbuchten Fehlschlag eines Dienstes als Erfolg umbuchen.
     *
     * Für Antworten, die nach HTTP ein Fehler sind, in der Sache aber genau
     * das, was gefragt war. strecken.info beantwortet jede Probe bei der
     * Suche nach dem aktuellen Datenstand mit HTTP 400 ("Revision zu alt",
     * "existiert noch nicht") - der Dienst funktioniert, die Probe lag nur
     * daneben. Ohne das Umbuchen meldete check.php "86 % fehlgeschlagen",
     * während die Baustellen einwandfrei ankamen.
     */
    public static function retract(string $url): void
    {
        if (self::$dir === null) {
            return;
        }
        $dienst = self::providerOf($url);
        $eimer = gmdate('Y-m-d\TH');
        if ($dienst === null || (self::$puffer[$dienst][$eimer]['fail'] ?? 0) < 1) {
            return;
        }
        self::$puffer[$dienst][$eimer]['fail']--;
        self::$puffer[$dienst][$eimer]['ok']++;
        if (self::$puffer[$dienst][$eimer]['fail'] === 0) {
            self::$puffer[$dienst][$eimer]['err'] = '';
        }
    }

    /** Schreibt den Puffer in die Datei — einmal je Anfrage. */
    public static function flush(): void
    {
        if (self::$dir === null || self::$puffer === []) {
            return;
        }

        $pfad = self::$dir . '/health.json';
        $fh = @fopen($pfad, 'c+');
        if ($fh === false) {
            self::$puffer = [];
            return;
        }

        // Unter Sperre lesen, zusammenführen, schreiben: zwei gleichzeitige
        // Anfragen dürfen sich nicht gegenseitig überschreiben.
        if (flock($fh, LOCK_EX)) {
            $roh = stream_get_contents($fh);
            $daten = json_decode((string) $roh, true);
            $daten = is_array($daten) ? $daten : [];

            foreach (self::$puffer as $dienst => $eimer) {
                foreach ($eimer as $stunde => $e) {
                    $alt = $daten[$dienst][$stunde] ?? ['ok' => 0, 'fail' => 0, 'err' => ''];
                    $daten[$dienst][$stunde] = [
                        'ok'   => (int) $alt['ok'] + $e['ok'],
                        'fail' => (int) $alt['fail'] + $e['fail'],
                        'err'  => $e['err'] !== '' ? $e['err'] : (string) ($alt['err'] ?? ''),
                    ];
                }
            }

            $daten = self::prune($daten);

            $json = json_encode($daten, JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $json);
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        self::$puffer = [];
    }

    /**
     * Was in den letzten 24 Stunden los war.
     *
     * @return array<string,array{ok:int,fail:int,err:string,quote:float}>
     */
    public static function summary(string $cacheDir): array
    {
        $roh = @file_get_contents(rtrim($cacheDir, '/') . '/health.json');
        $daten = $roh === false ? [] : json_decode($roh, true);
        if (!is_array($daten)) {
            return [];
        }

        $grenze = gmdate('Y-m-d\TH', time() - self::HOURS * 3600);
        $out = [];
        foreach ($daten as $dienst => $eimer) {
            $ok = 0;
            $fail = 0;
            $err = '';
            foreach ((array) $eimer as $stunde => $e) {
                if ((string) $stunde < $grenze) {
                    continue;
                }
                $ok   += (int) ($e['ok'] ?? 0);
                $fail += (int) ($e['fail'] ?? 0);
                if (($e['err'] ?? '') !== '') {
                    $err = (string) $e['err'];
                }
            }
            if ($ok + $fail === 0) {
                continue;
            }
            $out[$dienst] = [
                'ok'    => $ok,
                'fail'  => $fail,
                'err'   => $err,
                'quote' => $fail / ($ok + $fail),
            ];
        }
        ksort($out);
        return $out;
    }

    /** Eimer, die älter als HOURS sind, fliegen raus. */
    private static function prune(array $daten): array
    {
        $grenze = gmdate('Y-m-d\TH', time() - self::HOURS * 3600);
        foreach ($daten as $dienst => $eimer) {
            foreach ((array) $eimer as $stunde => $_) {
                if ((string) $stunde < $grenze) {
                    unset($daten[$dienst][$stunde]);
                }
            }
            if (($daten[$dienst] ?? []) === []) {
                unset($daten[$dienst]);
            }
        }
        return $daten;
    }

    private static function providerOf(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return null;
        }
        if (isset(self::PROVIDERS[$host])) {
            return self::PROVIDERS[$host];
        }
        // Overpass läuft über vier wechselnde Instanzen; die eint nur das Wort.
        if (str_contains($host, 'overpass') || str_contains($host, 'osm')) {
            return 'overpass';
        }
        return null;
    }
}
