<?php
/**
 * Große Baustellen in Deutschland (DB InfraGO, strecken.info).
 *
 * WOZU: Die ÖBB-Quelle in OebbHafas::works() ist österreichlastig -
 * nachgemessen über 500 Meldungen: 452 mit österreichischem, 17 mit
 * deutschem Anfangsbahnhof, und nach Kategorie- und Dauerfilter bleibt aus
 * Deutschland praktisch nichts übrig. Für eine Übersicht "wo wird im Netz
 * gerade groß gebaut" ist das die falsche Hälfte des Bildes.
 *
 * DIESE QUELLE ist das Verzeichnis der DB InfraGO, das hinter strecken.info
 * (seit dem Umzug: strecken-info.de) steckt. Sie liefert für ganz
 * Deutschland: betroffener Abschnitt mit Namen und Koordinaten, Zeitraum,
 * Art der Arbeiten, Streckennummer und Schwere der Einschränkung.
 *
 * WAS DAVON GEZEIGT WIRD: nur TOTALSPERRUNGEN, und davon nur die, die
 * mindestens eine Woche dauern. Alles andere ist Betriebsalltag - allein an
 * nächtlichen Sperrpausen liefert die Quelle über viertausend Einträge in
 * dreißig Tagen.
 *
 * ZWEI EIGENHEITEN, die beim Anbinden Arbeit gemacht haben:
 *
 *   REVISION. Jede Anfrage muss den Stand nennen, auf den sie sich bezieht.
 *   Die Weboberfläche bekommt ihn beim Start mitgeliefert; einen Endpunkt,
 *   der ihn allein zurückgibt, gibt es nicht. Die Zahl wächst monoton, und
 *   der Server nimmt ein Fenster von einigen hundert Ständen an. Deshalb
 *   wird der zuletzt gültige Stand gemerkt und bei Bedarf gesucht - siehe
 *   findRevision().
 *
 *   KOORDINATEN in EPSG:3857 (Web-Mercator-Metern), nicht in Grad.
 *
 * FAIRER UMGANG: Die Antwort umfasst je nach Zeitraum mehrere Megabyte.
 * Deshalb wird stündlich gecacht (wie die übrigen Baustellendaten), der
 * Zeitraum eng gehalten und beim Suchen des Standes mit einer minimalen
 * Abfrage gearbeitet.
 */
final class StreckenInfo
{
    private const ENDPOINT = 'https://strecken-info.de/api/baustellen';

    /** Alle Regionalbereiche der DB InfraGO. */
    private const REGIONEN = ['MITTE', 'NORD', 'OST', 'SUED', 'SUEDOST', 'SUEDWEST', 'WEST'];

    /**
     * Ausgangspunkt der Revisionssuche, falls nichts gemerkt ist.
     *
     * Stand August 2026. Veraltet der Wert, findet findRevision() den
     * aktuellen - er dient nur dazu, die Suche in der richtigen Gegend zu
     * beginnen.
     */
    private const REVISION_SEED = 3520724;

    /** Ab wie vielen Tagen eine Sperrung als große Baustelle gilt. */
    private const MIN_DAYS = 7;

    /** Ergebnis einer Revisionsprobe - siehe probeRevision(). */
    private const REV_OK      = 0;
    private const REV_TOO_OLD = 1;
    private const REV_TOO_NEW = 2;
    private const REV_ERROR   = 3;

    /** Mehr Einträge braucht keine Übersicht - und die Antwort bleibt klein. */
    private const MAX_WORKS = 60;

    private Http $http;
    private Cache $cache;
    private array $cfg;

    public function __construct(Http $http, Cache $cache, array $cfg = [])
    {
        $this->http  = $http;
        $this->cache = $cache;
        $this->cfg   = $cfg;
    }

