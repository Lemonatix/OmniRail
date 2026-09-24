<?php
/**
 * MVG (Münchner Verkehrsgesellschaft) - öffentliche Web-API.
 *
 * WOZU BRAUCHT MAN DAS?
 *
 * HAFAS (ÖBB, DB) findet Fernbahnhöfe und S-/U-Bahn-Knoten, verpasst aber
 * regelmäßig die reinen Münchner Nahverkehrshalte. "Odeonsplatz" oder
 * "Sendlinger Tor" haben keine EVA-Nummer, weil dort kein Fernverkehr hält,
 * und tauchen daher in der HAFAS-Suche oft gar nicht auf. Für eine Karte,
 * die den Münchner Nahverkehr sichtbar machen soll, ist das eine Lücke.
 *
 * Deshalb fragen wir MVG zusätzlich ab. Die API läuft ohne Auth und
 * liefert die vollständige DIVA-Datenbank aller Münchner Halte inklusive
 * Koordinaten und Verkehrsmittel. Zusätzlich holen wir Störungsmeldungen
 * ab: die App zeigt sie als kompakten Live-Ticker für die Region.
 *
 * VERBINDUNGEN INNERHALB DES MVV: Hier stand lange, die API habe keine
 * Verbindungssuche - gesucht wurde nach /trips. Sie heisst /routes, und sie
 * ist fuer die App wertvoller als jede andere Quelle in Muenchen: die
 * Fahrplanquelle der OeBB kennt die Muenchner U-Bahn schlicht nicht
 * (Odeonsplatz fuehrt dort nur Produktklasse 2, Marienplatz nur die S-Bahn).
 * Faellt die S-Bahn-Stammstrecke aus, kann HAFAS deshalb keine U-Bahn als
 * Ersatz anbieten - die MVG kann es. Siehe routes() und nearestStation().
 *
 * WAS DIESE API NICHT KANN:
 *
 *   - Verbindungen ueber den MVV hinaus. Fuer Fernverbindungen bleibt HAFAS
 *     zustaendig; die MVG liefert nur das Stueck innerhalb Muenchens.
 *   - EVA-Nummern - die IDs (globalId "de:09162:2") sind DELFI-Kennungen,
 *     nicht EVA. HAFAS akzeptiert sie nicht. Ein HAFAS-Bahnhof wird deshalb
 *     ueber seine Koordinaten auf die MVG-Haltestelle abgebildet.
 *
 * Für alles, was HAFAS ohnehin findet, gewinnt HAFAS im Merge - MVG
 * ergänzt dann nur fehlende Produkte (UBAHN/TRAM) und Koordinaten.
 */
final class Mvg
{
    /**
     * MVG-Transporttypen auf die HAFAS-/DB-Produktnamen abbilden, mit denen
     * Locations.php rechnet. So wird "München, Marienplatz" von allen drei
     * Quellen mit denselben Maßstäben bewertet.
     *
     * BAHN = Fernverkehr (für den bringt MVG nichts Neues, aber vollständige
     * Zuordnung schadet nicht), REGIONAL_BUS = Landkreis-Busse.
     */
    private const TRANSPORT_TO_PRODUCT = [
        'UBAHN'        => 'UBAHN',
        'SBAHN'        => 'SBAHN',
        'TRAM'         => 'TRAM',
        'BUS'          => 'BUS',
        'REGIONAL_BUS' => 'BUS',
        'BAHN'         => 'EC_IC',      // Fern-/Nachtzug
        'RUFTAXI'      => 'ANRUFPFLICHTIG',
        'SCHIFF'       => 'SCHIFF',
    ];

    /** ICE / EC / IC — für diese Kennzeichnung greift "Fernverkehrshalt". */
    private const LONG_DISTANCE_PRODUCTS = ['EC_IC'];

    /**
     * MVG-Transporttypen als Gattung eines Abschnitts - in der Schreibweise,
     * die trains.js (typeOf) kennt. Fussweg ist keine Gattung, sondern ein
     * eigener Abschnittstyp.
     */
    private const TRANSPORT_TO_CATEGORY = [
        'UBAHN'        => 'U',
        'SBAHN'        => 'S',
        'TRAM'         => 'Tram',
        'BUS'          => 'Bus',
        'REGIONAL_BUS' => 'Bus',
        'RUFTAXI'      => 'Bus',
        'BAHN'         => 'RB',
        'SCHIFF'       => 'Schiff',
    ];

