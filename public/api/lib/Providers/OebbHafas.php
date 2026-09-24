<?php
/**
 * ÖBB HAFAS (fahrplan.oebb.at/bin/mgate.exe).
 *
 * Das ist unsere primäre Fahrplanquelle für CH/DE/AT. Sie liefert:
 *   - Verbindungen mit Zeiten, Dauer, Umstiegen
 *   - Zuggattung (ICE, RJX, EC, IC, NJ, ...) und Zugnummer  -> Basis für den Nerd-Mode
 *   - Ländercode pro Station                               -> Basis für Abo-Anteile (GA/Klimaticket)
 *
 * Sie liefert bewusst KEINE Preise: das HAFAS-Tarifobjekt enthält bei ÖBB nur
 * einen Deeplink in den Ticketshop. Preise kommen aus dem DB-Provider bzw. der
 * Schätzung in Fares.php.
 */
final class OebbHafas
{
    /**
     * HAFAS-Produktklassen als Bitmaske: 1 = Hochgeschwindigkeit (ICE/RJX),
     * 2 = Nachtzug, 4 = IC/EC. Alles zusammen kennzeichnet einen Fernbahnhof.
     */
    private const CLS_LONG_DISTANCE = 1 | 2 | 4;

    /** Stützpunkte je Abschnitt für die Karte - hält die Antwort klein. */
    private const MAX_GEOMETRY_POINTS = 60;

    /** Länder, deren Baustellen hier interessieren. */
    private const DACH = ['de', 'at', 'ch'];

    /** Die ersten beiden Ziffern einer UIC-Stationsnummer als Ländercode. */
    private const UIC_COUNTRY = ['80' => 'de', '81' => 'at', '85' => 'ch'];

    /**
     * Ab wie vielen Tagen eine Meldung als Baustelle gilt.
     *
     * Darunter ist es eine Störung: eine Weichenstörung am Dienstag gehört
     * nicht in eine Übersicht, die zeigen soll, wo im Netz gerade groß
     * gebaut wird. Eine Woche trennt beides sauber.
     */
    private const MIN_DAYS = 7;

    /** Zeichen, nach denen der Meldungstext in der Liste abgeschnitten wird. */
    private const MAX_TEXT = 130;

    /**
     * HAFAS-Produktklassen auf die Gattungsnamen der DB abbilden.
     * Nur so lassen sich Treffer beider Quellen gleich bewerten - sonst
     * gewinnt ein österreichischer Dorfhalt gegen einen Münchner
     * U-Bahn-Knoten, bloß weil er pauschal eingestuft wurde.
     */
    private const CLS_TO_PRODUCT = [
        1    => 'ICE',
        2    => 'BUS',
        4    => 'EC_IC',
        8    => 'EC_IC',
        16   => 'REGIONAL',
        32   => 'SBAHN',
        64   => 'BUS',
        256  => 'UBAHN',
        512  => 'TRAM',
        4096 => 'REGIONAL',
    ];

