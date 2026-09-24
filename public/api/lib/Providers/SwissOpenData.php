<?php
/**
 * Schweizer Echtzeit über transport.opendata.ch - ohne Schlüssel.
 *
 * WOZU: Die Live-Verfolgung holt die Echtzeit über den Zuglauf bei HAFAS
 * (ÖBB). Kennt HAFAS für einen Schweizer Zug nur den Fahrplan, fragt die
 * App hier nach - bei der Schnittstelle von opendata.ch, die die Prognosen
 * der SBB weiterreicht. Kein Schlüssel, keine Registrierung; anders als
 * opentransportdata.swiss, das dieselben Daten nur mit Zugangsschlüssel
 * herausgibt.
 *
 * WIE: Es gibt dort keinen Zuglauf, aber die Abfahrts- und Ankunftstafel
 * jedes Bahnhofs mit Prognose. Der eigene Zug findet sich am Einstieg über
 * Gattung und Planminute, am Ausstieg ebenso auf der Ankunftstafel. Die
 * Zugnummer taugt nicht als Schlüssel: die Tafel nennt bei Fernzügen oft die
 * LINIE ("IR 37"), bei S-Bahnen eine Betriebsnummer.
 *
 * FAIRER UMGANG: ein Gemeinschaftsprojekt. Gefragt wird nur, wenn HAFAS
 * nichts hat, und jede Antwort gilt dreißig Sekunden (siehe index.php).
 */
final class SwissOpenData
{
    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Echtzeit eines Schweizer Abschnitts, im Zuglauf-Format der App.
     *
     * @param array{from:string,to:string,cat:string,dir:string,dep:string,arr:string} $leg
     * @return array{ok:bool,error:?string,data:array}
     */
    public function trip(array $leg): array
    {
        $tDep = strtotime($leg['dep']);
        $tArr = strtotime($leg['arr']);
        if ($tDep === false || $tArr === false) {
            return ['ok' => false, 'error' => 'Zeitangabe ungültig', 'data' => []];
        }
        $base = rtrim((string) ($this->cfg['endpoint'] ?? 'https://transport.opendata.ch/v1'), '/');
        $zeit = static fn(int $t): string => (new DateTimeImmutable('@' . ($t - 120)))
            ->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d H:i');
        $urls = [
            'ein' => $base . '/stationboard?' . http_build_query([
                'id' => $leg['from'], 'datetime' => $zeit($tDep), 'type' => 'departure', 'limit' => 30,
            ]),
            'aus' => $base . '/stationboard?' . http_build_query([
                'id' => $leg['to'], 'datetime' => $zeit($tArr), 'type' => 'arrival', 'limit' => 30,
            ]),
        ];
        $res = $this->http->getJsonAll($urls, ['User-Agent' => 'train-maxxing (Fahrplanwerkzeug)']);

        $ein = self::find($res['ein']['json']['stationboard'] ?? [], 'departure', $tDep, $leg['cat'], $leg['dir']);
        $aus = self::find($res['aus']['json']['stationboard'] ?? [], 'arrival', $tArr, $leg['cat'], '');

        $depReal = self::prognosis($ein, 'departure');
        $arrReal = self::prognosis($aus, 'arrival');
        $delay = null;
        if ($depReal !== null) {
            $delay = (int) round((strtotime($depReal) - $tDep) / 60);
        } elseif ($arrReal !== null) {
            $delay = (int) round((strtotime($arrReal) - $tArr) / 60);
        }

        return ['ok' => true, 'error' => null, 'data' => [
            'hasRealtime' => $depReal !== null || $arrReal !== null,
            'delay'       => $delay,
            'departureReal' => $depReal,
            'arrivalReal' => $arrReal,
            'platformFrom' => self::platform($ein),
            'platformTo'  => self::platform($aus),
            'cancelled'   => false,
            'source'      => 'opendata.ch',
        ]];
    }

    /**
     * Den eigenen Zug auf einer Tafel finden: gleiche Gattung, gleiche
     * Planminute, und wo bekannt dieselbe Richtung.
     */
    public static function find(array $board, string $kind, int $plan, string $cat, string $dir): ?array
    {
        $cat = strtoupper(trim($cat));
        $dir = mb_strtolower(trim((string) preg_replace('/\s*\(.*$/', '', $dir)));
        $bester = null;
        foreach ($board as $e) {
            $t = strtotime((string) ($e['stop'][$kind] ?? ''));
            if ($t === false || abs($t - $plan) > 60) {
                continue;
            }
            if ($cat !== '' && strtoupper((string) ($e['category'] ?? '')) !== $cat) {
                continue;
            }
            // Bei zwei Kandidaten entscheidet die Richtung.
            $ziel = mb_strtolower((string) ($e['to'] ?? ''));
            if ($dir !== '' && $ziel !== '' && !str_contains($ziel, $dir) && !str_contains($dir, $ziel)) {
                $bester ??= $e;
                continue;
            }
            return $e;
        }
        return $bester;
    }

    private static function prognosis(?array $e, string $kind): ?string
    {
        $p = $e['stop']['prognosis'][$kind] ?? null;
        if (!is_string($p) || $p === '') {
            return null;
        }
        $t = strtotime($p);
        return $t === false ? null : date('c', $t);
    }

    private static function platform(?array $e): ?string
    {
        $p = $e['stop']['prognosis']['platform'] ?? $e['stop']['platform'] ?? null;
        return is_string($p) && $p !== '' ? $p : null;
    }
}
