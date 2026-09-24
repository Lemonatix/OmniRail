<?php
/**
 * Wagenreihung und Baureihe - direkt bei bahn.de, ersatzweise bahn.expert.
 *
 * JETZT DIREKT BEI DER DB. Hier stand lange, der DB-Endpunkt
 * reisebegleitung/wagenreihung/vehicle-sequence antworte auf jede von außen
 * gebaute Anfrage mit HTTP 422. Die Kombination, die geht (nachgemessen
 * 2026-09, 8 von 9 Zügen, der neunte ohne Reihung): administrationId=80,
 * category, date, evaNumber, number und time als UTC mit Millisekunden
 * ("2026-09-24T06:03:00.000Z") - über das Browser-TLS-Profil wie die
 * übrige DB-Anbindung. Anlass war, dass bahn.expert seine Schnittstelle
 * erneut verschoben hatte (/api/trpc antwortete wie vorher /rpc mit HTTP 500
 * "Only HTML requests are supported here") und die Baureihe wochenlang fehlte.
 *
 * Die DB-Antwort ist dazu die reichere: der Bahnsteig mit seinen Sektoren in
 * Metern, und jeder Wagen mit Nummer, Klasse, Sektor und Lage am Bahnsteig.
 * Daraus entsteht der Wagenplan im Umstiegsplan - siehe sequence().
 *
 * bahn.expert bleibt als Quelle wählbar (config: wagenreihung.source).
 *
 * WAS ES LIEFERT:
 *   - Baureihe: "412" / "ICE 4 (BR412)"  <- genau das, was in den
 *     Fahrplandaten fehlt
 *   - Wagenliste mit Klasse, Ausstattung und teilweise Auslastung
 *
 * GRENZEN:
 *   - nur deutscher Fernverkehr (ICE, IC, EC)
 *   - nur am Reisetag, meist erst wenige Stunden vor Abfahrt
 *   - bahn.expert ist ein privat betriebenes Projekt, kein offizieller Dienst.
 *     Deshalb: aggressiv cachen, höchstens ein Zug pro Abschnitt, und bei
 *     jedem Fehler stillschweigend ohne Baureihe weitermachen.
 *
 * OFFIZIELLE ALTERNATIVE:
 * Der DB API Marketplace bietet mit RIS::Transports dieselben Daten unter
 * Vertrag und mit API-Key. Wer das Tool ernsthaft betreibt, sollte dorthin
 * wechseln - siehe README.
 */
final class CoachSequence
{
    private Http $http;
    private array $cfg;
    private Cache $cache;

    /**
     * Bauart-Kennungen der DB auf die Baureihen, die trains.js kennt.
     *
     * Zwei Schreibweisen, beide nachgesehen: "I4080".."I4088" (ICE 3neo) -
     * Baureihe vorn, Wagen hinten - und "I0812", "I1412" (ICE 4) - Wagen
     * vorn, Baureihe hinten.
     */
    private const SERIES_NAMES = [
        '401'  => 'ICE 1 (BR 401)',
        '402'  => 'ICE 2 (BR 402)',
        '403'  => 'ICE 3 (BR 403)',
        '406'  => 'ICE 3 (BR 406)',
        '407'  => 'ICE 3 (BR 407)',
        '408'  => 'ICE 3neo (BR 408)',
        '411'  => 'ICE T (BR 411)',
        '415'  => 'ICE T (BR 415)',
        '412'  => 'ICE 4 (BR 412)',
        '6110' => 'ICE L',
        '4110' => 'IC 2 (KISS)',
    ];

    private string $source;

    public function __construct(Http $http, array $cfg, Cache $cache)
    {
        $this->source = (string) ($cfg['source'] ?? 'bahnde');
        // bahn.de blockt ohne Browser-TLS, wie überall sonst auch.
        $this->http  = $this->source === 'bahnde' ? $http->withBrowserTls() : $http;
        $this->cfg   = $cfg;
        $this->cache = $cache;
    }