    /** @return string[] */
    private static function productsFromCls(int $pCls): array
    {
        $out = [];
        foreach (self::CLS_TO_PRODUCT as $bit => $name) {
            if (($pCls & $bit) !== 0) {
                $out[$name] = true;
            }
        }
        return array_keys($out);
    }

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Große Bauarbeiten und Störungen im Netz (HAFAS Information Manager).
     *
     * Liefert Meldungen mit betroffenem ABSCHNITT (von-Ort, bis-Ort),
     * Gültigkeitszeitraum und Koordinaten - also genau das, was sich auf
     * einer Karte darstellen lässt: welche Strecke ist wie lange betroffen.
     *
     * WAS HIER RAUSFAELLT, und warum: HimSearch liefert alles, was im
     * Betrieb gerade Bestand hat - vom mehrmonatigen Streckenumbau bis zum
     * nicht barrierefreien Bahnsteig. Für eine Übersicht "wo wird gerade
     * groß gebaut" ist das zu fein. Deshalb drei Siebe:
     *
     *   1. KATEGORIE. HAFAS trennt Betriebsmeldungen (1-3: Störung,
     *      Bauarbeiten, Ausfall) von Reisehinweisen (4). Ohne diesen Filter
     *      stehen 117 "ACHTUNG: Starker Reisetag"-Hinweise in der Liste.
     *   2. LAND. Diese Instanz gehört der ÖBB und kennt auch Meldungen aus
     *      Ungarn, Slowenien und Italien. Hier interessiert der
     *      deutschsprachige Raum.
     *   3. DAUER. Was in wenigen Tagen vorbei ist, ist keine Baustelle,
     *      sondern eine Störung. Siehe MIN_DAYS.
     *
     * ABDECKUNG: Der Schwerpunkt liegt bei Österreich - nachgemessen über
     * 500 Meldungen: 452 mit österreichischem, 17 mit deutschem und 9 mit
     * schweizerischem Anfangsbahnhof. Eine deutschlandweite Quelle braucht
     * einen eigenen Provider; im README steht, was dafür nötig wäre.
     *
     * @param int $days Vorausschau in Tagen
     * @return array{ok:bool,error:?string,data:array}
     */
    public function works(int $days = 30, int $max = 500): array
    {
        $res = $this->call('HimSearch', [
            'dateB'  => date('Ymd'),
            'dateE'  => date('Ymd', strtotime('+' . max(1, $days) . ' days')),
            'timeB'  => '000000',
            'timeE'  => '235900',
            // Hoch angesetzt, weil die Auswahl weiter unten stattfindet: von
            // 500 Meldungen bleiben nach Kategorie, Land, Dauer und
            // Entdoppelung rund drei Dutzend übrig.
            'maxNum' => max(1, min(500, $max)),
            // Der STRECKENVERLAUF des betroffenen Abschnitts, nicht nur seine
            // Endpunkte. Ohne ihn bleibt der Karte nur eine gerade Linie
            // zwischen zwei Bahnhöfen - und die läuft quer durchs Gelände,
            // wo die Schiene einen Bogen macht.
            //
            // Der Schalter gehört in 'req', nicht in 'cfg': dort quittiert
            // ihn HAFAS mit "Parse fail".
            'getPolyline' => true,
        ]);
        if (!$res['ok']) {
            return $res;
        }

        $body   = $res['data']['res'] ?? [];
        $common = $body['common'] ?? [];
        $locL   = $common['locL'] ?? [];
        $catL   = $common['himMsgCatL'] ?? [];
        $edgeL  = $common['himMsgEdgeL'] ?? [];
        $out    = [];

        foreach (($body['msgL'] ?? []) as $m) {
            $catId = null;
            foreach ($m['catRefL'] ?? [] as $ci) {
                $catId = $catL[$ci]['id'] ?? null;
                if ($catId !== null) {
                    break;
                }
            }
            if ($catId === null || $catId < 1 || $catId > 3) {
                continue;
            }

            $from = $locL[$m['fLocX'] ?? -1] ?? null;
            $to   = $locL[$m['tLocX'] ?? -1] ?? null;
            if ($from === null || $to === null) {
                continue;
            }

            // Bei grenzüberschreitenden Abschnitten zählt die Seite, die
            // hierher gehört: Villach-Jesenice ist für uns eine
            // österreichische Baustelle, keine slowenische.
            $land = self::country($from);
            if (!in_array($land, self::DACH, true)) {
                $land = self::country($to);
            }
            if (!in_array($land, self::DACH, true)) {
                continue;
            }

            $head = Text::plain((string) ($m['head'] ?? ''));
            if ($head === '') {
                continue;
            }

            $start = self::himDate($m['sDate'] ?? null);
            $end   = self::himDate($m['eDate'] ?? null);
            if (!self::longEnough($start, $end)) {
                continue;
            }

            $out[] = [
                'id'    => (string) ($m['hid'] ?? ($head . $land)),
                'title' => $head,
                // HAFAS liefert den Text mit HTML-Fragmenten; die Anzeige
                // setzt alles per textContent, also hier schon bereinigen -
                // und kürzen, siehe summarise().
                'text'  => self::summarise(Text::plain((string) ($m['text'] ?? '')), $head),
                'from'  => self::worksPlace($from),
                'to'    => self::worksPlace($to),
                'start' => $start,
                'end'   => $end,
                'country'  => $land,
                'geometry' => $this->worksGeometry($m, $edgeL, $common),
                'category' => (int) $catId,
                'products' => self::productsFromCls((int) ($m['prod'] ?? 0)),
                // Fährt hier Fernverkehr? Zwei Wege dorthin, weil HAFAS die
                // Produktklasse der MELDUNG in vier von fünf Fällen auf
                // null lässt: dann entscheiden die beiden Endbahnhöfe.
                'longDistance' => self::isLongDistance($m, $from, $to),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => self::mergeDuplicates($out)];
    }

    /**
     * Streckenverlauf des betroffenen Abschnitts.
     *
     * Eine Meldung verweist über `edgeRefL` auf ein oder mehrere Kanten des
     * Netzes; jede Kante bringt ihren eigenen Polylinienzug mit. Aneinander-
     * gehängt ergibt das den tatsächlichen Verlauf - bei einer Sperrung
     * über mehrere Betriebsstellen also den ganzen Zug der Strecke, nicht
     * die Luftlinie zwischen erstem und letztem Bahnhof.
     *
     * @return array<int,array{0:float,1:float}>
     */
    private function worksGeometry(array $msg, array $edgeL, array $common): array
    {
        $points = [];
        foreach ($msg['edgeRefL'] ?? [] as $ei) {
            $edge = $edgeL[$ei] ?? null;
            if ($edge === null || !isset($edge['polyG'])) {
                continue;
            }
            foreach ($this->geometryOf($edge, $common) as $p) {
                $points[] = $p;
            }
        }
        return $this->thin($points, self::MAX_GEOMETRY_POINTS);
    }

    /** Ländercode einer HAFAS-Station, klein geschrieben. */
    private static function country(array $loc): string
    {
        $code = strtolower((string) (($loc['countryCodeL'] ?? [])[0] ?? ''));
        if ($code !== '') {
            return $code;
        }
        // Rückfallebene: die ersten beiden Ziffern der UIC-Nummer sind der
        // Ländercode - 80 Deutschland, 81 Österreich, 85 Schweiz.
        return self::UIC_COUNTRY[substr((string) ($loc['extId'] ?? ''), 0, 2)] ?? '';
    }

    /**
     * Fährt auf dem betroffenen Abschnitt Fernverkehr?
     *
     * Erst die Produktklasse der Meldung selbst - die ist eindeutig, steht
     * aber nur bei rund einem Fünftel der Meldungen. Sonst entscheiden die
     * Endbahnhöfe: sind BEIDE Fernverkehrshalte, liegt der Abschnitt auf
     * einer Fernverkehrsstrecke. Nur einer reicht nicht, sonst zählt jede
     * Nebenbahn mit, die irgendwo in einen Hauptbahnhof mündet.
     */
    private static function isLongDistance(array $msg, array $from, array $to): bool
    {
        $prod = (int) ($msg['prod'] ?? 0);
        if ($prod !== 0) {
            return ($prod & self::CLS_LONG_DISTANCE) !== 0;
        }
        return ((int) ($from['pCls'] ?? 0) & self::CLS_LONG_DISTANCE) !== 0
            && ((int) ($to['pCls'] ?? 0) & self::CLS_LONG_DISTANCE) !== 0;
    }

    /** Dauert die Meldung lange genug, um eine Baustelle zu sein? */
    private static function longEnough(?string $start, ?string $end): bool
    {
        if ($end === null) {
            return true; // ohne Enddatum: im Zweifel behalten
        }
        $von = strtotime($start ?? 'today') ?: time();
        $bis = strtotime($end);
        if ($bis === false) {
            return true;
        }
        return ($bis - $von) >= self::MIN_DAYS * 86400;
    }

    /**
     * Dieselbe Baustelle, mehrfach gemeldet, zu einem Eintrag.
     *
     * HAFAS meldet je Linie, je Richtung und je Zeitabschnitt neu:
     * Wampersdorf-Ebenfurth stand viermal in der Liste, wortgleich, nur mit
     * anderen Datumsgrenzen. Der Schlüssel ignoriert deshalb sowohl die
     * Richtung (Hartberg-Fehring ist dieselbe Sperrung wie
     * Fehring-Hartberg) als auch den Zeitraum - und der zusammengefasste
     * Eintrag bekommt den weitesten Zeitraum aller Einzelmeldungen.
     *
     * Auch der Titel geht nur bis zum Schrägstrich in den Schlüssel ein:
     * "Bauarbeiten - Schienenersatzverkehr/geänderte Fahrzeiten" und
     * ".../vorverlegte Abfahrtszeit" beschreiben dieselbe Baustelle.
     *
     * @param array<int,array> $works
     * @return array<int,array>
     */
    private static function mergeDuplicates(array $works): array
    {
        $byKey = [];
        foreach ($works as $w) {
            $ends = [$w['from']['id'] ?: $w['from']['name'], $w['to']['id'] ?: $w['to']['name']];
            sort($ends);
            $thema = trim(explode('/', $w['title'])[0]);
            $key = mb_strtolower($thema . '|' . implode('|', $ends));

            if (!isset($byKey[$key])) {
                $byKey[$key] = $w;
                continue;
            }

            $vorhanden = &$byKey[$key];
            if ($w['start'] !== null && ($vorhanden['start'] === null || $w['start'] < $vorhanden['start'])) {
                $vorhanden['start'] = $w['start'];
            }
            if ($vorhanden['end'] !== null && ($w['end'] === null || $w['end'] > $vorhanden['end'])) {
                $vorhanden['end'] = $w['end'];
            }
            // Fernverkehr aus irgendeiner der Teilmeldungen zählt.
            $vorhanden['longDistance'] = $vorhanden['longDistance'] || $w['longDistance'];
            // Und der ausführlichere Streckenverlauf gewinnt: dieselbe
            // Baustelle wird je Linie gemeldet, und nicht jede Linie fährt
            // den ganzen gesperrten Abschnitt.
            if (count($w['geometry']) > count($vorhanden['geometry'])) {
                $vorhanden['geometry'] = $w['geometry'];
            }
            unset($vorhanden);
        }

        return array_values($byKey);
    }

    /**
     * Aus dem Meldungstext einen Satz machen, der in eine Zeile passt.
     *
     * Die HAFAS-Texte sind 280 bis 340 Zeichen lang und zu vier Fünfteln
     * Formelware: "Wir haben für Sie einen Schienenersatzverkehr
     * eingerichtet. Bitte beachten Sie, dass in den Bussen des
     * Schienenersatzverkehres keine Fahrradmitnahme möglich ist ...". In
     * der Liste lief das über den Rand hinaus und verdrängte genau die
     * Angaben, wegen derer man hinschaut: Abschnitt und Zeitraum.
     *
     * Behalten wird der erste Satz - er nennt die Sache. Wiederholt er nur
     * den Titel, bleibt das Feld leer.
     */
    private static function summarise(string $text, string $head): string
    {
        $text = trim(preg_replace('/\\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        // In Sätze zerlegen. Das Satzende ist ein Punkt gefolgt von
        // Leerzeichen und Großbuchstabe - "z.B." und "ca." beenden so
        // keinen Satz.
        $saetze = preg_split('/(?<=[.!?])\\s+(?=\\p{Lu})/u', $text) ?: [$text];

        // Sätze, die nichts hinzufügen. Der erste wiederholt den Abschnitt,
        // der ohnehin danebensteht; die übrigen sind Formelware, die in
        // jeder zweiten Meldung wortgleich vorkommt.
        $leer = '/(kann dieser Zug .* nicht fahren'
            . '|^Bitte beachten Sie'
            . '|^Wir bitten um'
            . '|um Verständnis'
            . '|Fahrradmitnahme)/ui';

        foreach ($saetze as $satz) {
            $satz = trim($satz);
            if ($satz !== '' && !preg_match($leer, $satz)) {
                $text = $satz;
                break;
            }
        }

        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT - 1) . '…';
        }

        // Sagt der Text nichts, was der Titel nicht schon sagt, ist er weg.
        return mb_strtolower($text) === mb_strtolower($head) ? '' : $text;
    }

    /** @param array<string,mixed> $loc */
    private static function worksPlace(array $loc): array
    {
        return [
            'name' => (string) ($loc['name'] ?? ''),
            'id'   => (string) ($loc['extId'] ?? ''),
            'lat'  => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
            'lon'  => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
        ];
    }

    /** HAFAS-Datum "20260817" als ISO-Datum. */
    private static function himDate(?string $d): ?string
    {
        if ($d === null || !preg_match('/^\d{8}$/', $d)) {
            return null;
        }
        return substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    }

    /** Ortssuche. @return array{ok:bool,error:?string,data:array} */
    public function locations(string $query, int $limit = 8): array
    {
        $res = $this->call('LocMatch', [
            'input' => [
                'field'  => 'S',
                'loc'    => ['name' => $query . '?', 'type' => 'S'],
                'maxLoc' => $limit,
            ],
        ]);

        if (!$res['ok']) {
            return $res;
        }

        $locs = $res['data']['res']['match']['locL'] ?? [];
        $out  = [];
        foreach ($locs as $l) {
            if (($l['type'] ?? '') !== 'S') {
                continue; // nur Stationen, keine Adressen/POIs
            }
            $pCls = (int) ($l['pCls'] ?? 0);
            $out[] = [
                'id'           => (string) ($l['extId'] ?? ''),
                'name'         => (string) ($l['name'] ?? ''),
                'country'      => strtolower((string) (($l['countryCodeL'][0] ?? ''))),
                'lat'          => isset($l['crd']['y']) ? $l['crd']['y'] / 1000000 : null,
                'lon'          => isset($l['crd']['x']) ? $l['crd']['x'] / 1000000 : null,
                'longDistance' => ($pCls & self::CLS_LONG_DISTANCE) !== 0,
                // Gleiche Produktnamen wie bei der DB, damit beide Quellen
                // nach denselben Maßstäben bewertet werden können.
                'products'     => self::productsFromCls($pCls),
            ];
        }

        // Reihenfolge bewusst NICHT verändern: HAFAS sortiert bereits nach
        // eigenem Relevanzgewicht. Namen mit "(U)" sind keine U-Bahn-Stationen,
        // sondern Meta-Stationen, die alle Bahnsteige eines Bahnhofs bündeln -
        // für eine Verbindungssuche genau die richtige Wahl.
        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Verbindungssuche.
     *
     * @param string $fromId  EVA-Nummer
     * @param string $toId    EVA-Nummer
     * @param string $date    YYYY-MM-DD
     * @param string $time    HH:MM
     * @param bool   $arrival true = $time ist Ankunftszeit
     * @param ?string $scroll  Blätter-Kontext aus einer früheren Antwort
     * @param bool   $realtime Mit der Echtzeitlage routen statt mit dem Fahrplan,
     *                         siehe call()
     * @return array{ok:bool,error:?string,data:array,scrollF:?string,scrollB:?string}
     */
    public function journeys(
        string $fromId,
        string $toId,
        string $date,
        string $time,
        bool $arrival = false,
        int $results = 6,
        int $travelClass = 2,
        array $viaIds = [],
        int $productMask = 0,
        ?int $minChangeMin = null,
        ?string $scroll = null,
        bool $realtime = false
    ): array {
        $req = [
            'depLocL'     => [['type' => 'S', 'lid' => 'A=1@L=' . $fromId . '@']],
            'arrLocL'     => [['type' => 'S', 'lid' => 'A=1@L=' . $toId . '@']],
            'outDate'     => str_replace('-', '', $date),
            'outTime'     => str_replace(':', '', $time) . '00',
            // Zwischenhalte brauchen wir doppelt: für den Streckenverlauf im
            // Nerd-Mode und für die exakte Länderaufteilung in Fares.php.
            'getPasslist' => true,
            // Polylines sind die Geometrie für die Karte.
            'getPolyline' => true,
            'getTariff'   => true,
            'trfReq'      => [
                'jnyCl'    => $travelClass,
                'tvlrProf' => [['type' => 'E']],
                'cType'    => 'PK',
            ],
            'numF'        => $results,
        ];

        if ($arrival) {
            $req['outFrwd'] = false;
        }

        // Blättern. HAFAS deckelt numF je Anfrage bei rund sechs Treffern -
        // mehr gibt es nur über den Kontext aus der vorigen Antwort, der an
        // die Stelle von Datum und Uhrzeit tritt. In welche RICHTUNG geblättert
        // wird, steckt im Kontext selbst ('3|OF|…' vorwärts, '3|OB|…'
        // rückwärts) - hier ist beides derselbe Parameter.
        if ($scroll !== null && $scroll !== '') {
            unset($req['outDate'], $req['outTime']);
            $req['ctxScr'] = $scroll;
        }

        // Mindestumsteigezeit. HAFAS wirft dann nicht nur zu knappe
        // Verbindungen weg, sondern sucht auch andere Wege - etwa über eine
        // Nachbarhaltestelle, zu der man läuft.
        if ($minChangeMin !== null && $minChangeMin >= 0) {
            $req['minChgTime'] = $minChangeMin;
        }

        // Verkehrsmittel einschränken. 0 heißt "keine Einschränkung".
        if ($productMask > 0) {
            $req['jnyFltrL'] = [[
                'type'  => 'PROD',
                'mode'  => 'INC',
                'value' => $productMask,
            ]];
        }

        if ($viaIds !== []) {
            $req['viaLocL'] = [];
            foreach ($viaIds as $v) {
                $req['viaLocL'][] = ['loc' => ['type' => 'S', 'lid' => 'A=1@L=' . $v . '@']];
            }
        }

        $res = $this->call('TripSearch', $req, $realtime ? ['rtMode' => 'REALTIME'] : []);
        if (!$res['ok']) {
            return $res + ['scrollF' => null, 'scrollB' => null];
        }

        $body   = $res['data']['res'] ?? [];
        $common = $body['common'] ?? [];
        $cons   = $body['outConL'] ?? [];

        $journeys = [];
        foreach ($cons as $con) {
            $j = $this->mapConnection($con, $common);
            if ($j !== null) {
                $journeys[] = $j;
            }
        }

        return [
            'ok'      => true,
            'error'   => null,
            'data'    => $journeys,
            // Kontext für die nächste Seite - spätere Abfahrten.
            'scrollF' => isset($body['outCtxScrF']) ? (string) $body['outCtxScrF'] : null,
            // Und derselbe Kontext rückwärts - frühere Abfahrten.
            'scrollB' => isset($body['outCtxScrB']) ? (string) $body['outCtxScrB'] : null,
        ];
    }

    /**
     * Abfahrts- oder Ankunftstafel eines Bahnhofs.
     *
     * Dieselben Namensregeln wie bei der Verbindungssuche, damit eine S-Bahn
     * auf der Tafel genauso heißt wie in der Trefferliste ("S1", nicht "DB
     * S1 (Zug-Nr. 6934)"). Jeder Eintrag trägt seine jid - ein Tipp auf die
     * Zeile lädt den Zuglauf über traindetails.
     *
     * Für Ankünfte nennt HAFAS nur das Fahrtziel, nicht den Startbahnhof;
     * `direction` ist deshalb bei beiden Tafeln das Ziel des Zuges.
     *
     * @return array{ok:bool,error:?string,data:array,station:?array}
     */
    public function stationBoard(
        string $eva,
        string $date,
        string $time,
        bool $arrivals = false,
        int $durationMin = 60,
        int $max = 40,
        int $productMask = 0
    ): array {
        $req = [
            'type'   => $arrivals ? 'ARR' : 'DEP',
            'date'   => str_replace('-', '', $date),
            'time'   => str_replace(':', '', $time) . '00',
            'stbLoc' => ['type' => 'S', 'lid' => 'A=1@L=' . $eva . '@'],
            'maxJny' => $max,
            'dur'    => $durationMin,
        ];
        if ($productMask > 0) {
            $req['jnyFltrL'] = [['type' => 'PROD', 'mode' => 'INC', 'value' => $productMask]];
        }

        $res = $this->call('StationBoard', $req);
        if (!$res['ok']) {
            return $res + ['station' => null];
        }

        $body   = $res['data']['res'] ?? [];
        $common = $body['common'] ?? [];
        $locL   = $common['locL'] ?? [];
        $prodL  = $common['prodL'] ?? [];
        $a      = $arrivals ? 'a' : 'd';

        $out = [];
        foreach (($body['jnyL'] ?? []) as $j) {
            $stb  = $j['stbStop'] ?? [];
            $prod = $prodL[$stb[$a . 'ProdX'] ?? $j['prodX'] ?? -1] ?? [];
            $ctx  = $prod['prodCtx'] ?? [];
            $name = self::productName($prod);
            $day  = (string) ($j['date'] ?? '');

            $planned = $this->hafasTime($day, $stb[$a . 'TimeS'] ?? null, $stb[$a . 'TZOffset'] ?? null);
            if ($planned === null) {
                continue;
            }
            $real = $this->hafasTime($day, $stb[$a . 'TimeR'] ?? null, $stb[$a . 'TZOffset'] ?? null);
            $pltS = $stb[$a . 'PltfS']['txt'] ?? null;
            $pltR = $stb[$a . 'PltfR']['txt'] ?? null;

            $out[] = [
                'jid'           => (string) ($j['jid'] ?? ''),
                'category'      => trim((string) ($ctx['catOut'] ?? '')),
                'categoryName'  => trim((string) ($ctx['catOutL'] ?? '')),
                'line'          => self::lineName($ctx),
                'trainNumber'   => self::trainNumber($ctx, $name),
                'name'          => $name,
                'direction'     => trim((string) ($j['dirTxt'] ?? '')),
                'planned'       => $planned,
                'real'          => $real,
                'delay'         => $real !== null ? $this->diffMinutes($planned, $real) : null,
                'platform'      => $pltR ?? $pltS,
                'platformChanged' => $pltR !== null && $pltS !== null && $pltR !== $pltS,
                'cancelled'     => !empty($stb[$a . 'Cncl']) || !empty($j['isCncl']),
                'source'        => 'hafas',
            ];
        }

        // Der angefragte Bahnhof, nicht der erste Ort der Antwort - das ist
        // in München "München Hbf (tief)", der S-Bahnhof darunter.
        $loc = null;
        foreach ($locL as $l) {
            if ((string) ($l['extId'] ?? '') === $eva) {
                $loc = $l;
                break;
            }
        }
        $loc ??= $locL[0] ?? null;
        return [
            'ok'      => true,
            'error'   => null,
            'data'    => $out,
            'station' => $loc === null ? null : [
                'id'   => (string) ($loc['extId'] ?? $eva),
                'name' => (string) ($loc['name'] ?? ''),
                'lat'  => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
                'lon'  => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
            ],
        ];
    }

    /**
     * Züge, die gerade in einem Kartenausschnitt unterwegs sind.
     *
     * HAFAS berechnet die Position aus Fahrplan und Echtzeitlage
     * (trainPosMode CALC). Das ist keine GPS-Ortung, kommt der Realität aber
     * nahe genug, um zu sehen wo ein Zug gerade steckt.
     *
     * @param float $south,$west,$north,$east Grad
     * @return array{ok:bool,error:?string,data:array}
     */
    public function liveTrains(float $south, float $west, float $north, float $east, int $max = 40, int $productMask = 0): array
    {
        $req = [
            'maxJny'       => $max,
            'onlyRT'       => false,
            'date'         => (new DateTimeImmutable('now'))->format('Ymd'),
            'time'         => (new DateTimeImmutable('now'))->format('His'),
            'rect'         => [
                'llCrd' => ['x' => (int) round($west * 1000000), 'y' => (int) round($south * 1000000)],
                'urCrd' => ['x' => (int) round($east * 1000000), 'y' => (int) round($north * 1000000)],
            ],
            'perSize'      => 120000,
            'perStep'      => 30000,
            'ageOfReport'  => true,
            'trainPosMode' => 'CALC',
        ];

        if ($productMask > 0) {
            $req['jnyFltrL'] = [['type' => 'PROD', 'mode' => 'INC', 'value' => $productMask]];
        }

        $res = $this->call('JourneyGeoPos', $req);
        if (!$res['ok']) {
            return $res;
        }

        $body  = $res['data']['res'] ?? [];
        $prodL = $body['common']['prodL'] ?? [];
        $out   = [];

        foreach (($body['jnyL'] ?? []) as $jny) {
            $pos = $jny['pos'] ?? null;
            if ($pos === null || !isset($pos['x'], $pos['y'])) {
                continue;
            }
            $prod = $prodL[$jny['prodX'] ?? -1] ?? [];
            $ctx  = $prod['prodCtx'] ?? [];

            $name = self::productName($prod);

            $out[] = [
                'lat'         => $pos['y'] / 1000000,
                'lon'         => $pos['x'] / 1000000,
                'category'    => trim((string) ($ctx['catOut'] ?? '')),
                'line'        => self::lineName($ctx),
                'trainNumber' => self::trainNumber($ctx, $name),
                'name'        => $name,
                'direction'   => trim((string) ($jny['dirTxt'] ?? '')),
                // Mit dieser Kennung lässt sich der Lauf im Detail nachladen.
                'jid'         => (string) ($jny['jid'] ?? ''),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Der komplette Lauf eines Zuges: alle Halte mit Soll- und Ist-Zeiten,
     * Gleisen und Verspätung.
     *
     * @param string $jid Kennung aus liveTrains()
     * @return array{ok:bool,error:?string,data:array}
     */
    public function journeyDetails(string $jid): array
    {
        $res = $this->call('JourneyDetails', [
            'jid'         => $jid,
            'getPolyline' => false,
            'getPasslist' => true,
        ]);
        if (!$res['ok']) {
            return $res;
        }

        $body   = $res['data']['res'] ?? [];
        $common = $body['common'] ?? [];
        $jny    = $body['journey'] ?? [];
        if ($jny === []) {
            return ['ok' => false, 'error' => 'Kein Zuglauf gefunden.', 'data' => []];
        }

        $prod = ($common['prodL'] ?? [])[$jny['prodX'] ?? -1] ?? [];
        $ctx  = $prod['prodCtx'] ?? [];
        $name = self::productName($prod);
        $date = (string) ($jny['date'] ?? '');
        $locL = $common['locL'] ?? [];

        $stops       = [];
        $maxDelay    = 0;
        $hasRealtime = false;

        foreach (($jny['stopL'] ?? []) as $st) {
            $loc = $locL[$st['locX'] ?? -1] ?? null;
            if ($loc === null) {
                continue;
            }

            $arrPlan = $this->hafasTime($date, $st['aTimeS'] ?? null, $st['aTZOffset'] ?? null);
            $arrReal = $this->hafasTime($date, $st['aTimeR'] ?? null, $st['aTZOffset'] ?? null);
            $depPlan = $this->hafasTime($date, $st['dTimeS'] ?? null, $st['dTZOffset'] ?? null);
            $depReal = $this->hafasTime($date, $st['dTimeR'] ?? null, $st['dTZOffset'] ?? null);

            // Verspätung an der Abfahrt, ersatzweise an der Ankunft.
            $delay = null;
            if ($depPlan !== null && $depReal !== null) {
                $delay = $this->diffMinutes($depPlan, $depReal);
            } elseif ($arrPlan !== null && $arrReal !== null) {
                $delay = $this->diffMinutes($arrPlan, $arrReal);
            }
            if ($delay !== null) {
                $hasRealtime = true;
                $maxDelay = max($maxDelay, $delay);
            }

            $stops[] = [
                'name'          => (string) ($loc['name'] ?? ''),
                'id'            => (string) ($loc['extId'] ?? ''),
                'country'       => strtolower((string) ($loc['countryCodeL'][0] ?? '')),
                'lat'           => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
                'lon'           => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
                'arrival'       => $arrPlan,
                'arrivalReal'   => $arrReal,
                'departure'     => $depPlan,
                'departureReal' => $depReal,
                'delay'         => $delay,
                'platform'      => $st['dPltfR']['txt'] ?? $st['dPltfS']['txt'] ?? $st['aPltfS']['txt'] ?? null,
                'cancelled'     => (bool) ($st['dCncl'] ?? $st['aCncl'] ?? false),
            ];
        }

        // Meldungen (Bauarbeiten, Störungen) sind für die Anzeige nützlich —
        // aber nur, wenn sie das auch wirklich sind.
        //
        // VIER SORTEN RAUSCHEN kamen hier ungefiltert durch:
        //
        //   type 'A'  — die AUSSTATTUNG des Zuges. "Klimaanlage",
        //     "Rollstuhlstellplatz", "Fahrradmitnahme begrenzt möglich".
        //
        //   code 'ZN' — der ZUGNAME. "Wörthersee", "Loreley". Als Meldung
        //     gelesen ergibt das keinen Sinn.
        //
        //   der volle HIM-TEXT — die Kopfzeile ist der Kern, der Fliesstext
        //     zählt danach jede betroffene Linie und jede S-Bahn einzeln auf.
        //     Genommen wird deshalb ausschliesslich die Kopfzeile; nur wenn
        //     die fehlt, tritt der gekürzte Fliesstext an ihre Stelle.
        //
        //   MELDUNGEN VON WOANDERS — das grösste Ärgernis. Ein Zuglauf ist
        //     länger als die eigene Fahrt: Auf München–Freiburg standen ein
        //     defekter Aufzug in Salzburg und ein nicht barrierefreier
        //     Bahnsteig in Villach, weil derselbe ICE dort vorher entlangkam.
        //
        // Gegen das Letzte trägt jede Meldung jetzt ihren GELTUNGSBEREICH mit
        // (`from`/`to` als Haltindex). HAFAS liefert ihn in fLocX/tLocX —
        // allerdings als Index in die Ortsliste, nicht in die Halteliste,
        // deshalb der Umweg über den Namen. Wer nur ein Teilstück fährt,
        // bekommt in der Anzeige auch nur dessen Meldungen.
        $haltIndex = [];
        foreach ($stops as $i => $st) {
            $haltIndex[$st['name']] ??= $i;
        }
        $ortAlsHalt = static function ($locX) use ($locL, $haltIndex): ?int {
            $name = (string) (($locL[$locX] ?? [])['name'] ?? '');
            return $name === '' ? null : ($haltIndex[$name] ?? null);
        };

        $messages = [];
        $gesehen  = [];
        foreach (($jny['msgL'] ?? []) as $m) {
            $rem = ($common['remL'] ?? [])[$m['remX'] ?? -1] ?? null;
            $him = ($common['himL'] ?? [])[$m['himX'] ?? -1] ?? null;

            if ($him !== null) {
                // Nur die Kopfzeile - das ist das, was fett dasteht.
                $txt = Text::plain((string) ($him['head'] ?? ''));
                if ($txt === '') {
                    $txt = self::summarise(Text::plain((string) ($him['text'] ?? '')), '');
                } elseif (mb_strlen($txt) > self::MAX_TEXT) {
                    $txt = mb_substr($txt, 0, self::MAX_TEXT - 1) . '…';
                }
            } else {
                if ($rem === null || ($rem['type'] ?? '') === 'A') {
                    continue;
                }
                if (strtoupper((string) ($rem['code'] ?? '')) === 'ZN') {
                    continue;
                }
                $txt = self::summarise(Text::plain((string) ($rem['txtN'] ?? '')), '');
            }

            if ($txt === '' || isset($gesehen[$txt])) {
                continue;
            }
            $gesehen[$txt] = true;

            $messages[] = [
                'text' => $txt,
                'from' => $ortAlsHalt($m['fLocX'] ?? -1),
                'to'   => $ortAlsHalt($m['tLocX'] ?? -1),
            ];
        }

        return [
            'ok'    => true,
            'error' => null,
            'data'  => [
                'category'    => trim((string) ($ctx['catOut'] ?? '')),
                'categoryName' => trim((string) ($ctx['catOutL'] ?? '')),
                'line'        => self::lineName($ctx),
                'trainNumber' => self::trainNumber($ctx, $name),
                'name'        => $name,
                'direction'   => trim((string) ($jny['dirTxt'] ?? '')),
                'operator'    => trim((string) ($ctx['admin'] ?? '')),
                'delay'       => $hasRealtime ? $maxDelay : null,
                'hasRealtime' => $hasRealtime,
                'cancelled'   => (bool) ($jny['isCncl'] ?? false),
                'stops'       => $stops,
                // Grosszügiger als das, was am Ende dasteht: gefiltert wird
                // erst in der Anzeige, und zwar auf das eigene Teilstück.
                // Würden wir hier schon deckeln, fiele womöglich die
                // relevante Meldung zugunsten einer aus Villach weg.
                'messages'    => array_slice($messages, 0, 12),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Mapping HAFAS -> unser normalisiertes Format
    // ------------------------------------------------------------------

    /**
     * Anzeigename eines Produkts, z.B. "ICE 516".
     *
     * HAFAS liefert zwei Namen und hält sich nicht daran, welcher von beiden
     * die Zugnummer trägt: mal steht sie im langen (`name`), mal nur im
     * kurzen (`nameS`). Genommen wird deshalb der längere - er enthält den
     * anderen praktisch immer und dazu die Nummer.
     */
    private static function productName(array $prod): string
    {
        $saeubern = static function (string $v): string {
            $v = trim((string) preg_replace('/\s+/u', ' ', $v));
            // "S 33 (Zug-Nr. 20326)" -> "S 33". Der Klammerzusatz ist eine
            // Anzeigehilfe von HAFAS und gehört in keine Beschriftung: bei
            // einer S-Bahn heißt der Zug nach seiner LINIE, und die
            // Zugnummer steht ohnehin getrennt in `prodCtx.num`.
            return trim((string) preg_replace('/\s*\((?:Zug-Nr\.|Zugnr\.?|Nr\.)[^)]*\)\s*$/ui', '', $v));
        };

        $long  = $saeubern((string) ($prod['name'] ?? ''));
        $short = $saeubern((string) ($prod['nameS'] ?? ''));

        if ($long === '' || $short === '') {
            return $long !== '' ? $long : $short;
        }
        return strlen($long) >= strlen($short) ? $long : $short;
    }

    /**
     * Liniennummer eines Produkts, sofern es eine Linie ist.
     *
     * Fernverkehr hat keine: ein ICE 593 fährt heute so und morgen anders,
     * die Nummer IST der Zug. Nahverkehr hat eine, und dort ist es umgekehrt -
     * die Linie ist das, was am Bahnsteig angeschrieben steht und wonach
     * gefragt wird. Zurückgegeben wird, was HAFAS in `prodCtx.line` führt:
     * mal nur die Zahl ("33"), mal mit Gattung ("RE3").
     */
    private static function lineName(array $ctx): string
    {
        return trim((string) ($ctx['line'] ?? ''));
    }

    /**
     * Zugnummer.
     *
     * In `prodCtx.num` steht sie nur, wenn HAFAS sie getrennt führt - in den
     * Positionsmeldungen (JourneyGeoPos) fehlt sie regelmäßig, und die
     * Karte zeigte dann bloß "ICE" statt "ICE 516". Fehlt sie, wird sie aus
     * dem Produktnamen gezogen. Gattungen ohne Nummer (S-Bahn, Bus) liefern
     * erwartungsgemäß einen leeren String.
     */
    private static function trainNumber(array $ctx, string $name): string
    {
        $num = trim((string) ($ctx['num'] ?? ''));
        if ($num !== '') {
            return $num;
        }
        return preg_match('/(?:^|\s)(\d{1,5}[A-Za-z]?)$/u', $name, $m) === 1 ? $m[1] : '';
    }

    private function mapConnection(array $con, array $common): ?array
    {
        $baseDate = (string) ($con['date'] ?? '');
        if ($baseDate === '') {
            return null;
        }

        $locL  = $common['locL'] ?? [];
        $prodL = $common['prodL'] ?? [];

        $legs      = [];
        $countries = [];

        foreach (($con['secL'] ?? []) as $sec) {
            $type = (string) ($sec['type'] ?? '');
            $dep  = $sec['dep'] ?? [];
            $arr  = $sec['arr'] ?? [];

            $fromLoc = $this->mapLocation($locL[$dep['locX'] ?? -1] ?? null, $dep['dPltfS']['txt'] ?? null);
            $toLoc   = $this->mapLocation($locL[$arr['locX'] ?? -1] ?? null, $arr['aPltfS']['txt'] ?? null);

            foreach ([$fromLoc, $toLoc] as $l) {
                if ($l !== null && $l['country'] !== '') {
                    $countries[$l['country']] = true;
                }
            }

            $depTime = $this->hafasTime($baseDate, $dep['dTimeR'] ?? $dep['dTimeS'] ?? null, $dep['dTZOffset'] ?? null);
            $arrTime = $this->hafasTime($baseDate, $arr['aTimeR'] ?? $arr['aTimeS'] ?? null, $arr['aTZOffset'] ?? null);

            if ($type === 'JNY') {
                $jny  = $sec['jny'] ?? [];
                $prod = $prodL[$jny['prodX'] ?? -1] ?? [];
                $ctx  = $prod['prodCtx'] ?? [];
                $prodName = self::productName($prod);

                $stops = $this->mapStops($jny['stopL'] ?? [], $locL, $baseDate);
                foreach ($stops as $s) {
                    if ($s['country'] !== '') {
                        $countries[$s['country']] = true;
                    }
                }

                $legs[] = [
                    'mode'         => 'train',
                    // Kennung des konkreten Zuglaufs. Damit lässt sich zu
                    // diesem Abschnitt später die Echtzeitlage nachladen
                    // (action=traindetails) - Grundlage der Live-Verfolgung.
                    'jid'          => (string) ($jny['jid'] ?? ''),
                    'geometry'     => $this->geometryOf($jny, $common),
                    'category'     => trim((string) ($ctx['catOut'] ?? '')),
                    'categoryName' => trim((string) ($ctx['catOutL'] ?? '')),
                    'line'         => self::lineName($ctx) !== ''
                        ? self::lineName($ctx)
                        : trim((string) ($ctx['matchId'] ?? '')),
                    'trainNumber'  => self::trainNumber($ctx, $prodName),
                    'name'         => $prodName,
                    'direction'    => trim((string) ($jny['dirTxt'] ?? '')),
                    'operator'     => trim((string) ($ctx['admin'] ?? '')),
                    'from'         => $fromLoc,
                    'to'           => $toLoc,
                    'stops'        => $stops,
                    'departure'    => $depTime,
                    'arrival'      => $arrTime,
                    'durationMin'  => $this->diffMinutes($depTime, $arrTime),
                    // Auch der Zug, der zwar fährt, aber am EIGENEN Ein- oder
                    // Ausstieg nicht hält, fällt für diese Reise aus. Genau so
                    // sieht eine gesperrte Stammstrecke aus: die S-Bahn endet
                    // vorzeitig, und am Marienplatz steht dCncl, nicht isCncl.
                    'cancelled'    => (bool) ($jny['isCncl'] ?? false)
                        || !empty($dep['dCncl']) || !empty($arr['aCncl']),
                ];
            } else {
                // Alles außer einer Fahrt ist ein Weg zu Fuß: WALK, TRSF,
                // aber auch seltenere Typen wie DEVI oder KISS. Sie hier
                // aufzuführen wäre zu eng - was kein JNY ist, fährt nicht.
                $legs[] = [
                    'mode'        => 'walk',
                    'kind'        => strtolower($type),
                    'from'        => $fromLoc,
                    'to'          => $toLoc,
                    'departure'   => $depTime,
                    'arrival'     => $arrTime,
                    'durationMin' => $this->diffMinutes($depTime, $arrTime),
                    // Wechselt der Halt, geht man wirklich ein Stück.
                    'changesPlace' => ($fromLoc['name'] ?? '') !== ($toLoc['name'] ?? ''),
                ];
            }
        }

        if ($legs === []) {
            return null;
        }

        $trainLegs = array_values(array_filter($legs, static fn($l) => $l['mode'] === 'train'));
        if ($trainLegs === []) {
            return null; // reine Fußweg-"Verbindungen" interessieren uns nicht
        }

        $depAll = $this->hafasTime($baseDate, $con['dep']['dTimeS'] ?? null, $con['dep']['dTZOffset'] ?? null);
        $arrAll = $this->hafasTime($baseDate, $con['arr']['aTimeS'] ?? null, $con['arr']['aTZOffset'] ?? null);

        // Buchungs-Deeplink: HAFAS liefert bei ÖBB einen fertigen Shop-Link.
        $bookingUrl = $con['trfRes']['clickout'] ?? null;

        // Identität der Verbindung. NICHT cid nehmen: das ist nur der Index
        // innerhalb einer Antwort ("C-0") und wiederholt sich beim
        // Weiterblättern auf jeder Seite. ctxRecon bezeichnet dagegen genau
        // diese eine Verbindung; fehlt es, tun es Ab- und Ankunftszeit.
        $recon = trim((string) ($con['ctxRecon'] ?? ''));
        $ident = $recon !== ''
            ? $recon
            : ($depAll ?? '') . '|' . ($arrAll ?? '') . '|' . (json_encode($legs) ?: '');

        return [
            'id'          => substr(sha1($ident), 0, 16),
            'departure'   => $depAll,
            'arrival'     => $arrAll,
            'durationMin' => $this->hafasDuration((string) ($con['dur'] ?? '')) ?: $this->diffMinutes($depAll, $arrAll),
            'changes'     => (int) ($con['chg'] ?? max(0, count($trainLegs) - 1)),
            'legs'        => $legs,
            // HAFAS rechnet jede Verbindung gegen die Echtzeitlage nach und
            // markiert, was so nicht mehr fahrbar ist - meist ein Anschluss,
            // den eine Verspätung schon gekappt hat. Stand bisher nirgends.
            'reachable'   => empty($con['isNotRdbl']),
            'countries'   => array_keys($countries),
            'bookingUrl'  => $bookingUrl,
            'price'       => null, // wird später von DB-Provider oder Fares.php gefüllt
            'source'      => 'oebb',
        ];
    }

    /**
     * Geometrie eines Abschnitts als [[lat, lon], ...] für die Karte.
     *
     * HAFAS liefert die Linien Google-encoded in common.polyL; der Abschnitt
     * verweist über jny.polyG.polyXL auf die Einträge. Wir dünnen auf
     * höchstens MAX_GEOMETRY_POINTS Stützpunkte aus - für eine
     * Übersichtskarte reicht das und die Antwort bleibt klein.
     *
     * @return array<int,array{0:float,1:float}>
     */
    private function geometryOf(array $jny, array $common): array
    {
        $polyL = $common['polyL'] ?? [];
        $idx   = $jny['polyG']['polyXL'] ?? [];
        if ($polyL === [] || $idx === []) {
            return [];
        }

        $points = [];
        foreach ($idx as $i) {
            $poly = $polyL[$i] ?? null;
            if ($poly === null) {
                continue;
            }
            $enc = $poly['crdEncYX'] ?? null;
            if (!is_string($enc) || $enc === '') {
                continue;
            }
            foreach ($this->decodePolyline($enc) as $p) {
                $points[] = $p;
            }
        }

        return $this->thin($points, self::MAX_GEOMETRY_POINTS);
    }

    /**
     * Google-Encoded-Polyline-Dekodierung (Delta-kodierte Varints, Faktor 1e5).
     *
     * @return array<int,array{0:float,1:float}>
     */
    public static function decodePolyline(string $enc): array
    {
        $points = [];
        $index  = 0;
        $len    = strlen($enc);
        $lat    = 0;
        $lon    = 0;

        while ($index < $len) {
            foreach (['lat', 'lon'] as $which) {
                $shift  = 0;
                $result = 0;
                do {
                    if ($index >= $len) {
                        return $points;
                    }
                    $b = ord($enc[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($which === 'lat') {
                    $lat += $delta;
                } else {
                    $lon += $delta;
                }
            }
            $points[] = [$lat / 100000.0, $lon / 100000.0];
        }

        return $points;
    }

    /** Gleichmäßiges Ausdünnen; Anfang und Ende bleiben erhalten. */
    private function thin(array $points, int $max): array
    {
        $n = count($points);
        if ($n <= $max) {
            return $points;
        }

        $step = ($n - 1) / ($max - 1);
        $out  = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $points[(int) round($i * $step)];
        }

        return $out;
    }

    /**
     * Zwischenhalte eines Abschnitts.
     * Die Ländercodes hier sind die Grundlage dafür, dass Fares.php eine
     * grenzquerende Fahrt korrekt aufteilen kann (z.B. Wien-Salzburg = AT,
     * Salzburg-München = DE) statt sie pauschal zu halbieren.
     */
    private function mapStops(array $stopL, array $locL, string $baseDate): array
    {
        $out = [];
        foreach ($stopL as $s) {
            $loc = $locL[$s['locX'] ?? -1] ?? null;
            if ($loc === null) {
                continue;
            }
            $out[] = [
                'id'        => (string) ($loc['extId'] ?? ''),
                'name'      => (string) ($loc['name'] ?? ''),
                'country'   => strtolower((string) ($loc['countryCodeL'][0] ?? '')),
                'lat'       => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
                'lon'       => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
                'arrival'   => $this->hafasTime($baseDate, $s['aTimeR'] ?? $s['aTimeS'] ?? null, $s['aTZOffset'] ?? null),
                'departure' => $this->hafasTime($baseDate, $s['dTimeR'] ?? $s['dTimeS'] ?? null, $s['dTZOffset'] ?? null),
                'platform'  => $s['dPltfS']['txt'] ?? $s['aPltfS']['txt'] ?? null,
            ];
        }
        return $out;
    }

    private function mapLocation(?array $loc, ?string $platform): ?array
    {
        if ($loc === null) {
            return null;
        }
        return [
            'id'       => (string) ($loc['extId'] ?? ''),
            'name'     => (string) ($loc['name'] ?? ''),
            'country'  => strtolower((string) ($loc['countryCodeL'][0] ?? '')),
            'platform' => $platform !== null && $platform !== '' ? $platform : null,
            // Koordinaten brauchen wir für die Distanz- und damit Preisschätzung.
            'lat'      => isset($loc['crd']['y']) ? $loc['crd']['y'] / 1000000 : null,
            'lon'      => isset($loc['crd']['x']) ? $loc['crd']['x'] / 1000000 : null,
        ];
    }

    /**
     * HAFAS-Zeiten sind "HHMMSS" oder "DDHHMMSS" (DD = Tagesversatz zur Basis).
     * Zusammen mit dem Minuten-Offset ergibt das einen ISO-8601-Zeitstempel.
     */
    private function hafasTime(string $baseDate, ?string $time, ?int $tzOffsetMin): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        $dayOffset = 0;
        if (strlen($time) === 8) {
            $dayOffset = (int) substr($time, 0, 2);
            $time      = substr($time, 2);
        }
        if (strlen($time) !== 6) {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Ymd His',
            $baseDate . ' ' . $time,
            new DateTimeZone('UTC')
        );
        if ($dt === false) {
            return null;
        }
        if ($dayOffset > 0) {
            $dt = $dt->modify('+' . $dayOffset . ' day');
        }

        $offset = $tzOffsetMin ?? 60;
        $sign   = $offset < 0 ? '-' : '+';
        $abs    = abs($offset);

        return $dt->format('Y-m-d\TH:i:s')
            . $sign . str_pad((string) intdiv($abs, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) ($abs % 60), 2, '0', STR_PAD_LEFT);
    }

    /** "043800" -> 278 Minuten */
    private function hafasDuration(string $dur): int
    {
        if (strlen($dur) === 8) {
            $dur = substr($dur, 2);
        }
        if (strlen($dur) !== 6) {
            return 0;
        }
        return ((int) substr($dur, 0, 2)) * 60 + (int) substr($dur, 2, 2);
    }

    private function diffMinutes(?string $from, ?string $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }
        try {
            $a = new DateTimeImmutable($from);
            $b = new DateTimeImmutable($to);
        } catch (Exception $e) {
            return 0;
        }
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
    }

    // ------------------------------------------------------------------

    /**
     * Eine Anfrage an den HAFAS-Server.
     *
     * $cfg ergänzt die Dienstkonfiguration. Wichtig ist dort vor allem
     * rtMode: 'REALTIME' lässt HAFAS mit der Echtzeitlage routen, statt mit
     * dem Fahrplan. Verbindungen, deren Anschluss eine Verspätung schon
     * gekappt hat, fallen dann weg, und ausgefallene Züge werden umfahren.
     * Nachgemessen, was der ÖBB-Server annimmt: rtMode steht in `cfg`, nicht
     * in `req` (dort: "Parser error"), und von den Werten, die andere
     * HAFAS-Server kennen, gehen SERVER_DEFAULT, REALTIME und FULL - HYBRID
     * nicht.
     *
     * @param array<string,mixed> $cfg
     * @return array{ok:bool,error:?string,data:array}
     */
    private function call(string $method, array $req, array $cfg = []): array
    {
        $payload = [
            'auth'    => $this->cfg['auth'],
            'client'  => $this->cfg['client'],
            'ver'     => $this->cfg['ver'],
            'lang'    => $this->cfg['lang'],
            'svcReqL' => [[
                'cfg'  => ['polyEnc' => 'GPA'] + $cfg,
                'meth' => $method,
                'req'  => $req,
            ]],
        ];

        $res = $this->http->postJson($this->cfg['endpoint'], $payload, [
            'User-Agent' => 'Dalvik/2.1.0 (Linux; U; Android 13)',
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? ('HTTP ' . $res['status']), 'data' => []];
        }
        if ($res['json'] === null) {
            return ['ok' => false, 'error' => 'Ungültige Antwort (kein JSON)', 'data' => []];
        }

        $body = $res['json'];
        if (($body['err'] ?? 'OK') !== 'OK') {
            return ['ok' => false, 'error' => 'HAFAS: ' . ($body['errTxt'] ?? $body['err']), 'data' => []];
        }

        $svc = $body['svcResL'][0] ?? null;
        if ($svc === null) {
            return ['ok' => false, 'error' => 'HAFAS: leere Antwort', 'data' => []];
        }
        if (($svc['err'] ?? 'OK') !== 'OK') {
            return ['ok' => false, 'error' => 'HAFAS: ' . ($svc['errTxt'] ?? $svc['err']), 'data' => []];
        }

        return ['ok' => true, 'error' => null, 'data' => $svc];
    }
}