    /**
     * Große Baustellen in Deutschland.
     *
     * Gibt dieselbe Struktur zurück wie OebbHafas::works(), damit beide
     * Quellen in einer Liste stehen können.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function works(int $days = 30): array
    {
        $rev = $this->findRevision();
        if ($rev === null) {
            return ['ok' => false, 'error' => 'strecken.info: kein gültiger Stand ermittelbar', 'data' => []];
        }

        $res = $this->request($rev, self::REGIONEN, max(1, $days) * 24, true);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => 'strecken.info: HTTP ' . $res['status'], 'data' => []];
        }

        return ['ok' => true, 'error' => null, 'data' => $this->normalise($res['json'])];
    }

    /**
     * Rohdaten zu Baustellen im Format der übrigen Quellen.
     *
     * ZUSAMMENFASSEN: Ein Bauvorhaben zerfällt in der Quelle in viele
     * Einzeleinträge - je Richtung, je Abschnitt, je Zeitfenster. Sie
     * teilen sich den Präfix der `baustellenID` vor dem Punkt: aus 66
     * Einträgen "1E79F.x" wird ein Vorhaben. Ohne diese Bündelung stünde
     * dieselbe Baustelle zwanzigmal in der Liste.
     *
     * @param array<int,array> $roh
     * @return array<int,array>
     */
    private function normalise(array $roh): array
    {
        $projekte = [];

        foreach ($roh as $b) {
            $von = strtotime((string) ($b['zeitraum']['beginn'] ?? ''));
            $bis = strtotime((string) ($b['zeitraum']['ende'] ?? ''));
            if ($von === false || $bis === false || $bis <= $von) {
                continue;
            }
            if (($bis - $von) < self::MIN_DAYS * 86400) {
                continue;
            }

            $id = explode('.', (string) ($b['baustellenID'] ?? ''))[0];
            if ($id === '') {
                continue;
            }

            $a = self::toLatLon($b['koordinaten']['von'] ?? null);
            $z = self::toLatLon($b['koordinaten']['bis'] ?? null);
            if ($a === null || $z === null) {
                continue;
            }

            // Innerhalb eines Vorhabens gewinnt der Eintrag mit dem LAENGSTEN
            // Abschnitt: er beschreibt, was tatsächlich gesperrt ist. Der
            // längste Zeitraum wird getrennt davon über alle Einträge
            // gebildet - die Teilsperrungen lösen einander ab.
            $laenge = self::metres($a, $z);
            $vorhanden = $projekte[$id] ?? null;

            if ($vorhanden === null) {
                $projekte[$id] = [
                    'id'       => 'si-' . $id,
                    'title'    => trim((string) ($b['arbeiten'] ?? 'Bauarbeiten')),
                    'text'     => self::beschreibung($b),
                    'from'     => ['name' => (string) ($b['langnameVon'] ?? ''), 'id' => (string) ($b['ril100Von'] ?? ''), 'lat' => $a[0], 'lon' => $a[1]],
                    'to'       => ['name' => (string) ($b['langnameBis'] ?? ''), 'id' => (string) ($b['ril100Bis'] ?? ''), 'lat' => $z[0], 'lon' => $z[1]],
                    'start'    => date('Y-m-d', $von),
                    'end'      => date('Y-m-d', $bis),
                    'country'  => 'de',
                    'geometry' => [],
                    'category' => 2,          // Bauarbeiten, wie bei HAFAS
                    'products' => [],
                    'lines'    => array_values(array_map('strval', $b['streckennummern'] ?? [])),
                    'longDistance' => false,  // wird in handleWorks bestimmt
                    '_laenge'  => $laenge,
                ];
                continue;
            }

            if ($von < strtotime($vorhanden['start'])) {
                $projekte[$id]['start'] = date('Y-m-d', $von);
            }
            if ($bis > strtotime($vorhanden['end'])) {
                $projekte[$id]['end'] = date('Y-m-d', $bis);
            }
            if ($laenge > $vorhanden['_laenge']) {
                $projekte[$id]['_laenge'] = $laenge;
                $projekte[$id]['from'] = ['name' => (string) ($b['langnameVon'] ?? ''), 'id' => (string) ($b['ril100Von'] ?? ''), 'lat' => $a[0], 'lon' => $a[1]];
                $projekte[$id]['to']   = ['name' => (string) ($b['langnameBis'] ?? ''), 'id' => (string) ($b['ril100Bis'] ?? ''), 'lat' => $z[0], 'lon' => $z[1]];
                $projekte[$id]['title'] = trim((string) ($b['arbeiten'] ?? 'Bauarbeiten'));
                $projekte[$id]['text']  = self::beschreibung($b);
            }
            foreach ($b['streckennummern'] ?? [] as $nr) {
                if (!in_array((string) $nr, $projekte[$id]['lines'], true)) {
                    $projekte[$id]['lines'][] = (string) $nr;
                }
            }
        }

        // Die längsten zuerst, dann kappen.
        usort($projekte, static function (array $x, array $y): int {
            $dx = strtotime($x['end']) - strtotime($x['start']);
            $dy = strtotime($y['end']) - strtotime($y['start']);
            return $dy <=> $dx;
        });
        $projekte = array_slice($projekte, 0, self::MAX_WORKS);

        foreach ($projekte as $i => $p) {
            unset($projekte[$i]['_laenge']);
        }
        return $projekte;
    }