    /**
     * Die Wagenreihung eines Zuges an einem Bahnhof, für den Umstiegsplan.
     *
     * @param string $timeIso geplante Abfahrt dort (mit Zone)
     * @return ?array{platform:?string, length:float, sectors:array, vehicles:array, trains:array}
     */
    public function sequence(string $eva, string $number, string $category, string $timeIso): ?array
    {
        if ($this->source !== 'bahnde') {
            return null;
        }
        $key = 'seq:' . self::key($eva, $number, $category, $timeIso);
        $cached = $this->cache->get($key, 600);
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }
        $url = $this->url($eva, $number, $category, $timeIso);
        if ($url === null) {
            return null;
        }
        $res = $this->http->getJson($url, $this->headers());
        $seq = ($res['ok'] && is_array($res['json'])) ? self::mapSequence($res['json']) : null;
        $this->cache->set($key, $seq ?? '');
        return $seq;
    }

    /**
     * Die Antwort von vehicle-sequence, auf das Nötige gekürzt.
     *
     * Positionen in Metern ab dem Anfang des Bahnsteigs (Sektor A).
     */
    public static function mapSequence(array $j): ?array
    {
        $plat = $j['platform'] ?? null;
        $vehicles = [];
        $trains = [];
        foreach (($j['groups'] ?? []) as $g) {
            $t = $g['transport'] ?? [];
            $trains[] = [
                'number'      => (string) ($t['number'] ?? ''),
                'category'    => (string) ($t['category'] ?? ''),
                'destination' => (string) ($t['destination']['name'] ?? ''),
            ];
            foreach (($g['vehicles'] ?? []) as $v) {
                $pos = $v['platformPosition'] ?? [];
                if (!isset($pos['start'], $pos['end'])) {
                    continue;
                }
                $typ = $v['type'] ?? [];
                $kat = (string) ($typ['category'] ?? '');
                $ausstattung = array_column(array_filter(
                    (array) ($v['amenities'] ?? []),
                    static fn($a) => ($a['status'] ?? '') !== 'UNAVAILABLE'
                ), 'type');
                $vehicles[] = [
                    'n'      => isset($v['wagonIdentificationNumber']) ? (string) $v['wagonIdentificationNumber'] : '',
                    'first'  => !empty($typ['hasFirstClass']),
                    'second' => !empty($typ['hasEconomyClass']),
                    'dining' => str_contains($kat, 'DINING') || str_contains($kat, 'BISTRO'),
                    'loco'   => str_contains($kat, 'LOCOMOTIVE') || str_contains($kat, 'POWERCAR'),
                    'bike'   => in_array('BIKE_SPACE', $ausstattung, true),
                    'wheelchair' => in_array('WHEELCHAIR_SPACE', $ausstattung, true),
                    'closed' => ($v['status'] ?? 'OPEN') !== 'OPEN',
                    'sector' => (string) ($pos['sector'] ?? ''),
                    'start'  => (float) $pos['start'],
                    'end'    => (float) $pos['end'],
                    'group'  => count($trains) - 1,
                ];
            }
        }
        if ($vehicles === []) {
            return null;
        }
        $sectors = [];
        foreach ((array) ($plat['sectors'] ?? []) as $sct) {
            if (isset($sct['name'], $sct['start'], $sct['end'])) {
                $sectors[] = ['name' => (string) $sct['name'], 'start' => (float) $sct['start'], 'end' => (float) $sct['end']];
            }
        }
        $ende = max(array_merge([(float) ($plat['end'] ?? 0)], array_column($vehicles, 'end')));
        return [
            'platform' => isset($j['departurePlatform']) ? (string) $j['departurePlatform'] : ($plat['name'] ?? null),
            'length'   => $ende,
            'sectors'  => $sectors,
            'vehicles' => $vehicles,
            'trains'   => $trains,
        ];
    }

    /**
     * Baureihe aus den Bauart-Kennungen der Wagen - die häufigste bekannte.
     *
     * @return ?array{series:string, seriesName:string}
     */
    public static function seriesFromVehicles(array $j, string $category): ?array
    {
        $zaehler = [];
        foreach (($j['groups'] ?? []) as $g) {
            foreach (($g['vehicles'] ?? []) as $v) {
                $ct = (string) ($v['type']['constructionType'] ?? '');
                $s = null;
                if (preg_match('/^I\d(412|812)$/', $ct)) {
                    $s = '412';
                } elseif (preg_match('/^I(\d{3})\d$/', $ct, $m)) {
                    $s = $m[1] === '411' && $category === 'IC' ? '4110' : $m[1];
                } elseif (str_starts_with($ct, 'R89') && $category === 'ICE') {
                    $s = '6110';
                }
                if ($s !== null && isset(self::SERIES_NAMES[$s])) {
                    $zaehler[$s] = ($zaehler[$s] ?? 0) + 1;
                }
            }
        }
        if ($zaehler === []) {
            return null;
        }
        arsort($zaehler);
        $s = (string) array_key_first($zaehler);
        return ['series' => $s, 'seriesName' => self::SERIES_NAMES[$s]];
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return $this->source === 'bahnde'
            ? [
                'Accept'          => 'application/json',
                'Accept-Language' => 'de-DE,de;q=0.9',
                'Referer'         => 'https://www.bahn.de/',
                'User-Agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ]
            : ['User-Agent' => 'train-maxxing/1.0 (privates Fahrplanwerkzeug)'];
    }

    /**
     * Ergänzt die Abschnitte MEHRERER Verbindungen um 'series' und
     * 'seriesName' — in einem Rutsch und mit gleichzeitigen Anfragen.
     *
     * WARUM NICHT JE VERBINDUNG: Die Wagenreihung braucht eine Anfrage je
     * Zug. Sechs Trefferkarten mit je zwei Zügen sind zwölf Round-Trips, und
     * nacheinander abgearbeitet dauerte die Suche dadurch 27 statt 8
     * Sekunden — nachgemessen Frankfurt–Hamburg. Für eine Angabe, die nur
     * ein Zusatz zur Trefferliste ist, ist das kein vertretbarer Preis.
     *
     * Also: erst alle offenen Abfragen einsammeln, nach Zug entdoppeln (in
     * sechs Verbindungen fahren oft dieselben Züge), was im Cache liegt
     * gleich bedienen, den Rest parallel holen. Aus zwölf Round-Trips wird
     * einer.
     *
     * @param array[] $journeys
     * @param string  $travelDate YYYY-MM-DD
     * @return array[] dieselben Verbindungen, ergänzt
     */
    public function enrichAll(array $journeys, string $travelDate): array
    {
        if (!$this->isToday($travelDate)) {
            return $journeys; // Wagenreihung gibt es nur am Reisetag
        }

        // --- 1. Einsammeln, was überhaupt zu holen wäre -------------------
        $offen  = [];   // Cache-Schlüssel => ['eva','num','cat','dep']
        $stellen = [];  // Cache-Schlüssel => [[journeyIdx, legIdx], …]

        foreach ($journeys as $ji => $journey) {
            foreach (($journey['legs'] ?? []) as $li => $leg) {
                if (($leg['mode'] ?? '') !== 'train') {
                    continue;
                }

                // Steht die Baureihe schon da und ist die Beobachtung frisch,
                // sparen wir uns die Anfrage. Wagenreihungen wechseln zum
                // Fahrplanwechsel, nicht von Tag zu Tag - siehe Fleet.
                $gelernt = $leg['seriesLearned'] ?? null;
                if ($gelernt !== null && $gelernt <= Fleet::TRUST_DAYS) {
                    continue;
                }

                $cat = strtoupper(trim((string) ($leg['category'] ?? '')));
                $num = trim((string) ($leg['trainNumber'] ?? ''));
                $eva = (string) ($leg['from']['id'] ?? '');
                $dep = (string) ($leg['departure'] ?? '');

                // Nur deutscher Fernverkehr - alles andere hat keine Wagenreihung.
                if ($num === '' || $dep === '' || !str_starts_with($eva, '80')) {
                    continue;
                }
                if (!in_array($cat, ['ICE', 'IC', 'EC'], true)) {
                    continue;
                }

                $key = self::key($eva, $num, $cat, $dep);
                $offen[$key]     = ['eva' => $eva, 'num' => $num, 'cat' => $cat, 'dep' => $dep];
                $stellen[$key][] = [$ji, $li];
            }
        }

        // --- 2. Was im Cache liegt, kostet nichts -------------------------
        $treffer = [];
        foreach ($offen as $key => $z) {
            $cached = $this->cache->get($key, 1800);
            if ($cached !== null) {
                $treffer[$key] = $cached === '' ? null : $cached;
                unset($offen[$key]);
            }
        }

        // --- 3. Der Rest, gleichzeitig und gedeckelt ----------------------
        //
        // Der Deckel gilt für die GANZE Suche, nicht je Verbindung: sonst
        // wächst die Last mit der Zahl der Treffer, und bahn.expert ist ein
        // privates Projekt. Was diesmal nicht drankommt, holt der nächste
        // Aufruf - und was einmal geholt wurde, merkt sich Fleet.
        $deckel = max(1, (int) ($this->cfg['max_lookups'] ?? 12));
        $offen  = array_slice($offen, 0, $deckel, true);

        $urls = [];
        foreach ($offen as $key => $z) {
            $url = $this->url($z['eva'], $z['num'], $z['cat'], $z['dep']);
            if ($url !== null) {
                $urls[$key] = $url;
            }
        }

        $antworten = $this->http->getJsonAll($urls, $this->headers());

        foreach ($antworten as $key => $res) {
            $info = $this->parse($res, $offen[$key]['cat'] ?? '');
            // Auch Misserfolge merken, sonst fragen wir bei jedem Aufruf erneut.
            $this->cache->set($key, $info ?? '');
            $treffer[$key] = $info;
        }

        // --- 4. Zurückschreiben -------------------------------------------
        foreach ($treffer as $key => $info) {
            if ($info === null) {
                continue;
            }
            foreach ($stellen[$key] ?? [] as [$ji, $li]) {
                $journeys[$ji]['legs'][$li]['series']     = $info['series'];
                $journeys[$ji]['legs'][$li]['seriesName'] = $info['seriesName'];
                if ($info['coaches'] !== null) {
                    $journeys[$ji]['legs'][$li]['coaches'] = $info['coaches'];
                }
            }
        }

        return $journeys;
    }

    private static function key(string $eva, string $num, string $cat, string $dep): string
    {
        return 'cs:' . $eva . ':' . $cat . ':' . $num . ':' . substr($dep, 0, 16);
    }

    /**
     * Die Abfrage-URL für einen Zug — oder null, wenn sich keine bauen lässt.
     *
     * tRPC/superjson: der Parameter `input` ist ein JSON-STRING, der ein
     * Array enthält — nicht das Array selbst. Ohne die zweite Kodierung
     * antwortet der Dienst mit `"[object Object]" is not valid JSON`.
     */
    private function url(string $eva, string $number, string $category, string $departureIso): ?string
    {
        try {
            $dep = new DateTimeImmutable($departureIso);
        } catch (Exception $e) {
            return null;
        }

        if ($this->source === 'bahnde') {
            return rtrim((string) ($this->cfg['endpoint'] ?? ''), '/') . '?' . http_build_query([
                'administrationId' => '80',
                'category'         => $category,
                // Der Tag des Zuglaufs in Ortszeit, die Uhrzeit in UTC.
                'date'             => $dep->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d'),
                'evaNumber'        => $eva,
                'number'           => $number,
                'time'             => $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'),
            ]);
        }

        // Der Dienst erwartet UTC-Zeitstempel im JavaScript-Format.
        $planned = $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        // Der Abfahrtstag des Zuglaufs; Mitternacht des Reisetags genügt.
        $initial = $dep->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\T00:00:00.000\Z');

        // Erst eine Feldkarte, dann die Werte in Indexreihenfolge.
        $payload = [
            [
                'evaNumber'        => 1,
                'plannedDeparture' => 2,
                'initialDeparture' => 3,
                'journeyNumber'    => 4,
                'category'         => 5,
                'administration'   => 6,
            ],
            $eva,
            ['Date', $planned],
            ['Date', $initial],
            (int) $number,
            $category,
            '80',
        ];

        $inner = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $outer = json_encode($inner === false ? '[]' : $inner, JSON_UNESCAPED_SLASHES);

        return rtrim((string) $this->cfg['endpoint'], '/')
            . '/coachSequence.departureSequence?input='
            . rawurlencode((string) $outer);
    }

    /**
     * Eine Antwort auswerten.
     *
     * @param array{ok:bool,status:int,body:string,error:?string,json:?array} $res
     * @return array{series:string,seriesName:string,coaches:?array}|null
     */
    private function parse(array $res, string $category = ''): ?array
    {
        if (!$res['ok'] || $res['json'] === null) {
            return null;
        }

        if ($this->source === 'bahnde') {
            $br = self::seriesFromVehicles($res['json'], $category);
            if ($br === null) {
                return null;
            }
            $first = 0;
            $second = 0;
            $total = 0;
            foreach (($res['json']['groups'] ?? []) as $g) {
                foreach (($g['vehicles'] ?? []) as $v) {
                    $kat = (string) ($v['type']['category'] ?? '');
                    if (str_contains($kat, 'LOCOMOTIVE') || str_contains($kat, 'POWERCAR')) {
                        continue;
                    }
                    $total++;
                    if (!empty($v['type']['hasFirstClass'])) {
                        $first++;
                    }
                    if (!empty($v['type']['hasEconomyClass'])) {
                        $second++;
                    }
                }
            }
            return $br + ['coaches' => ['total' => $total, 'first' => $first, 'second' => $second, 'occupancy' => null]];
        }

        $data = $res['json']['result']['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data) || $data === []) {
            return null;
        }

        return $this->extract($data);
    }

    /**
     * Liest Baureihe und Wagen aus der superjson-Antwort.
     *
     * Das Format ist referenzbasiert: Element 0 ist die Wurzel, jeder Wert
     * darin ist ein Index in dasselbe Array. -1 steht für "nicht vorhanden".
     * Statt das Format allgemein aufzulösen, navigieren wir gezielt - das
     * ist kürzer und bricht nicht, wenn anderswo etwas unbekannt ist.
     */
    private function extract(array $arr): ?array
    {
        $at = static function ($idx) use ($arr) {
            return is_int($idx) && $idx >= 0 && $idx < count($arr) ? $arr[$idx] : null;
        };

        $root = $arr[0] ?? null;
        if (!is_array($root)) {
            return null;
        }

        $sequence = $at($root['sequence'] ?? -1);
        if (!is_array($sequence)) {
            return null;
        }

        $groupIdx = $at($sequence['groups'] ?? -1);
        if (!is_array($groupIdx) || $groupIdx === []) {
            return null;
        }

        $group = $at($groupIdx[0]);
        if (!is_array($group)) {
            return null;
        }

        $br = $at($group['baureihe'] ?? -1);
        if (!is_array($br)) {
            return null;
        }

        $series     = (string) ($at($br['identifier'] ?? -1) ?? '');
        $seriesName = (string) ($at($br['name'] ?? -1) ?? '');
        if ($series === '' && $seriesName === '') {
            return null;
        }

        // Wagen: Klasse und Auslastung, soweit vorhanden.
        $coaches   = null;
        $coachIdx  = $at($group['coaches'] ?? -1);
        if (is_array($coachIdx)) {
            $first = 0;
            $second = 0;
            $occupancy = null;
            foreach ($coachIdx as $ci) {
                $c = $at($ci);
                if (!is_array($c)) {
                    continue;
                }
                $cls = $at($c['class'] ?? -1);
                if ($cls === 1) {
                    $first++;
                } elseif ($cls === 2) {
                    $second++;
                }
                $occ = $at($c['occupancy'] ?? -1);
                if (is_int($occ)) {
                    $occupancy = max($occupancy ?? 0, $occ);
                }
            }
            $coaches = [
                'total'     => count($coachIdx),
                'first'     => $first,
                'second'    => $second,
                'occupancy' => $occupancy,
            ];
        }

        return [
            'series'     => $series,
            'seriesName' => $seriesName !== '' ? $seriesName : ('BR ' . $series),
            'coaches'    => $coaches,
        ];
    }

    private function isToday(string $date): bool
    {
        try {
            $today = new DateTimeImmutable('today');
            $d     = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return false;
        }
        return $d->format('Y-m-d') === $today->format('Y-m-d');
    }
}
