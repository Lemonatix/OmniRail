<?php
/**
 * DB (bahn.de Web-API) - unsere Quelle für ECHTE Preise.
 *
 * ZWEI DINGE, DIE HIER WICHTIG SIND:
 *
 * 1. TLS-FINGERPRINT
 *    bahn.de läuft hinter Akamai Bot Manager. Der wertet den TLS-ClientHello
 *    aus, nicht die Header. Mit cURL-Standardeinstellungen kommt IMMER ein
 *    403 "OPS_BLOCKED" zurück - auch von einem privaten Anschluss aus.
 *    Deshalb wird dieser Provider mit Http::withBrowserTls() betrieben.
 *    Nachgemessen: ohne Cipher-Liste 403, mit 200.
 *
 * 2. NUR BAHNCARDS
 *    Die Angebots-API kennt ausschließlich BahnCards. Halbtax, GA,
 *    Vorteirtscard und KlimaTicket werden STILL IGNORIERT - die API liefert
 *    denselben Preis wie ohne Ermäßigung und meldet keinen Fehler.
 *    Nachgemessen Zürich->München: ohne Abo 34,19 EUR, BahnCard 50
 *    29,57 EUR, Halbtax 34,19 EUR (= wirkungslos), Unsinn-Wert ebenfalls
 *    34,19 EUR. Schweizer und österreichische Abos rechnet daher Fares.php
 *    auf den Länderanteil - klar als Schätzung gekennzeichnet.
 */
final class DbVendo
{
    /**
     * Nur diese Werte wirken tatsächlich. Bewusst kurz gehalten - alles
     * andere würde falsche Sicherheit vortäuschen.
     */
    private const DISCOUNT_MAP = [
        'bc25'  => 'BAHNCARD25',
        'bc50'  => 'BAHNCARD50',
        'bc100' => 'BAHNCARD100',
    ];

    /** UIC-Ländercode aus dem EVA-Präfix. Zuverlässiger als adminID. */
    private const EVA_COUNTRY = [
        '80' => 'de', '81' => 'at', '85' => 'ch',
        '83' => 'it', '84' => 'nl', '87' => 'fr', '88' => 'be',
    ];

    /**
     * Zugausstattung aus den `zugattribute` der DB, auf das Wesentliche
     * gekürzt. Die DB liefert rund vierzig Schlüssel, darunter viel
     * Werbetext ("Intercity 2: Info unter www.bahn.de/ic2"). Behalten wird,
     * was die Reise betrifft - und was sie VERHINDERN kann, ist als
     * `important` markiert: Reservierungspflicht, nur 2. Klasse,
     * DB-Fahrscheine gelten nicht, Ersatzverkehr.
     *
     * Nachgemessen über fünf Relationen (DE, CH-DE, DE-AT), 2026-09.
     */
    private const AMENITIES = [
        'BR' => ['bistro', null, false],            // Wert: "Bordrestaurant" / "Bordbistro"
        'MP' => ['snacks', 'Snacks am Platz', false],
        'WV' => ['wifi', 'WLAN', false],
        'LS' => ['power', 'Steckdosen', false],
        'UA' => ['business', 'Businessabteil', false],
        'FB' => ['bike', 'Fahrrad begrenzt', false],
        'KF' => ['bike', 'Fahrrad kostenlos', false],
        'FR' => ['bike', 'Fahrrad nur mit Reservierung', true],
        'FF' => ['bike', 'Fahrrad nur mit Reservierung', true],
        'RO' => ['wheelchair', 'Rollstuhlplatz', false],
        'RZ' => ['stepfree', 'Einstieg stufenfrei', false],
        'CK' => ['checkin', 'Komfort-Check-in', false],
        'IT' => ['sprinter', 'ICE Sprinter', false],
        'RP' => ['reservation', 'Reservierungspflicht', true],
        'K2' => ['class2', 'nur 2. Klasse', true],
        'DU' => ['nodb', 'DB-Fahrscheine gelten nicht', true],
        '9N' => ['nodticket', 'Deutschlandticket gilt nicht', true],
        'SV' => ['replacement', 'Ersatzverkehr', true],
    ];