    /**
     * Ein Satz, der sagt, was dort passiert.
     *
     * Die Art der Arbeiten behält ihre Schreibweise: "Totalsperrung -
     * Brückenarbeiten" sind zwei Substantive, und im Deutschen werden die
     * großgeschrieben. Früher stand hier ein lcfirst() - das stammt aus der
     * Zeit, als der Satz nur im Tooltip hing; im aufgeklappten Kasten der
     * Baustellenliste liest er sich als Satz, und dort fiel es auf.
     */
    private static function beschreibung(array $b): string
    {
        $art = trim((string) ($b['arbeiten'] ?? ''));
        $nr  = implode(', ', array_map('strval', $b['streckennummern'] ?? []));

        $teile = ['Totalsperrung'];
        if ($art !== '') {
            $teile[] = $art;
        }
        $satz = implode(' — ', $teile);
        return $nr === '' ? $satz . '.' : $satz . ' (Strecke ' . $nr . ').';
    }

    /**
     * EPSG:3857 (Web-Mercator-Meter) nach WGS84.
     *
     * @return ?array{0:float,1:float}
     */
    private static function toLatLon(?array $xy): ?array
    {
        if (!isset($xy['x'], $xy['y'])) {
            return null;
        }
        $x = (float) $xy['x'];
        $y = (float) $xy['y'];

        $lon = $x / 20037508.342789244 * 180.0;
        $lat = $y / 20037508.342789244 * 180.0;
        $lat = 180.0 / M_PI * (2.0 * atan(exp($lat * M_PI / 180.0)) - M_PI / 2.0);

        if (abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }
        return [round($lat, 6), round($lon, 6)];
    }

    /** Entfernung zweier [lat, lon]-Punkte in Metern. */
    private static function metres(array $a, array $b): float
    {
        $lat = ($a[0] + $b[0]) / 2 * M_PI / 180;
        $dx = ($b[1] - $a[1]) * 111320 * cos($lat);
        $dy = ($b[0] - $a[0]) * 110540;
        return sqrt($dx * $dx + $dy * $dy);
    }

    // ------------------------------------------------------------------
    // Revision
    // ------------------------------------------------------------------