    /**
     * Wie weit ein HAFAS-Bahnhof von der MVG-Haltestelle entfernt sein darf,
     * um als dieselbe zu gelten. München Hbf und "Hauptbahnhof (U, Tram)"
     * liegen hundert Meter auseinander und sind trotzdem ein Umstieg;
     * vierhundert Meter fassen das, ohne die Nachbarhaltestelle zu greifen.
     */
    private const SAME_STATION_M = 400.0;


    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Ortssuche in München und Umgebung.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function locations(string $query, int $limit = 8): array
    {
        $url = rtrim((string) ($this->cfg['endpoint'] ?? ''), '/')
            . '/locations?query=' . rawurlencode($query);

        $res = $this->http->getJson($url, ['User-Agent' => $this->userAgent()]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'MVG: HTTP ' . $res['status'] . ($res['error'] ? ' (' . $res['error'] . ')' : ''),
                'data'  => [],
            ];
        }

        $out = [];
        foreach ($res['json'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Adressen und POIs helfen bei einer Zugsuche nicht.
            if (($item['type'] ?? '') !== 'STATION') {
                continue;
            }

            $globalId = (string) ($item['globalId'] ?? '');
            if ($globalId === '') {
                continue;
            }

            $transports = array_values(array_filter(array_map(
                'strval',
                (array) ($item['transportTypes'] ?? [])
            )));
            $products = $this->productsFromTransportTypes($transports);
            if ($products === []) {
                continue; // Halt ohne bekanntes Verkehrsmittel: überspringen
            }

            $out[] = [
                // Eigener Prefix, damit MVG-IDs mit HAFAS-IDs nicht kollidieren
                // können; Locations.php erkennt daran auch noJourneys=true.
                'id'           => 'mvg:' . $globalId,
                'name'         => (string) ($item['name'] ?? ''),
                'place'        => (string) ($item['place'] ?? ''),
                'country'      => 'de',
                'lat'          => isset($item['latitude']) ? (float) $item['latitude'] : null,
                'lon'          => isset($item['longitude']) ? (float) $item['longitude'] : null,
                'products'     => $products,
                'longDistance' => (bool) array_intersect($products, self::LONG_DISTANCE_PRODUCTS),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Die MVG-Haltestelle an einem Punkt - oder null, wenn dort keine ist.
     *
     * So wird aus einem HAFAS-Bahnhof ("München Ostbahnhof", EVA 8000262)
     * die MVG-Kennung, mit der sich eine Verbindung suchen laesst. Liegt die
     * naechste Haltestelle weiter als SAME_STATION_M entfernt, ist der Punkt
     * ausserhalb des MVG-Netzes, und null ist die ehrliche Antwort.
     *
     * @return ?array{globalId:string,name:string,lat:float,lon:float,distance:float}
     */
    public function nearestStation(float $lat, float $lon): ?array
    {
        $url = rtrim((string) ($this->cfg['endpoint'] ?? ''), '/')
            . sprintf('/stations/nearby?latitude=%.6f&longitude=%.6f', $lat, $lon);

        $res = $this->http->getJson($url, ['User-Agent' => $this->userAgent()]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return null;
        }

        // BAHNHALT VOR BUSHALT. Die naechstgelegene Haltestelle ist oft
        // eine Bushaltestelle vor dem Bahnhof - am Ostbahnhof "Friedenstrasse"
        // (106 m), am Hauptbahnhof "Hauptbahnhof Nord" (45 m). Von dort plante
        // die MVG erst acht Minuten Fussweg zur S-Bahn und am Ende drei
        // zurueck zur Tram, und jede Ersatzverbindung verpasste dadurch den
        // Anschlusszug. Ersetzt wird hier ein ZUG, also zaehlt der Bahnhalt;
        // nur wenn im Umkreis keiner liegt, nimmt man, was da ist.
        //
        // Und S-/U-Bahn vor blossem "BAHN": die Bushaltestelle Friedenstrasse
        // am Ostbahnhof fuehrt die MVG als BAHN,BUS - wohl weil ein Regionalzug
        // in der Naehe haelt -, der Bahnhof selbst als SBAHN,UBAHN,TRAM,BUS.
        // Nach Entfernung allein gewann die Bushaltestelle.
        $besterRang = PHP_INT_MAX;
        $bester = null;
        foreach ($res['json'] as $st) {
            $dist = (float) ($st['distanceInMeters'] ?? INF);
            $id   = (string) ($st['globalId'] ?? '');
            if ($id === '' || $dist > self::SAME_STATION_M) {
                continue;
            }
            $typen = array_map('strval', (array) ($st['transportTypes'] ?? []));
            $rang = match (true) {
                array_intersect($typen, ['SBAHN', 'UBAHN']) !== [] => 0,
                in_array('BAHN', $typen, true)                    => 1,
                default                                           => 2,
            };
            // Die Liste kommt nach Entfernung sortiert; bei gleichem Rang
            // bleibt also die naehere.
            if ($rang < $besterRang) {
                $besterRang = $rang;
                $bester = [
                    'globalId' => $id,
                    'name'     => (string) ($st['name'] ?? ''),
                    'lat'      => (float) ($st['latitude'] ?? $lat),
                    'lon'      => (float) ($st['longitude'] ?? $lon),
                    'distance' => $dist,
                ];
            }
        }
        return $bester;
    }

    /**
     * Alle MVG-Haltestellen eines Bahnhofs.
     *
     * Ein großer Bahnhof ist bei der MVG kein Halt, sondern mehrere: am
     * Hauptbahnhof "München Hbf" (S-Bahn, 93 m), "Hauptbahnhof (U, Tram)"
     * (203 m), "Hauptbahnhof Süd" und "Nord" (Tram, Bus). Für eine
     * Abfahrtstafel gehören alle dazu - wer am Hauptbahnhof steht, will
     * auch die U-Bahn sehen. Für eine Verbindungssuche reicht dagegen einer,
     * siehe nearestStation().
     *
     * @return string[] globalIds, nächste zuerst
     */
    public function stationsAround(float $lat, float $lon, float $radius = 250.0, int $max = 4): array
    {
        $url = rtrim((string) ($this->cfg['endpoint'] ?? ''), '/')
            . sprintf('/stations/nearby?latitude=%.6f&longitude=%.6f', $lat, $lon);
        $res = $this->http->getJson($url, ['User-Agent' => $this->userAgent()]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return [];
        }
        $out = [];
        foreach ($res['json'] as $st) {
            $id = (string) ($st['globalId'] ?? '');
            if ($id !== '' && (float) ($st['distanceInMeters'] ?? INF) <= $radius) {
                $out[] = $id;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /**
     * Abfahrten mehrerer Haltestellen, gleichzeitig abgefragt.
     *
     * @param string[] $globalIds
     * @return array{ok:bool,error:?string,data:array}
     */
    public function departuresMany(array $globalIds, int $offsetMin = 0, int $limit = 40): array
    {
        $urls = [];
        foreach ($globalIds as $gid) {
            $urls[$gid] = $this->departuresUrl($gid, $offsetMin, $limit);
        }
        $data = [];
        $fehler = null;
        foreach ($this->http->getJsonAll($urls, ['User-Agent' => $this->userAgent()]) as $res) {
            $r = $this->mapDepartures($res);
            if ($r['ok']) {
                array_push($data, ...$r['data']);
            } else {
                $fehler ??= $r['error'];
            }
        }
        return $data === [] && $fehler !== null
            ? ['ok' => false, 'error' => $fehler, 'data' => []]
            : ['ok' => true, 'error' => null, 'data' => $data];
    }

    /**
     * Echtzeit für einen MVG-Abschnitt, im Zuglauf-Format der App.
     *
     * Die MVG hat keinen Endpunkt für einen einzelnen Zuglauf. Sie hat aber
     * die Abfahrtstafel, und die kennt jede Fahrt mit Plan- und Ist-Zeit:
     * am Einstieg findet sich die eigene U-Bahn über Linie und Planminute,
     * am Ausstieg über dieselbe Fahrtnummer (`tripCode`) - ihre Abfahrt
     * dort ist praktisch die Ankunft. Liegt der Ausstieg an einer Endstation,
     * fährt die Bahn dort nicht weiter ab; dann gilt die Verspätung vom
     * Einstieg.
     *
     * @param array{from:string,to:string,line:string,dep:string,arr:string} $leg
     * @return array{ok:bool,error:?string,data:array}
     */
    public function trip(array $leg): array
    {
        $now = time();
        $tDep = strtotime($leg['dep']);
        $tArr = strtotime($leg['arr']);
        if ($tDep === false || $tArr === false) {
            return ['ok' => false, 'error' => 'Zeitangabe ungültig', 'data' => []];
        }
        // Zwei Minuten Luft vor der Planzeit: die Tafel zeigt ab "jetzt plus
        // Versatz", und eine Bahn, die früher kommt, soll trotzdem drin sein.
        $versatz = static fn(int $t): int => max(0, (int) floor(($t - $now) / 60) - 2);
        $urls = [
            'from' => $this->departuresUrl($leg['from'], $versatz($tDep), 30),
            'to'   => $this->departuresUrl($leg['to'], $versatz($tArr), 30),
        ];
        $res = $this->http->getJsonAll($urls, ['User-Agent' => $this->userAgent()]);
        $ein = $this->mapDepartures($res['from'] ?? ['ok' => false, 'status' => 0, 'error' => null, 'json' => null]);
        $aus = $this->mapDepartures($res['to'] ?? ['ok' => false, 'status' => 0, 'error' => null, 'json' => null]);

        $norm = static fn(string $l): string => mb_strtolower(str_replace(' ', '', $l));
        $linie = $norm($leg['line']);
        $finde = static function (array $liste, int $plan, ?string $trip) use ($norm, $linie): ?array {
            foreach ($liste as $d) {
                if ($norm((string) $d['line']) !== $linie) {
                    continue;
                }
                if ($trip !== null && $trip !== '' && ($d['tripCode'] ?? '') !== '' && (string) $d['tripCode'] !== $trip) {
                    continue;
                }
                $t = strtotime((string) $d['planned']);
                if ($t !== false && abs($t - $plan) <= 90) {
                    return $d;
                }
            }
            return null;
        };

        $a = $ein['ok'] ? $finde($ein['data'], $tDep, null) : null;
        $b = $aus['ok'] ? $finde($aus['data'], $tArr, isset($a['tripCode']) ? (string) $a['tripCode'] : null) : null;

        if ($a === null && $b === null) {
            // Nichts gefunden ist kein Fehler: vor Mitternacht fährt die
            // Bahn von morgen früh noch nicht auf der Tafel.
            return ['ok' => true, 'error' => null, 'data' => [
                'hasRealtime' => false, 'delay' => null, 'cancelled' => false, 'stops' => [], 'messages' => [],
                'source' => 'mvg',
            ]];
        }

        $depReal = $a['real'] ?? null;
        $depDelay = $a['delay'] ?? null;
        // Ankunft: Abfahrt am Ausstieg, sonst Plan plus Verspätung vom Einstieg.
        $arrReal = $b['real'] ?? null;
        if ($arrReal === null && $depDelay !== null) {
            $arrReal = date('c', $tArr + $depDelay * 60);
        }
        $arrDelay = $b['delay'] ?? $depDelay;

        $infos = array_values(array_unique(array_merge($a['remarks'] ?? [], $b['remarks'] ?? [])));
        return ['ok' => true, 'error' => null, 'data' => [
            'category'    => (string) ($a['category'] ?? $b['category'] ?? ''),
            'line'        => $leg['line'],
            'trainNumber' => '',
            'name'        => $leg['line'],
            'direction'   => (string) ($a['direction'] ?? $b['direction'] ?? ''),
            'delay'       => $depDelay ?? $arrDelay,
            'hasRealtime' => $depReal !== null || ($b['real'] ?? null) !== null,
            'cancelled'   => (bool) (($a['cancelled'] ?? false) || ($b['cancelled'] ?? false)),
            'stops'       => [
                [
                    'id' => 'mvg:' . $leg['from'], 'name' => $leg['fromName'] ?? '',
                    'departure' => $leg['dep'], 'departureReal' => $depReal, 'delay' => $depDelay,
                    'platform' => $a['platform'] ?? null, 'cancelled' => (bool) ($a['cancelled'] ?? false),
                ],
                [
                    'id' => 'mvg:' . $leg['to'], 'name' => $leg['toName'] ?? '',
                    'arrival' => $leg['arr'], 'arrivalReal' => $arrReal, 'delay' => $arrDelay,
                    'platform' => $b['platform'] ?? null, 'cancelled' => (bool) ($b['cancelled'] ?? false),
                ],
            ],
            'messages'    => array_map(static fn($t) => ['text' => $t, 'from' => null, 'to' => null], array_slice($infos, 0, 3)),
            'source'      => 'mvg',
        ]];
    }

    /**
     * Verbindungen innerhalb des MVV, im selben Format wie die HAFAS-Treffer.
     *
     * Abschnitte, die AUSFALLEN, werfen die ganze Verbindung raus: gefragt
     * ist hier gerade der Weg um einen Ausfall herum, und eine Alternative,
     * die selbst ausfaellt, ist keine.
     *
     * @param string $isoUtc Abfahrt frühestens, als UTC ("2026-09-23T21:36:00.000Z")
     * @return array{ok:bool,error:?string,data:array}
     */
    public function routes(
        string $fromGlobalId,
        string $toGlobalId,
        string $isoUtc,
        int $limit = 4,
        bool $isArrival = false
    ): array {
        $res = $this->http->getJson(
            $this->routesUrl($fromGlobalId, $toGlobalId, $isoUtc, $isArrival),
            ['User-Agent' => $this->userAgent()]
        );
        return $this->mapRoutesResponse($res, $limit);
    }

    /**
     * Mehrere Verbindungssuchen auf einmal, gleichzeitig.
     *
     * Für den Zubringer zum Fernzug: je Fernverbindung eine eigene Frage
     * ("wann muss ich am Odeonsplatz los, um den ICE um 9:01 zu kriegen?").
     * Nacheinander wären das sechs Round-Trips, parallel kostet es einen.
     *
     * @param array<string|int,array{0:string,1:string,2:string,3:bool}> $queries
     *        Schlüssel => [von, nach, Zeitpunkt UTC, ist Ankunftszeit]
     * @return array<string|int,array{ok:bool,error:?string,data:array}>
     */
    public function routesMany(array $queries, int $limit = 4): array
    {
        $urls = [];
        foreach ($queries as $k => [$from, $to, $iso, $isArrival]) {
            $urls[$k] = $this->routesUrl($from, $to, $iso, $isArrival);
        }
        $out = [];
        foreach ($this->http->getJsonAll($urls, ['User-Agent' => $this->userAgent()]) as $k => $res) {
            $out[$k] = $this->mapRoutesResponse($res, $limit);
        }
        return $out;
    }

    private function routesUrl(string $from, string $to, string $isoUtc, bool $isArrival): string
    {
        return rtrim((string) ($this->cfg['endpoint'] ?? ''), '/') . '/routes?' . http_build_query([
            'originStationGlobalId'      => $from,
            'destinationStationGlobalId' => $to,
            'routingDateTime'            => $isoUtc,
            'routingDateTimeIsArrival'   => $isArrival ? 'true' : 'false',
            'transportTypes'             => 'SCHIFF,RUFTAXI,BAHN,UBAHN,TRAM,SBAHN,BUS,REGIONAL_BUS',
        ]);
    }

    /** @return array{ok:bool,error:?string,data:array} */
    private function mapRoutesResponse(array $res, int $limit): array
    {
        if (!$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'MVG: HTTP ' . $res['status'] . ($res['error'] ? ' (' . $res['error'] . ')' : ''),
                'data'  => [],
            ];
        }

        $out = [];
        foreach ($res['json'] as $route) {
            $j = $this->mapRoute(is_array($route) ? $route : []);
            if ($j === null) {
                continue;
            }
            $out[] = $j;
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Die MVG-Kennung eines Ortes der App - oder null, wenn er nicht im
     * MVG-Netz liegt.
     *
     * Ein Halt aus der MVG-Ortssuche trägt sie schon ("mvg:de:09162:2").
     * Ein HAFAS-Bahnhof wird über seine Koordinaten abgebildet, aber nur,
     * wenn er überhaupt im Raum München liegt - sonst kostete jede Suche
     * Zürich-Wien eine sinnlose MVG-Abfrage.
     */
    public function stationFor(string $id, ?float $lat, ?float $lon): ?string
    {
        if (str_starts_with($id, 'mvg:')) {
            return substr($id, 4);
        }
        if ($lat === null || $lon === null || !self::inArea($lat, $lon)) {
            return null;
        }
        return $this->nearestStation($lat, $lon)['globalId'] ?? null;
    }

    /**
     * Liegt ein Punkt im MVV-Gebiet? Großzügig gerechnet: vom Ammersee bis
     * Erding, von Freising bis Wolfratshausen. Genau entscheidet danach
     * nearestStation() - das hier spart nur die Abfrage für alles, was
     * offensichtlich woanders liegt.
     */
    public static function inArea(float $lat, float $lon): bool
    {
        return $lat >= 47.85 && $lat <= 48.45 && $lon >= 11.10 && $lon <= 12.10;
    }

    /**
     * Eine MVG-Route als Verbindung im App-Format - oder null, wenn sie
     * unbrauchbar ist (leer, oder ein Abschnitt faellt aus).
     */
    private function mapRoute(array $route): ?array
    {
        $legs = [];
        foreach ($route['parts'] ?? [] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (!empty($part['isCancelled'])) {
                return null;
            }

            $line  = is_array($part['line'] ?? null) ? $part['line'] : [];
            $type  = (string) ($line['transportType'] ?? '');
            $from  = self::place($part['from'] ?? []);
            $to    = self::place($part['to'] ?? []);
            $dep   = (string) ($part['from']['plannedDeparture'] ?? '');
            // MVG nennt auch die Ankunftszeit "plannedDeparture".
            $arr   = (string) ($part['to']['plannedDeparture'] ?? '');
            if ($dep === '' || $arr === '') {
                return null;
            }

            $depDelay = self::delay($part['from'] ?? []);
            $arrDelay = self::delay($part['to'] ?? []) ?? $depDelay;
            $live     = !empty($part['realTime']);

            if ($type === 'PEDESTRIAN') {
                $legs[] = [
                    'mode'         => 'walk',
                    'kind'         => 'walk',
                    'from'         => $from,
                    'to'           => $to,
                    'departure'    => $dep,
                    'arrival'      => $arr,
                    'durationMin'  => self::minutes($dep, $arr),
                    'changesPlace' => $from['name'] !== $to['name'],
                ];
                continue;
            }

            $label = trim((string) ($line['label'] ?? ''));
            $stops = [$from + ['departure' => $dep]];
            foreach ($part['intermediateStops'] ?? [] as $st) {
                if (!is_array($st)) {
                    continue;
                }
                $stops[] = self::place($st) + ['departure' => (string) ($st['plannedDeparture'] ?? '')];
            }
            $stops[] = $to + ['arrival' => $arr];

            $geometry = [];
            if (is_string($part['pathPolyline'] ?? null) && $part['pathPolyline'] !== '') {
                $geometry = OebbHafas::decodePolyline($part['pathPolyline']);
            }

            $legs[] = [
                'mode'          => 'train',
                'jid'           => '',
                'geometry'      => $geometry,
                'category'      => self::TRANSPORT_TO_CATEGORY[$type] ?? $label,
                'categoryName'  => '',
                // Linienverkehr: die Linie ist der Name, eine Zugnummer gibt
                // es nicht - siehe trainLabel() im Frontend.
                'line'          => $label,
                'trainNumber'   => '',
                'name'          => $label,
                'direction'     => (string) ($line['destination'] ?? ''),
                'operator'      => 'MVG',
                'from'          => $from,
                'to'            => $to,
                'stops'         => $stops,
                'departure'     => $dep,
                'arrival'       => $arr,
                'departureReal' => $live && $depDelay ? self::shift($dep, $depDelay) : null,
                'arrivalReal'   => $live && $arrDelay ? self::shift($arr, $arrDelay) : null,
                'durationMin'   => self::minutes($dep, $arr),
                'cancelled'     => false,
                'sev'           => !empty($line['sev']),
                // Im ganzen MVV gilt das Deutschlandticket.
                'dTicket'       => 'Deutschlandticket gültig',
                'stationNotes'  => array_merge(
                    self::accessNotes($part['from'] ?? [], 'from'),
                    self::accessNotes($part['to'] ?? [], 'to')
                ),
            ];
        }

        $trains = array_values(array_filter($legs, static fn($l) => $l['mode'] === 'train'));
        if ($trains === []) {
            return null;
        }

        $first = $legs[0];
        $last  = $legs[count($legs) - 1];

        return [
            'id'          => 'mvg-' . (string) ($route['uniqueId'] ?? md5(json_encode($route))),
            'departure'   => $first['departure'],
            'arrival'     => $last['arrival'],
            'departureReal' => $trains[0]['departureReal'] ?? null,
            'arrivalReal' => $trains[count($trains) - 1]['arrivalReal'] ?? null,
            'durationMin' => self::minutes($first['departure'], $last['arrival']),
            'changes'     => max(0, count($trains) - 1),
            'legs'        => $legs,
            'countries'   => ['de'],
            'bookingUrl'  => null,
            'price'       => null,
            // Tarifzonen statt Preis: die MVG nennt sie, einen Betrag nicht.
            // 0 ist die Zone M, 1 bis 12 die Ringe darum.
            'tariffZones' => array_values(array_map('intval', (array) ($route['ticketingInformation']['zones'] ?? []))),
            'source'      => 'mvg',
        ];
    }

    /**
     * Gestörter Aufzug oder Rolltreppe an einem Halt - die MVG meldet beides
     * je Halt in der Verbindung mit, im selben Format wie die
     * Stationsmeldungen der DB.
     *
     * @return array<int,array{at:string,text:string}>
     */
    private static function accessNotes(array $p, string $at): array
    {
        $was = [];
        if (!empty($p['hasOutOfOrderElevator'])) {
            $was[] = 'ein Aufzug';
        }
        if (!empty($p['hasOutOfOrderEscalator'])) {
            $was[] = 'eine Rolltreppe';
        }
        if ($was === []) {
            return [];
        }
        return [[
            'at'   => $at,
            // Ohne Haltnamen: die Anzeige setzt die Meldung ohnehin unter
            // den Halt, wie bei den Meldungen der DB.
            'text' => ucfirst(implode(' und ', $was)) . ' außer Betrieb (laut MVG).',
        ]];
    }

    /** Ein MVG-Halt im Ortsformat der App. */
    private static function place(array $p): array
    {
        $gid = (string) ($p['stationGlobalId'] ?? '');
        $platform = $p['platform'] ?? null;
        return [
            'id'       => $gid !== '' ? 'mvg:' . $gid : '',
            'name'     => (string) ($p['name'] ?? ''),
            'country'  => 'de',
            'platform' => $platform !== null && $platform !== '' ? (string) $platform : null,
            'lat'      => isset($p['latitude']) ? (float) $p['latitude'] : null,
            'lon'      => isset($p['longitude']) ? (float) $p['longitude'] : null,
        ];
    }

    /** Verspätung in Minuten, soweit gemeldet. */
    private static function delay(array $p): ?int
    {
        foreach (['departureDelayInMinutes', 'arrivalDelayInMinutes'] as $k) {
            if (isset($p[$k]) && is_numeric($p[$k])) {
                return (int) $p[$k];
            }
        }
        return null;
    }

    /** ISO-Zeitpunkt um Minuten verschoben, Zonenangabe bleibt erhalten. */
    private static function shift(string $iso, int $minutes): ?string
    {
        try {
            return (new DateTimeImmutable($iso))->modify(sprintf('%+d minutes', $minutes))->format('Y-m-d\TH:i:sP');
        } catch (Exception) {
            return null;
        }
    }

    private static function minutes(string $a, string $b): int
    {
        $ta = strtotime($a);
        $tb = strtotime($b);
        return ($ta === false || $tb === false) ? 0 : (int) round(($tb - $ta) / 60);
    }

    /**
     * Abfahrten an einer MVG-Haltestelle, im Tafelformat von
     * OebbHafas::stationBoard().
     *
     * Die MVG kennt nur Abfahrten ab JETZT, verschoben um offsetInMinutes -
     * einen Zeitpunkt nächste Woche kann man nicht fragen. Echtzeit gibt es
     * dafür für alles, auch für die S-Bahn, bei der HAFAS in München oft
     * nur den Fahrplan kennt.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function departures(string $globalId, int $offsetMin = 0, int $limit = 40): array
    {
        $res = $this->http->getJson(
            $this->departuresUrl($globalId, $offsetMin, $limit),
            ['User-Agent' => $this->userAgent()]
        );
        return $this->mapDepartures($res);
    }

    private function departuresUrl(string $globalId, int $offsetMin, int $limit): string
    {
        return rtrim((string) ($this->cfg['endpoint'] ?? ''), '/') . '/departures?' . http_build_query([
            'globalId'        => $globalId,
            'limit'           => $limit,
            'offsetInMinutes' => max(0, $offsetMin),
            'transportTypes'  => 'UBAHN,TRAM,SBAHN,BUS,REGIONAL_BUS,BAHN,SCHIFF',
        ]);
    }

    /** @return array{ok:bool,error:?string,data:array} */
    private function mapDepartures(array $res): array
    {
        if (!$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'MVG: HTTP ' . $res['status'] . ($res['error'] ? ' (' . $res['error'] . ')' : ''),
                'data'  => [],
            ];
        }

        $berlin = new DateTimeZone('Europe/Berlin');
        $iso = static function ($ms) use ($berlin): ?string {
            if (!is_numeric($ms) || (int) $ms <= 0) {
                return null;
            }
            return (new DateTimeImmutable('@' . intdiv((int) $ms, 1000)))->setTimezone($berlin)->format('Y-m-d\TH:i:sP');
        };

        $out = [];
        foreach ($res['json'] as $d) {
            if (!is_array($d)) {
                continue;
            }
            $planned = $iso($d['plannedDepartureTime'] ?? null);
            if ($planned === null) {
                continue;
            }
            $live  = !empty($d['realtime']);
            $real  = $live ? $iso($d['realtimeDepartureTime'] ?? null) : null;
            $type  = (string) ($d['transportType'] ?? '');
            $label = trim((string) ($d['label'] ?? ''));
            $infos = [];
            foreach ((array) ($d['infos'] ?? []) as $i) {
                $t = Text::plain((string) ($i['message'] ?? ''));
                if ($t !== '') {
                    $infos[] = $t;
                }
            }

            $out[] = [
                'jid'             => '',
                'category'        => self::TRANSPORT_TO_CATEGORY[$type] ?? $label,
                'categoryName'    => '',
                'line'            => $label,
                'trainNumber'     => '',
                'name'            => $label,
                'direction'       => trim((string) ($d['destination'] ?? '')),
                'planned'         => $planned,
                'real'            => $real,
                'delay'           => $live && isset($d['delayInMinutes']) ? (int) $d['delayInMinutes'] : null,
                'platform'        => isset($d['platform']) && $d['platform'] !== '' ? (string) $d['platform'] : null,
                'platformChanged' => !empty($d['platformChanged']),
                'cancelled'       => !empty($d['cancelled']),
                'sev'             => !empty($d['sev']),
                'remarks'         => array_slice($infos, 0, 2),
                // Fahrtnummer: dieselbe an allen Halten einer Fahrt - so
                // findet trip() die eigene U-Bahn am Ausstieg wieder.
                'tripCode'        => isset($d['tripCode']) ? (string) $d['tripCode'] : '',
                'source'          => 'mvg',
            ];
        }
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Aktuelle Störungs- und Baustellen-Meldungen der MVG.
     *
     * Der Endpoint liefert unabhängig vom Ort das gesamte Netz - die
     * Auswahl ist einheitlich München (SWM + MVV + DDB für S-Bahn).
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function messages(): array
    {
        $url = rtrim((string) ($this->cfg['endpoint'] ?? ''), '/') . '/messages';

        $res = $this->http->getJson($url, ['User-Agent' => $this->userAgent()]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return [
                'ok'    => false,
                'error' => 'MVG: HTTP ' . $res['status'] . ($res['error'] ? ' (' . $res['error'] . ')' : ''),
                'data'  => [],
            ];
        }

        $out = [];
        foreach ($res['json'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $lines = [];
            foreach ((array) ($m['lines'] ?? []) as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $label = trim((string) ($l['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $lines[] = [
                    'label'        => $label,
                    'transportType' => (string) ($l['transportType'] ?? ''),
                    'network'      => (string) ($l['network'] ?? ''),
                    'sev'          => (bool) ($l['sev'] ?? false),
                ];
            }

            $out[] = [
                'id'          => (string) ($m['id'] ?? ($m['title'] ?? '')),
                'type'        => (string) ($m['type'] ?? ''),
                // Die MVG liefert Absätze und Fettungen als HTML mit; das
                // Frontend setzt alles per textContent und würde die Tags
                // sonst wörtlich anzeigen.
                'title'       => Text::plain((string) ($m['title'] ?? '')),
                'description' => Text::plain((string) ($m['description'] ?? '')),
                'validFrom'   => self::toIsoTime($m['validFrom'] ?? null),
                'validTo'     => self::toIsoTime($m['validTo'] ?? null),
                'lines'       => $lines,
                'provider'    => (string) ($m['provider'] ?? ''),
            ];
        }

        // Aktive Meldungen zuerst, dann nach Beginn absteigend - so steht die
        // frischeste akute Störung immer oben.
        $now = time();
        usort($out, static function ($a, $b) use ($now) {
            $aActive = self::isActive($a, $now) ? 0 : 1;
            $bActive = self::isActive($b, $now) ? 0 : 1;
            if ($aActive !== $bActive) {
                return $aActive - $bActive;
            }
            return strcmp((string) $b['validFrom'], (string) $a['validFrom']);
        });

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /** @param string[] $transports */
    private function productsFromTransportTypes(array $transports): array
    {
        $out = [];
        foreach ($transports as $t) {
            $t = strtoupper(trim($t));
            if (isset(self::TRANSPORT_TO_PRODUCT[$t])) {
                $out[self::TRANSPORT_TO_PRODUCT[$t]] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * MVG akzeptiert Anfragen auch ohne User-Agent, aber sie loggen ihn zur
     * Missbrauchsanalyse. Wir identifizieren uns kooperativ statt uns zu
     * verstecken - der Endpoint ist ausdrücklich für die Web-App gedacht,
     * kein Grund für Spielchen.
     */
    private function userAgent(): string
    {
        return (string) ($this->cfg['user_agent'] ?? 'train-maxxing (+github)');
    }

    /** MVG liefert Millisekunden-Timestamps. */
    private static function toIsoTime($ms): ?string
    {
        if (!is_int($ms) && !(is_string($ms) && ctype_digit($ms))) {
            return null;
        }
        $sec = (int) ((int) $ms / 1000);
        if ($sec <= 0) {
            return null;
        }
        return gmdate('c', $sec);
    }

    /** Ist eine Meldung im Zeitfenster? Ohne Angabe: als aktiv werten. */
    private static function isActive(array $m, int $now): bool
    {
        $from = $m['validFrom'] !== null ? strtotime((string) $m['validFrom']) : null;
        $to   = $m['validTo']   !== null ? strtotime((string) $m['validTo'])   : null;
        if ($from !== null && $from !== false && $from > $now) {
            return false;
        }
        if ($to !== null && $to !== false && $to < $now) {
            return false;
        }
        return true;
    }
}