    /**
     * Stationsmeldungen, die für den Weg durch den Bahnhof zählen. Die DB
     * hängt an jeden Abschnitt alle Meldungen seiner Halte - hier wird nur
     * behalten, was die Barrierefreiheit am Ein- oder Ausstieg betrifft.
     */
    private const ACCESS_PATTERN = '/aufzug|fahrstuhl|rolltreppe|fahrtreppe|barrierefrei|stufenfrei|rollstuhl/iu';

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        // Ohne Browser-TLS blockt bahn.de jede Anfrage.
        $this->http = $http->withBrowserTls();
        $this->cfg  = $cfg;
    }

    /** @return array{ok:bool,error:?string,data:array} */
    public function locations(string $query, int $limit = 8): array
    {
        $url = $this->cfg['bahnde']['locations']
            . '?suchbegriff=' . rawurlencode($query)
            . '&typ=ALL&limit=' . $limit;

        $res = $this->http->getJson($url, $this->browserHeaders());

        $blocked = $this->detectBlock($res);
        if ($blocked !== null) {
            return ['ok' => false, 'error' => $blocked, 'data' => []];
        }
        if (!$res['ok'] || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => []];
        }

        $out = [];
        foreach ($res['json'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Adressen und Sehenswürdigkeiten sind für eine Zugsuche nutzlos.
            if (($item['type'] ?? 'ST') !== 'ST') {
                continue;
            }
            $eva  = (string) ($item['extId'] ?? '');
            $id   = (string) ($item['id'] ?? '');
            $crd  = $this->coordsFromId($id);

            $out[] = [
                'id'       => $id,                 // "A=1@O=...@X=...@Y=...@L=8503000@"
                'evaId'    => $eva,
                'name'     => (string) ($item['name'] ?? ''),
                'country'  => $this->countryFromEva($eva),
                'lat'      => $item['lat'] ?? $crd['lat'],
                'lon'      => $item['lon'] ?? $crd['lon'],
                'products' => array_values((array) ($item['products'] ?? [])),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Verbindungen mit Preisen.
     *
     * @param string[] $discounts unsere Abo-IDs; nur BahnCards wirken hier
     * @return array{ok:bool,error:?string,data:array,usedDiscounts:array}
     */
    public function journeys(
        string $fromId,
        string $toId,
        string $date,
        string $time,
        bool $arrival = false,
        int $travelClass = 2,
        array $discounts = [],
        bool $fastOnly = true,
        array $products = [],
        ?int $minChangeMin = null
    ): array {
        $ermaessigungen = $this->mapDiscounts($discounts, $travelClass);

        $payload = [
            'abfahrtsHalt'     => $this->toDbLocationId($fromId),
            'ankunftsHalt'     => $this->toDbLocationId($toId),
            'anfrageZeitpunkt' => $date . 'T' . $time . ':00',
            'ankunftSuche'     => $arrival ? 'ANKUNFT' : 'ABFAHRT',
            'klasse'           => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            'produktgattungen' => Products::dbProducts($products),
            'reisende'         => [[
                'typ'            => 'ERWACHSENER',
                'ermaessigungen' => $ermaessigungen['payload'],
                'alter'          => [],
                'anzahl'         => 1,
            ]],
            'schnelleVerbindungen'              => $fastOnly,
            'sitzplatzOnly'                     => false,
            'bikeCarriage'                      => false,
            'reservierungsKontingenteVorhanden' => false,
        ];

        // Die DB kennt eine Mindestumsteigezeit. Sie durchzureichen ist besser
        // als hinterher zu filtern: so sucht sie passende Verbindungen, statt
        // dass wir die knappen nur wegwerfen. Nachgemessen: ohne den Parameter
        // Umstiege von 3-4 Minuten, mit minUmstiegszeit=10 dann 13-14.
        if ($minChangeMin !== null && $minChangeMin > 0) {
            $payload['minUmstiegszeit'] = $minChangeMin;
        }

        $res = $this->http->postJson($this->cfg['bahnde']['journeys'], $payload, $this->browserHeaders());

        $blocked = $this->detectBlock($res);
        if ($blocked !== null) {
            return ['ok' => false, 'error' => $blocked, 'data' => [], 'usedDiscounts' => []];
        }
        // Die Angebots-API antwortet mit 201, nicht 200.
        if (($res['status'] !== 200 && $res['status'] !== 201) || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => [], 'usedDiscounts' => []];
        }

        $out = [];
        foreach (($res['json']['verbindungen'] ?? []) as $v) {
            $mapped = $this->mapConnection($v);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return [
            'ok'            => true,
            'error'         => null,
            'data'          => $out,
            'usedDiscounts' => $ermaessigungen['applied'],
        ];
    }

    /**
     * Bestpreise über den Tag, in Zeitfenstern.
     *
     * Beantwortet die Frage "lohnt es sich, zwei Stunden später zu fahren?".
     * Die DB liefert dazu sechs Intervalle mit dem jeweils günstigsten
     * Angebot. Die Antwort ist mit über 1 MB groß, weil sie zu jedem
     * Intervall die kompletten Verbindungen mitschickt - wir behalten nur die
     * Preise.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function bestPrices(
        string $fromId,
        string $toId,
        string $date,
        int $travelClass = 2,
        array $discounts = [],
        array $products = []
    ): array {
        $payload = [
            'abfahrtsHalt'     => $this->toDbLocationId($fromId),
            'ankunftsHalt'     => $this->toDbLocationId($toId),
            'anfrageZeitpunkt' => $date . 'T00:00:00',
            'ankunftSuche'     => 'ABFAHRT',
            'klasse'           => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            'produktgattungen' => Products::dbProducts($products),
            'reisende'         => [[
                'typ'            => 'ERWACHSENER',
                'ermaessigungen' => $this->mapDiscounts($discounts, $travelClass)['payload'],
                'alter'          => [],
                'anzahl'         => 1,
            ]],
            'schnelleVerbindungen'              => true,
            'sitzplatzOnly'                     => false,
            'bikeCarriage'                      => false,
            'reservierungsKontingenteVorhanden' => false,
        ];

        // Der Bestpreis liegt neben der Verbindungssuche, nicht darunter.
        // (rtrim wäre hier falsch: es entfernt Zeichen, keine Zeichenkette.)
        $url = preg_replace('#/fahrplan$#', '/tagesbestpreis', $this->cfg['bahnde']['journeys'])
            ?? $this->cfg['bahnde']['journeys'];

        $res = $this->http->postJson($url, $payload, $this->browserHeaders());

        if ($this->detectBlock($res) !== null) {
            return ['ok' => false, 'error' => $this->detectBlock($res), 'data' => []];
        }
        if (($res['status'] !== 200 && $res['status'] !== 201) || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => []];
        }

        $out = [];
        foreach (($res['json']['intervalle'] ?? []) as $iv) {
            $betrag = $iv['preis']['betrag'] ?? null;
            $out[] = [
                'from'     => substr((string) ($iv['ab'] ?? ''), 11, 5),
                'to'       => substr((string) ($iv['bis'] ?? ''), 11, 5),
                'amount'   => $betrag !== null ? (float) $betrag : null,
                'currency' => (string) ($iv['preis']['waehrung'] ?? 'EUR'),
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $out];
    }

    /**
     * Ein Zuglauf bei der DB, im Format von OebbHafas::journeyDetails().
     *
     * WOZU: Die Live-Verfolgung lädt je Abschnitt den Zuglauf nach. Bisher
     * ging das nur über die jid von HAFAS - und die fehlt genau dort, wo man
     * sie in München bräuchte: bei U-Bahn, Tram und Bus, deren Fahrplan von
     * der DB kommt. Die DB hat dafür einen eigenen Endpunkt
     * (`/web/api/reiseloesung/fahrt`), den auch bahn.de benutzt. Er liefert
     * Soll- und Ist-Zeit je Halt, auch für die Münchner Tram, in rund 0,2 s.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function trip(string $journeyId): array
    {
        $base = preg_replace('#/angebote/fahrplan$#', '/reiseloesung/fahrt', $this->cfg['bahnde']['journeys'])
            ?? $this->cfg['bahnde']['journeys'];
        $url = $base . '?journeyId=' . rawurlencode($journeyId) . '&poly=false';

        $res = $this->http->getJson($url, $this->browserHeaders());
        $blocked = $this->detectBlock($res);
        if ($blocked !== null) {
            return ['ok' => false, 'error' => $blocked, 'data' => []];
        }
        if (!$res['ok'] || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => self::mapTrip($res['json'])];
    }

    /** Antwort von `reiseloesung/fahrt` im Zuglauf-Format der App. */
    public static function mapTrip(array $f): array
    {
        $stops = [];
        $maxDelay = 0;
        $hasRealtime = false;
        foreach (($f['halte'] ?? []) as $h) {
            $eva = (string) ($h['extId'] ?? '');
            $crd = ['lat' => null, 'lon' => null];
            $id  = (string) ($h['id'] ?? '');
            if (preg_match('/@Y=(-?\d+)@/', $id, $m)) {
                $crd['lat'] = ((int) $m[1]) / 1000000;
            }
            if (preg_match('/@X=(-?\d+)@/', $id, $m)) {
                $crd['lon'] = ((int) $m[1]) / 1000000;
            }
            $arr  = self::iso($h['ankunft']['sollzeit'] ?? null);
            $arrR = self::iso($h['ankunft']['echtzeit'] ?? null);
            $dep  = self::iso($h['abfahrt']['sollzeit'] ?? null);
            $depR = self::iso($h['abfahrt']['echtzeit'] ?? null);
            $delay = self::maxDelay([[$dep, $depR], [$arr, $arrR]]);
            if ($delay !== null) {
                $hasRealtime = true;
                $maxDelay = max($maxDelay, $delay);
            }
            $stops[] = [
                'name'          => (string) ($h['name'] ?? ''),
                'id'            => $eva,
                'country'       => '',
                'lat'           => $crd['lat'],
                'lon'           => $crd['lon'],
                'arrival'       => $arr,
                'arrivalReal'   => $arrR,
                'departure'     => $dep,
                'departureReal' => $depR,
                'delay'         => $delay,
                'platform'      => $h['ezGleis'] ?? $h['gleis'] ?? null,
                'cancelled'     => !empty($h['ausfall']) || !empty($h['cancelled']),
            ];
        }

        $messages = [];
        foreach (array_merge($f['priorisierteMeldungen'] ?? [], $f['risNotizen'] ?? []) as $m) {
            $txt = Text::plain((string) ($m['text'] ?? $m['value'] ?? ''));
            if ($txt !== '' && !in_array($txt, array_column($messages, 'text'), true)) {
                $messages[] = ['text' => $txt, 'from' => null, 'to' => null];
            }
        }

        $name = trim((string) ($f['zugName'] ?? ''));
        return [
            'category'    => trim((string) ($f['halte'][0]['kategorie'] ?? strtok($name, ' ') ?: '')),
            'categoryName' => '',
            'line'        => trim((string) preg_replace('/^\S+\s+/', '', $name)),
            'trainNumber' => '',
            'name'        => $name,
            'direction'   => (string) (end($stops)['name'] ?? ''),
            'operator'    => '',
            'delay'       => $hasRealtime ? $maxDelay : null,
            'hasRealtime' => $hasRealtime,
            'cancelled'   => !empty($f['cancelled']),
            'stops'       => $stops,
            'messages'    => array_slice($messages, 0, 6),
            'source'      => 'db',
        ];
    }

    /**
     * Alle Tarife einer Verbindung - nicht nur der günstigste.
     *
     * Die Suche nennt je Verbindung einen Preis, den billigsten verfügbaren
     * (meist Super Sparpreis). Welche Bedingungen daran hängen und was die
     * Alternativen kosten, weiß sie nicht. Das beantwortet die DB erst über
     * `recon` mit dem ctxRecon der Verbindung: Super Sparpreis, Sparpreis
     * und Flexpreis in beiden Klassen, jeweils mit Zugbindung, Storno und
     * City-Ticket, dazu der Preis einer Sitzplatzreservierung.
     *
     * Die Antwort ist mit über 100 kB groß, deshalb nur auf Nachfrage und
     * nur für eine Verbindung.
     *
     * @param string[] $discounts
     * @return array{ok:bool,error:?string,data:array}
     */
    public function offers(string $ctxRecon, int $travelClass = 2, array $discounts = []): array
    {
        $ermaessigung = $this->mapDiscounts($discounts, $travelClass);
        $payload = [
            'klasse'   => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            'reisende' => [[
                'typ'            => 'ERWACHSENER',
                'ermaessigungen' => $ermaessigung['payload'],
                'alter'          => [],
                'anzahl'         => 1,
            ]],
            'ctxRecon' => $ctxRecon,
            'reservierungsKontingenteVorhanden' => false,
        ];

        $url = preg_replace('#/fahrplan$#', '/recon', $this->cfg['bahnde']['journeys'])
            ?? $this->cfg['bahnde']['journeys'];

        $res = $this->http->postJson($url, $payload, $this->browserHeaders());
        $blocked = $this->detectBlock($res);
        if ($blocked !== null) {
            return ['ok' => false, 'error' => $blocked, 'data' => []];
        }
        if (($res['status'] !== 200 && $res['status'] !== 201) || $res['json'] === null) {
            return ['ok' => false, 'error' => 'DB: HTTP ' . $res['status'], 'data' => []];
        }

        $v = $res['json']['verbindungen'][0] ?? null;
        if (!is_array($v)) {
            return ['ok' => false, 'error' => 'DB: Verbindung nicht mehr gefunden.', 'data' => []];
        }

        return ['ok' => true, 'error' => null, 'data' => self::mapOffers($v, $ermaessigung['applied'] !== [])];
    }

    /**
     * Die Angebote aus einer recon-Antwort, aufgeräumt.
     *
     * Die DB schickt jedes Angebot mehrfach: einmal regulär (kontextTyp
     * STANDARD), einmal als "Upgrade" auf die andere Klasse
     * (UPSELL_VERKNUEPFT), und mit BahnCard im Profil zusätzlich den
     * Vorher-Preis. Dazu kommen Probe-BahnCards als Werbung (typ BAHNCARD)
     * - die interessieren nur als Rechenbeispiel "mit BahnCard 25 kostete
     * es so viel".
     *
     * DER RABATT-HINWEIS BEDEUTET ZWEIERLEI. Hat man eine BahnCard
     * angegeben, steht er an den eigenen Preisen ("20,00 € Ersparnis durch
     * BahnCard Rabatt"). Hat man keine, hängt er an zusätzlichen Angeboten,
     * die erst mit einer (Probe-)BahnCard gelten - nachgemessen München-
     * Frankfurt: Super Sparpreis 79,99 € regulär und 59,99 € "mit BahnCard"
     * in derselben Liste. Die gehören dann nicht zu den eigenen Tarifen,
     * sondern in `withBahnCard`.
     *
     * @return array{fares:array,withBahnCard:array,seat:?array,bahncard:array}
     */
    public static function mapOffers(array $v, bool $hasBahnCard = false): array
    {
        $fares = [];
        $withBahnCard = [];
        $bahncard = [];
        $seen = [];

        foreach (($v['reiseAngebote'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $name   = trim((string) ($a['name'] ?? ''));
            $amount = $a['preis']['betrag'] ?? null;
            if ($name === '' || !is_numeric($amount)) {
                continue;
            }

            $conditions = [];
            $rabatt = null;
            foreach (($a['konditionsAnzeigen'] ?? []) as $k) {
                $head  = trim((string) ($k['anzeigeUeberschrift'] ?? ''));
                $short = trim((string) ($k['textKurz'] ?? ''));
                if ($head === 'Rabatt') {
                    $rabatt = $short;   // "20,00 € Ersparnis durch BahnCard Rabatt"
                    continue;
                }
                if ($short !== '') {
                    $conditions[] = ['title' => $head, 'text' => $short];
                }
            }

            if (($a['typ'] ?? '') === 'BAHNCARD') {
                $bahncard[$name] = [
                    'name'     => $name,
                    'amount'   => (float) $amount,
                    'currency' => (string) ($a['preis']['waehrung'] ?? 'EUR'),
                ];
                continue;
            }

            $klasse = ($a['klasse'] ?? '') === 'KLASSE_1' ? 1 : 2;
            $fare = [
                'name'       => $name,
                'class'      => $klasse,
                'amount'     => (float) $amount,
                'currency'   => (string) ($a['preis']['waehrung'] ?? 'EUR'),
                'conditions' => $conditions,
                // Mit Rabatt-Hinweis ist das der Preis nach einer BahnCard,
                // die man NICHT hat: die DB rechnet das als Anreiz vor.
                'discountNote' => $rabatt,
                'partial'    => !empty($a['teilpreis']),
            ];

            // Doppelte (dasselbe Angebot als Standard und als Upsell) nur einmal.
            $key = $name . '|' . $klasse . '|' . $fare['amount'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($rabatt !== null && !$hasBahnCard) {
                $withBahnCard[] = $fare;
            } else {
                $fares[] = $fare;
            }
        }

        // Nach Klasse, darin günstigste zuerst.
        $order = static fn($x, $y) => [$x['class'], $x['amount']] <=> [$y['class'], $y['amount']];
        usort($fares, $order);
        usort($withBahnCard, $order);

        // Sitzplatzreservierung: steht an jedem Angebot gleich, einmal reicht.
        $seat = null;
        foreach (($v['reiseAngebote'] ?? []) as $a) {
            foreach (($a['hinfahrt']['sitzplatzAngebote'] ?? []) as $sp) {
                $preis = $sp['angebot']['preis']['betrag'] ?? null;
                if (is_numeric($preis)) {
                    $seat = [
                        'amount'    => (float) $preis,
                        'currency'  => (string) ($sp['angebot']['preis']['waehrung'] ?? 'EUR'),
                        'available' => !empty($sp['reservierungVerfuegbar']) && empty($sp['verbindungAusreserviert']),
                        'required'  => !empty($sp['reservierungspflicht']),
                        'included'  => !empty($sp['reservierungInklusive']),
                    ];
                    break 2;
                }
            }
        }

        return [
            'fares'        => $fares,
            'withBahnCard' => $withBahnCard,
            'seat'         => $seat,
            'bahncard'     => array_values($bahncard),
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Die Antwortstruktur ist flach: verbindungsAbschnitte, umstiegsAnzahl und
     * angebotsPreis liegen direkt auf der Verbindung. Zeiten stecken in
     * abfahrt.sollzeit / ankunft.sollzeit, Zwischenhalte in halte[].
     */
    private function mapConnection(array $v): ?array
    {
        $abs = $v['verbindungsAbschnitte'] ?? [];
        if ($abs === []) {
            return null;
        }

        $legs             = [];
        $countries        = [];
        $dTicketSegments  = [];

        foreach ($abs as $a) {
            // Soll- UND Ist-Zeit. Die DB liefert die Echtzeit direkt in der
            // Suchantwort mit - anders als HAFAS, das dafür je Abschnitt eine
            // eigene Abfrage braucht. Das Feld heißt 'echtzeit'.
            $dep     = self::iso($a['abfahrt']['sollzeit'] ?? null);
            $arr     = self::iso($a['ankunft']['sollzeit'] ?? null);
            $depReal = self::iso($a['abfahrt']['echtzeit'] ?? null);
            $arrReal = self::iso($a['ankunft']['echtzeit'] ?? null);

            // Ohne Sollzeit ist die Ist-Zeit die einzige, die wir haben.
            $dep = $dep ?? $depReal;
            $arr = $arr ?? $arrReal;

            $fromEva = (string) ($a['abfahrtsOrtExtId'] ?? '');
            $toEva   = (string) ($a['ankunftsOrtExtId'] ?? '');

            $from = [
                'id'       => $fromEva,
                'name'     => (string) ($a['abfahrtsOrt'] ?? ''),
                'country'  => $this->countryFromEva($fromEva),
                'platform' => $a['halte'][0]['gleis'] ?? null,
                'lat'      => null,
                'lon'      => null,
            ];
            $to = [
                'id'       => $toEva,
                'name'     => (string) ($a['ankunftsOrt'] ?? ''),
                'country'  => $this->countryFromEva($toEva),
                'platform' => null,
                'lat'      => null,
                'lon'      => null,
            ];

            foreach ([$from['country'], $to['country']] as $c) {
                if ($c !== '') {
                    $countries[$c] = true;
                }
            }

            $vm = $a['verkehrsmittel'] ?? [];

            if ($this->isWalk($a, $vm)) {
                $legs[] = [
                    'mode'        => 'walk',
                    'from'        => $from,
                    'to'          => $to,
                    'departure'   => $dep,
                    'arrival'     => $arr,
                    'durationMin' => (int) round(((int) ($a['abschnittsDauer'] ?? 0)) / 60),
                    // Wechselt der Halt, muss man tatsächlich ein Stück gehen.
                    'changesPlace' => ($from['name'] ?? '') !== ($to['name'] ?? ''),
                ];
                continue;
            }

            $stops = [];
            foreach (($a['halte'] ?? []) as $h) {
                $eva = (string) ($h['extId'] ?? '');
                // Die Koordinaten stecken nur in der ID, nicht in eigenen Feldern.
                $crd = $this->coordsFromId((string) ($h['id'] ?? ''));
                $stops[] = [
                    'id'        => $eva,
                    'name'      => (string) ($h['name'] ?? ''),
                    'country'   => $this->countryFromEva($eva),
                    'lat'       => $crd['lat'],
                    'lon'       => $crd['lon'],
                    'arrival'       => self::iso($h['ankunft']['sollzeit'] ?? null),
                    'arrivalReal'   => self::iso($h['ankunft']['echtzeit'] ?? null),
                    'departure'     => self::iso($h['abfahrt']['sollzeit'] ?? null),
                    'departureReal' => self::iso($h['abfahrt']['echtzeit'] ?? null),
                    'platform'      => $h['ezGleis'] ?? $h['gleis'] ?? null,
                    'cancelled'     => (bool) ($h['ausfall'] ?? false),
                ];
            }

            // Gründe für die Verspätung, soweit die DB sie nennt
            // ("Verspätung eines vorausfahrenden Zuges", "Polizeieinsatz").
            //
            // Nur risNotizen: das sind die Betriebsgründe zum Zuglauf.
            // himMeldungen enthalten daneben Bahnhofsinfos ("Aufzug in Celle
            // außer Betrieb"), die an einer Fahrt Frankfurt-Mannheim nichts
            // zu suchen haben. Die relevanten Störungsmeldungen zeigt ohnehin
            // die Live-Verfolgung.
            $remarks = [];
            foreach (($a['risNotizen'] ?? []) as $n) {
                $txt = Text::plain((string) ($n['value'] ?? ''));
                if ($txt !== '' && !in_array($txt, $remarks, true)) {
                    $remarks[] = $txt;
                }
                if (count($remarks) >= 2) {
                    break;
                }
            }

            // Die DB markiert selbst, auf welchen Teilstrecken das
            // Deutschlandticket gilt - besser als jede eigene Heuristik.
            $dTicket = null;
            foreach (($vm['zugattribute'] ?? []) as $z) {
                if (stripos((string) ($z['value'] ?? ''), 'deutschlandticket') !== false) {
                    $dTicket = trim((string) ($z['value'] ?? '') . ' ' . (string) ($z['teilstreckenHinweis'] ?? ''));
                    $dTicketSegments[] = $dTicket;
                    break;
                }
            }

            $legs[] = [
                'mode'         => 'train',
                // Kennung des Zuglaufs bei der DB - damit lässt sich der
                // Abschnitt live verfolgen, auch U-Bahn und Tram, die HAFAS
                // nicht kennt. Siehe trip().
                'dbJourneyId'  => isset($a['journeyId']) ? (string) $a['journeyId'] : null,
                'amenities'    => self::amenitiesOf($vm),
                'stationNotes' => self::stationNotesOf($a),
                'occupancy'    => $this->occupancyOf($a),
                'category'     => trim((string) ($vm['kategorie'] ?? '')),
                'categoryName' => trim((string) ($vm['produktGattung'] ?? '')),
                'line'         => self::lineOf($vm),
                'trainNumber'  => trim((string) ($vm['nummer'] ?? '')),
                'name'         => trim((string) ($vm['name'] ?? '')),
                'direction'    => trim((string) ($vm['richtung'] ?? '')),
                'operator'     => $this->operatorOf($vm),
                'from'          => $from,
                'to'            => $to,
                'stops'         => $stops,
                'departure'     => $dep,
                'departureReal' => $depReal,
                'arrival'       => $arr,
                'arrivalReal'   => $arrReal,
                // Die größere der beiden Abweichungen: an der Abfahrt sieht
                // man, ob der Zug schon spät dran ist, an der Ankunft, ob er
                // die Verspätung unterwegs noch aufholt.
                'delay'         => self::maxDelay([[$dep, $depReal], [$arr, $arrReal]]),
                'hasRealtime'   => $depReal !== null || $arrReal !== null,
                'remarks'       => $remarks,
                'durationMin'   => (int) round(((int) ($a['abschnittsDauer'] ?? 0)) / 60),
                'cancelled'     => (bool) ($a['originCancelled'] ?? false),
                'dTicket'       => $dTicket,
            ];
        }

        $price  = null;
        $betrag = $v['angebotsPreis']['betrag'] ?? null;
        if ($betrag !== null) {
            $price = [
                'amount'    => (float) $betrag,
                'currency'  => (string) ($v['angebotsPreis']['waehrung'] ?? 'EUR'),
                'type'      => 'Echtpreis',
                'source'    => 'db',
                'estimated' => false,
            ];
        }

        $first = $legs[0] ?? null;
        $last  = $legs[count($legs) - 1] ?? null;

        // Größte Verspätung über alle Abschnitte - das ist die Zahl, die
        // an der Verbindung interessiert.
        $delay = null;
        foreach ($legs as $l) {
            if (isset($l['delay']) && $l['delay'] !== null) {
                $delay = $delay === null ? $l['delay'] : max($delay, $l['delay']);
            }
        }

        return [
            'id'            => (string) ($v['tripId'] ?? md5((string) json_encode($legs))),
            'departure'     => $first['departure'] ?? null,
            'departureReal' => $first['departureReal'] ?? null,
            'arrival'       => $last['arrival'] ?? null,
            'arrivalReal'   => $last['arrivalReal'] ?? null,
            'delay'         => $delay,
            'durationMin' => (int) round(((int) ($v['verbindungsDauerInSeconds'] ?? 0)) / 60),
            'changes'     => (int) ($v['umstiegsAnzahl'] ?? max(0, count($legs) - 1)),
            'legs'        => $legs,
            'countries'   => array_keys($countries),
            'bookingUrl'  => null,
            'price'       => $price,
            'dTicket'     => $dTicketSegments,
            // Schlüssel für alle Tarife dieser Verbindung, siehe offers().
            'dbRecon'     => isset($v['ctxRecon']) ? (string) $v['ctxRecon'] : null,
            'source'      => 'db',
        ];
    }

    /**
     * Ausstattung eines Zuges, siehe AMENITIES.
     *
     * @return array<int,array{key:string,label:string,important:bool}>
     */
    public static function amenitiesOf(array $vm): array
    {
        $out = [];
        foreach (($vm['zugattribute'] ?? []) as $z) {
            $def = self::AMENITIES[(string) ($z['key'] ?? '')] ?? null;
            if ($def === null) {
                continue;
            }
            [$key, $label, $important] = $def;
            $label ??= trim((string) ($z['value'] ?? ''));
            if ($label === '') {
                continue;
            }
            // Je Sorte eine Angabe. Die DB meldet am selben ICE "Fahrradmitnahme
            // begrenzt möglich" UND "reservierungspflichtig" - die zweite ist
            // die, nach der man sich richten muss.
            if (isset($out[$key]) && ($out[$key]['important'] || !$important)) {
                continue;
            }
            $out[$key] = ['key' => $key, 'label' => $label, 'important' => $important];
        }
        return array_values($out);
    }

    /**
     * Meldungen zur Barrierefreiheit am Ein- und Ausstieg eines Abschnitts.
     *
     * Die DB beginnt solche Meldungen mit dem Bahnhofsnamen ("Hamburg Hbf:
     * Aufgrund einer Aufzugserneuerung Gleis 13/14 ..."). Daran wird sie dem
     * Einstieg (`from`) oder dem Ausstieg (`to`) zugeordnet; was an einem
     * Zwischenhalt gilt, an dem man sitzen bleibt, fällt weg.
     *
     * @return array<int,array{at:string,text:string}>
     */
    public static function stationNotesOf(array $abschnitt): array
    {
        $orte = [
            'from' => trim((string) ($abschnitt['abfahrtsOrt'] ?? '')),
            'to'   => trim((string) ($abschnitt['ankunftsOrt'] ?? '')),
        ];
        $out = [];
        foreach (($abschnitt['himMeldungen'] ?? []) as $m) {
            $text = Text::plain((string) ($m['text'] ?? ''));
            if ($text === '' || !preg_match(self::ACCESS_PATTERN, $text)) {
                continue;
            }
            foreach ($orte as $at => $name) {
                if ($name === '' || !str_starts_with($text, $name . ':')) {
                    continue;
                }
                $rest = trim(mb_substr($text, mb_strlen($name) + 1));
                $out[$at . $rest] = ['at' => $at, 'text' => $rest];
            }
        }
        return array_values($out);
    }

    /**
     * DB-Zeitstempel in vollständiges ISO-8601 mit Zonenangabe umwandeln.
     *
     * WARUM DAS NOETIG IST: Die DB liefert "2026-08-22T00:47:00" - deutsche
     * Ortszeit OHNE Offset. Die ÖBB liefert "2026-08-22T00:47:00+02:00".
     * Ohne Normalisierung interpretiert PHP den DB-Wert in der Zeitzone des
     * Servers. Auf einem deutschen Webspace fällt das nicht auf, auf einem
     * UTC-Server liegen beide Quellen zwei Stunden auseinander - der Abgleich
     * über Ab- und Ankunftszeit findet dann gar nichts mehr oder, schlimmer,
     * das Falsche. Auch Fares.php und das Frontend rechnen mit dem Offset.
     *
     * Werte, die bereits eine Zone tragen, bleiben unangetastet.
     */
    private static function iso(?string $local): ?string
    {
        if ($local === null || $local === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $local)) {
            return $local;
        }
        try {
            return (new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin')))
                ->format(DateTimeInterface::ATOM);
        } catch (Exception $e) {
            return $local;
        }
    }

    /**
     * Größte Verspätung in Minuten aus Paaren von Soll- und Ist-Zeit.
     *
     * @param array<int,array{0:?string,1:?string}> $pairs
     */
    private static function maxDelay(array $pairs): ?int
    {
        $out = null;
        foreach ($pairs as [$plan, $real]) {
            if ($plan === null || $real === null) {
                continue;
            }
            try {
                $d = (int) round(
                    ((new DateTimeImmutable($real))->getTimestamp()
                        - (new DateTimeImmutable($plan))->getTimestamp()) / 60
                );
            } catch (Exception $e) {
                continue;
            }
            $out = $out === null ? $d : max($out, $d);
        }
        return $out;
    }

    /**
     * Auslastung je Klasse, wie sie die DB meldet.
     *
     * Die Stufen sind: 0 unbekannt, 1 gering, 2 mittel, 3 hoch,
     * 4 Zug ausgebucht. Wir geben beide Klassen zurück, damit man in der
     * Anzeige die passende wählen kann.
     *
     * @return array{first:?int,second:?int}|null
     */
    private function occupancyOf(array $abschnitt): ?array
    {
        $out = ['first' => null, 'second' => null];
        $any = false;

        foreach (($abschnitt['auslastungsmeldungen'] ?? []) as $m) {
            $stufe = $m['stufe'] ?? null;
            if (!is_int($stufe) || $stufe <= 0) {
                continue;
            }
            $any = true;
            if (($m['klasse'] ?? '') === 'KLASSE_1') {
                $out['first'] = $stufe;
            } elseif (($m['klasse'] ?? '') === 'KLASSE_2') {
                $out['second'] = $stufe;
            }
        }

        return $any ? $out : null;
    }

    /**
     * Ist dieser Abschnitt ein Fußweg?
     *
     * Verlassen kann man sich auf das Feld 'typ' nicht: im Nahverkehr fehlt es
     * regelmäßig komplett, und dann würde ein Fußweg als Fahrzeug ohne
     * Gattung durchgehen und im Frontend als "Unbekannt" erscheinen.
     * Zuverlässiger ist das Verkehrsmittel selbst - ein Fußweg hat weder
     * Gattung noch Liniennummer und heißt schlicht "Fußweg".
     */
    private function isWalk(array $abschnitt, array $vm): bool
    {
        $typ = strtoupper((string) ($abschnitt['typ'] ?? ''));
        if ($typ === 'FUSSWEG' || $typ === 'TRANSFER') {
            return true;
        }

        $name = (string) ($vm['name'] ?? '');
        if (preg_match('/fu(ss|ß)weg|umstieg|transfer|zu\s+fu(ss|ß)/iu', $name) === 1) {
            return true;
        }

        // Weder Gattung noch Nummer: da fährt nichts.
        $kat = trim((string) ($vm['kategorie'] ?? ''));
        $nr  = trim((string) ($vm['nummer'] ?? ''));
        $gat = trim((string) ($vm['produktGattung'] ?? ''));

        return $kat === '' && $nr === '' && $gat === '';
    }

    /**
     * Die Linienbezeichnung, wie sie am Bahnsteig steht.
     *
     * 'linienNummer' ist im Nahverkehr regelmäßig LEER - bei allen Zügen,
     * die nicht die DB selbst fährt, ist sie es praktisch immer. Die Linie
     * steht dann nur im 'mittelText': die HLB-Regionalbahn Frankfurt-Gießen
     * liefert kategorie=DRB, linienNummer=null, mittelText="RB37". Ohne
     * diesen Rückgriff blieb von dem Zug nur "DRB 24628" übrig - eine
     * Betriebsnummer, die auf keiner Anzeigetafel steht.
     *
     * Im Fernverkehr ist 'mittelText' dagegen die Zugnummer mit Gattung davor
     * ("ICE 2374"). Das ist keine Linie, und als solche ausgegeben würde sie
     * die Zuordnung gleichnamiger Züge durcheinanderbringen - deshalb fällt
     * alles heraus, was die Zugnummer enthält.
     */
    private static function lineOf(array $vm): string
    {
        $linie = trim((string) ($vm['linienNummer'] ?? ''));
        if ($linie !== '') {
            return $linie;
        }

        $mittel = trim((string) ($vm['mittelText'] ?? ''));
        $nummer = trim((string) ($vm['nummer'] ?? ''));
        if ($mittel === '' || ($nummer !== '' && str_contains($mittel, $nummer))) {
            return '';
        }

        return $mittel;
    }

    private function operatorOf(array $vm): string
    {
        foreach (($vm['zugattribute'] ?? []) as $z) {
            if (($z['key'] ?? '') === 'BEF') {
                return trim((string) ($z['value'] ?? ''));
            }
        }
        return '';
    }

    /**
     * Zieht die Koordinaten aus einer DB-Location-ID.
     *
     * Die IDs sehen so aus:
     *   A=1@O=Zürich HB@X=8540211@Y=47378177@U=80@L=8503000@
     * X ist die Länge, Y die Breite, beide mal 1e6. Ein eigenes Feld dafür
     * gibt es in den Halten nicht - deshalb dieser Weg.
     *
     * @return array{lat:?float,lon:?float}
     */
    private function coordsFromId(string $id): array
    {
        $lat = null;
        $lon = null;
        if (preg_match('/@Y=(-?\d+)@/', $id, $m)) {
            $lat = ((int) $m[1]) / 1000000;
        }
        if (preg_match('/@X=(-?\d+)@/', $id, $m)) {
            $lon = ((int) $m[1]) / 1000000;
        }
        return ['lat' => $lat, 'lon' => $lon];
    }

    private function countryFromEva(string $eva): string
    {
        if (strlen($eva) < 2) {
            return '';
        }
        return self::EVA_COUNTRY[substr($eva, 0, 2)] ?? '';
    }

    /**
     * @param string[] $discounts
     * @return array{payload:array,applied:string[]}
     */
    private function mapDiscounts(array $discounts, int $travelClass): array
    {
        $payload = [];
        $applied = [];

        foreach ($discounts as $d) {
            if (!isset(self::DISCOUNT_MAP[$d])) {
                continue; // CH-/AT-Abos rechnet Fares.php, die DB kennt sie nicht
            }
            $payload[] = [
                'art'    => self::DISCOUNT_MAP[$d],
                'klasse' => $travelClass === 1 ? 'KLASSE_1' : 'KLASSE_2',
            ];
            $applied[] = $d;
        }

        if ($payload === []) {
            $payload[] = ['art' => 'KEINE_ERMAESSIGUNG', 'klasse' => 'KLASSENLOS'];
        }

        return ['payload' => $payload, 'applied' => $applied];
    }

    private function toDbLocationId(string $id): string
    {
        return str_contains($id, '@') ? $id : 'A=1@L=' . $id . '@';
    }

    private function detectBlock(array $res): ?string
    {
        if ($res['status'] === 403 || str_contains($res['body'], 'OPS_BLOCKED')) {
            return 'DB blockt die Anfrage (OPS_BLOCKED). Meist fehlt die Browser-TLS-Cipher-Reihenfolge '
                 . '- prüf, ob cURL auf diesem Server TLS 1.3 unterstützt.';
        }
        if ($res['status'] === 429) {
            return 'DB drosselt die Anfragen (429). Bitte später erneut versuchen.';
        }
        return null;
    }

    /** @return array<string,string> */
    private function browserHeaders(): array
    {
        return [
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            'Referer'         => 'https://int.bahn.de/de/buchung/start',
            'User-Agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ];
    }
}