    /**
     * Den aktuellen Datenstand ermitteln.
     *
     * Die Weboberfläche bekommt ihn beim Start mitgeliefert; ein Endpunkt,
     * der ihn allein liefert, existiert nicht. Der Server verrät aber bei
     * jeder Anfrage, in WELCHE RICHTUNG man suchen muss:
     *
     *   "Angefragte Revision 3520724 zu alt"        -> höher
     *   "Revision 3530000 existiert noch nicht"     -> tiefer
     *
     * Genau diese Unterscheidung fehlte anfangs, und deshalb fand die Suche
     * gar nichts: jeder Fehlschlag galt als "zu neu", also lief sie sofort
     * nach unten - während der gemerkte Stand in Wirklichkeit veraltet war
     * und es nach oben gegangen wäre. Die deutschen Baustellen blieben
     * dadurch stumm aus, sobald der hinterlegte Ausgangswert alt genug war.
     *
     * Der zuletzt gültige Stand wird gemerkt; im Normalfall bleibt es bei
     * einer einzigen kleinen Abfrage.
     */
    private function findRevision(): ?int
    {
        $key = 'streckeninfo:revision';
        $gemerkt = (int) ($this->cache->get($key, 86400) ?? 0);
        $start = $gemerkt > 0 ? $gemerkt : self::REVISION_SEED;

        $p = $this->probeRevision($start);
        if ($p === self::REV_OK) {
            return $start;
        }
        if ($p === self::REV_ERROR) {
            return null;   // Dienst antwortet nicht - nicht weitersuchen
        }

        // Ein Fenster finden, in dem der gültige Stand liegen muss.
        $unten = $start;
        $oben  = null;

        if ($p === self::REV_TOO_OLD) {
            // In Zweierschritten nach oben, bis der Server "existiert noch
            // nicht" sagt. Trifft ein Schritt das Fenster, sind wir fertig.
            for ($schritt = 256; $schritt <= 4194304; $schritt *= 2) {
                $probe = $start + $schritt;
                $q = $this->probeRevision($probe);
                if ($q === self::REV_OK) {
                    $this->cache->set($key, $probe);
                    return $probe;
                }
                if ($q === self::REV_TOO_NEW) {
                    $oben = $probe;
                    break;
                }
                if ($q !== self::REV_TOO_OLD) {
                    return null;
                }
                $unten = $probe;
            }
        } else {
            // Der gemerkte Stand liegt in der Zukunft - kommt vor, wenn die
            // Gegenseite ihre Zählung zurücksetzt.
            $oben  = $start;
            $unten = max(1, $start - 4194304);
        }

        if ($oben === null) {
            return null;
        }

        // Halbieren, bis eine Probe im Fenster landet. Das Fenster ist ein
        // paar hundert Stände breit, deshalb geht das schnell.
        for ($i = 0; $i < 24 && $oben - $unten > 1; $i++) {
            $mitte = intdiv($unten + $oben, 2);
            $q = $this->probeRevision($mitte);
            if ($q === self::REV_OK) {
                $this->cache->set($key, $mitte);
                return $mitte;
            }
            if ($q === self::REV_TOO_OLD) {
                $unten = $mitte;
            } elseif ($q === self::REV_TOO_NEW) {
                $oben = $mitte;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Nimmt der Server diesen Stand an - und wenn nicht, warum?
     *
     * Kleinstmögliche Anfrage: eine Region, eine Stunde, nur
     * Totalsperrungen. Die Antwort ist damit ein paar Kilobyte statt
     * mehrerer Megabyte.
     */
    private function probeRevision(int $rev): int
    {
        $res = $this->request($rev, ['MITTE'], 1, true);
        if ($res['ok'] && is_array($res['json'])) {
            return self::REV_OK;
        }

        $text = (string) (($res['json']['details'][0] ?? null)
            ?? ($res['json']['message'] ?? null)
            ?? $res['body']);

        // "Zu alt" und "existiert noch nicht" kommen als HTTP 400, sind aber
        // die erwartete Antwort auf eine Probe - der Dienst läuft. Für die
        // Statistik in check.php also kein Fehlschlag, siehe Health::retract().
        if (mb_stripos($text, 'zu alt') !== false) {
            Health::retract(self::ENDPOINT);
            return self::REV_TOO_OLD;
        }
        if (mb_stripos($text, 'existiert noch nicht') !== false) {
            Health::retract(self::ENDPOINT);
            return self::REV_TOO_NEW;
        }
        return self::REV_ERROR;
    }

    /**
     * @param string[] $regionen
     * @return array{ok:bool,status:int,body:string,error:?string,json:?array}
     */
    private function request(int $rev, array $regionen, int $stunden, bool $nurTotalsperrung): array
    {
        $payload = [
            'revision' => $rev,
            'filter'   => [
                'baustellenAktiv'            => true,
                'baustellenNurTotalsperrung' => $nurTotalsperrung,
                'streckenruhenAktiv'         => false,
                'stoerungenAktiv'            => false,
                'wirkungsdauer'              => 0,
                'zeitraum'                   => ['type' => 'ROLLIEREND', 'stunden' => $stunden],
                'regionalbereiche'           => array_values($regionen),
                'streckennummern'            => [],
                'betriebsstellen'            => [],
            ],
        ];

        return $this->http->postJson(self::ENDPOINT, $payload, [
            'User-Agent' => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
        ]);
    }
}
