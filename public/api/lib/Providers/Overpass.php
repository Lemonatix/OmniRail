<?php
/**
 * Bahnsteige aus OpenStreetMap (Overpass API).
 *
 * WOZU: Bei einem Umstieg von vier Minuten ist die entscheidende Frage nicht
 * "welches Gleis", sondern "wo liegt es". Die Fahrplanquellen nennen nur die
 * Gleisnummer; wo dieses Gleis liegt, wissen sie nicht.
 *
 * OSM weiß es: Bahnsteige sind als `railway=platform` erfasst, mit
 * Gleisnummer (`ref` bzw. `local_ref`), Koordinaten und oft auch Ebene
 * (`level`). Daraus wird ein maßstäblicher Lageplan.
 *
 * NICHT MEHR DABEI: die Fußwege. Aus ihnen wurde einmal der genaue Laufweg
 * gerechnet - siehe stationData(). Sie waren der größte Teil der Antwort.
 *
 * FAIRER UMGANG: Overpass ist ein kostenlos betriebener Gemeinschaftsdienst.
 * Bahnsteige bewegen sich nicht, deshalb wird sehr lange gecacht und der
 * Radius klein gehalten.
 */
final class Overpass
{
    /**
     * Suchradius um den Bahnhofsmittelpunkt.
     *
     * Große Bahnhöfe messen gut 400 m in der Länge, gemessen ab der Mitte
     * also gut 200 m. 350 m fasst auch weit vorgelagerte Bahnsteige, holt
     * aber noch nicht die Tramhaltestelle zwei Straßen weiter mit herein.
     */
    private const RADIUS_M = 350;

    /**
     * Wie viele Nummern höchstens fehlen dürfen, damit ergänzt wird.
     * Darüber ist es keine Erfassungslücke mehr, sondern ein eigener
     * Bahnhofsteil mit eigener Nummerierung.
     */
    private const MAX_GAP = 3;

    /**
     * Abstand zweier benachbarter Gleisnummern, grob. Zwei Gleisachsen mit
     * einem Bahnsteig dazwischen sind gut zehn Meter auseinander; fünfzehn
     * lassen Luft für breitere Bahnsteige, ohne einen ganzen Bahnhofsteil
     * einzufangen.
     */
    private const TRACK_SPACING_M = 15.0;

    /**
     * Umkreis für Treppen, Rolltreppen und Aufzüge. Kleiner als der für
     * Bahnsteige: gefragt ist, was im Bahnhof von Ebene zu Ebene führt, nicht
     * die Treppe zur Straßenbahn zwei Straßen weiter.
     */
    private const CONNECTOR_RADIUS_M = 250;

    /** Mehr zeichnet auch Zürich HB nicht sinnvoll in einen Ausschnitt. */
    private const MAX_CONNECTORS = 250;

    private Http $http;
    private array $cfg;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->cfg  = $cfg;
    }

    /**
     * Eine Abfrage an die erste Instanz, die antwortet.
     *
     * Jede bekommt nur einen Teil des Gesamtbudgets: eine tote Instanz soll
     * nicht die ganze Zeit fressen. Vorher wartete eine Anfrage zweimal
     * fünfzig Sekunden und gab dann auf - der Umstiegsplan blieb weg,
     * obwohl eine dritte Instanz sofort geantwortet hätte.
     *
     * Als Erfolg zählt nur eine Antwort, die sich als JSON lesen lässt.
     * Überlastete Instanzen schicken eine HTML-Fehlerseite mit Status 200;
     * ohne diese Prüfung wäre das ein Bahnhof ohne Bahnsteige.
     *
     * @return ?array Overpass-Antwort als Array, oder null
     */
    private function ask(string $query, ?string &$error = null): ?array
    {
        $endpoints = self::endpoints($this->cfg);
        if ($endpoints === []) {
            $error = 'Overpass: keine Instanz konfiguriert';
            return null;
        }

        $budget = max(20, (int) ($this->cfg['timeout'] ?? 60));
        $jeInstanz = max(12, (int) floor($budget / count($endpoints)));

        $letzter = 0;
        foreach ($endpoints as $url) {
            $res = (new Http($jeInstanz))->request(
                'POST',
                rtrim($url, '/'),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => (string) ($this->cfg['user_agent'] ?? 'train-maxxing'),
                ],
                'data=' . rawurlencode($query)
            );
            if ($res['ok'] && is_array($res['json'])) {
                return $res['json'];
            }
            $letzter = (int) $res['status'];
        }

        $error = 'Overpass: HTTP ' . $letzter;
        return null;
    }

    /**
     * Die konfigurierten Instanzen, in der Reihenfolge des Versuchs.
     *
     * @return string[]
     */
    public static function endpoints(array $cfg): array
    {
        $liste = $cfg['endpoints'] ?? null;
        if (!is_array($liste)) {
            // Ältere Konfiguration mit einzelnem Endpunkt und Fallback.
            $liste = [$cfg['endpoint'] ?? '', $cfg['fallback'] ?? ''];
        }
        return array_values(array_filter(array_map('strval', $liste)));
    }

    /**
     * Bahnsteige rund um einen Punkt.
     *
     * @return array{ok:bool,error:?string,data:array}
     */
    public function platforms(float $lat, float $lon): array
    {
        $res = $this->stationData($lat, $lon);
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'data' => $res['data']['platforms']]
            : $res;
    }

    /**
     * Die nummerierten Bahnsteige eines Bahnhofs.
     *
     * Grundlage des Umstiegsplans: er zeigt, wo Ankunfts- und Abfahrtsgleis
     * liegen. Wie man dazwischen läuft, sagt er bewusst nicht - siehe die
     * Anmerkung zur Abfrage weiter unten.
     *
     * @return array{ok:bool,error:?string,data:array{platforms:array,trackPoints:array}}
     */
    public function stationData(float $lat, float $lon): array
    {
        // Drei Erfassungsarten, weil OSM uneinheitlich taggt und jede
        // einzelne für sich ganze Bahnhöfe verschluckt:
        //
        //   railway=platform / public_transport=platform als NODE oder WAY
        //       München Hbf führt seine Bahnsteige so.
        //
        //   dieselben Tags als RELATION (multipolygon)
        //       Frankfurt Hbf, Würzburg Hbf, Stuttgart Hbf und Zürich HB
        //       kartieren ihre Bahnsteige ausschließlich so. Ohne diese
        //       Zeile lieferte Frankfurt null und Zürich einen einzigen
        //       nummerierten Bahnsteig - der Lageplan entfiel deshalb dort
        //       immer, obwohl die Daten längst in OSM stehen.
        //
        //   public_transport=stop_position mit local_ref
        //       Die Rückfallebene für Bahnhöfe, deren Bahnsteigflächen
        //       gar keine Nummer tragen. Zürich HB hat 26 solcher Punkte
        //       (Gleis 3-18, 31-34, 41-44). ACHTUNG: bei diesen Knoten steht
        //       in `ref` die Nummer des BAHNHOFS (Zürich: 13030), die
        //       Gleisnummer nur in `local_ref` - siehe unten.
        //
        // Kein train=yes als Bedingung: OSM taggt es bei Bahnsteigen oft gar
        // nicht (Würzburg Hbf lieferte damit null statt vierzehn). Tram-,
        // Bus- und U-Bahnsteige fliegen weiter unten raus, anhand der Tags,
        // die sie tatsächlich tragen.
        //
        // KEINE FUSSWEGE MEHR: früher holte diese Abfrage zusätzlich alle
        // Wege mit highway=footway|steps|corridor|pedestrian|elevator, um
        // daraus den genauen Laufweg zwischen zwei Bahnsteigen zu berechnen.
        // Das ist entfallen - der Plan zeigt jetzt nur noch, WO die beiden
        // Bahnsteige liegen (siehe handlePlatforms()). Die Fußwege waren der
        // größte Teil der Antwort; ohne sie ist die Abfrage deutlich
        // kleiner, und der Gemeinschaftsdienst Overpass dankt es.
        //
        // `out tags center` statt `out geom`: der Plan zeichnet je Gleis
        // einen Punkt, Umrisse braucht davon niemand mehr. Das spart ein
        // Drittel der Antwort (Ulm 84 → 57 KB, Frankfurt 89 → 55 KB) und
        // räumt zugleich einen Sonderfall weg - siehe unten bei der
        // Auswertung.
        //
        // EINFACHE ANFUEHRUNGSZEICHEN, und zwar zwingend: in einem
        // doppelt gequoteten String liest PHP `%1$d` als "%1" gefolgt von der
        // Variablen $d. Die war nie gesetzt, sprintf bekam "%1," zu sehen und
        // warf "Unknown format specifier". Die Abfrage kam damit gar nicht
        // erst zustande - jeder Bahnhof ohne Cache-Eintrag meldete "keine
        // Bahnsteige erfasst", obwohl die Daten in OSM stehen.
        //
        // TREPPEN, ROLLTREPPEN, AUFZÜGE kommen in derselben Abfrage mit, als
        // zweiter Block mit eigenem `out`. Sie sind keine Wegberechnung - die
        // bleibt aus den oben genannten Gründen draußen -, sondern Tatsachen:
        // hier geht es eine Ebene hinunter. In OSM sind sie an großen
        // Bahnhöfen fast vollständig und mit Ebene erfasst (nachgezählt:
        // Zürich HB 81 Rolltreppen, alle mit `level`; München Hbf 63;
        // Mannheim 14). Für die Rolltreppen braucht es die Geometrie, sonst
        // gibt es keine Fahrtrichtung - deshalb `out tags geom`, nur für sie.
        $r = self::RADIUS_M;
        $c = self::CONNECTOR_RADIUS_M;
        $query = sprintf(
            '[out:json][timeout:40];('
            . 'node(around:%1$d,%2$.6f,%3$.6f)["railway"="platform"];'
            . 'way(around:%1$d,%2$.6f,%3$.6f)["railway"="platform"];'
            . 'relation(around:%1$d,%2$.6f,%3$.6f)["railway"="platform"];'
            . 'node(around:%1$d,%2$.6f,%3$.6f)["public_transport"="platform"];'
            . 'way(around:%1$d,%2$.6f,%3$.6f)["public_transport"="platform"];'
            . 'relation(around:%1$d,%2$.6f,%3$.6f)["public_transport"="platform"];'
            . 'node(around:%1$d,%2$.6f,%3$.6f)["public_transport"="stop_position"];'
            . 'node(around:%1$d,%2$.6f,%3$.6f)["railway"="stop"];'
            . ');out tags center;('
            . 'node(around:%4$d,%2$.6f,%3$.6f)["highway"="elevator"];'
            . 'way(around:%4$d,%2$.6f,%3$.6f)["highway"="steps"]["level"];'
            . 'way(around:%4$d,%2$.6f,%3$.6f)["conveying"];'
            . ');out tags geom;',
            $r, $lat, $lon, $c
        );

        $antwort = $this->ask($query, $fehler);
        if ($antwort === null) {
            return ['ok' => false, 'error' => $fehler, 'data' => []];
        }

        // Die Verbinder zwischen den Ebenen gehen ihren eigenen Weg - in der
        // Bahnsteig-Auswertung darunter hätten sie nichts zu suchen.
        $elements   = [];
        $connectors = [];
        foreach ($antwort['elements'] ?? [] as $e) {
            $k = self::connector($e);
            if ($k !== null) {
                $connectors[] = $k;
            } elseif (!self::isConnectorTagged($e['tags'] ?? [])) {
                $elements[] = $e;
            }
        }

        $out    = [];
        $seen   = [];
        $punkte = [];   // Gleisnummer -> [lat, lon] aus den Haltepunkten
        $stationsnummern = self::stationCodes($elements);

        foreach ($elements as $e) {
            $tags = $e['tags'] ?? [];

            // Mittelpunkt und - bei Wegen - der Umriss. Der Umriss macht aus
            // der Punktwolke einen Bahnhofsplan.
            // NUR DER MITTELPUNKT. Der Plan zeichnet je Gleis einen Punkt,
            // keine Flächen - Umrisse braucht davon niemand mehr. `out tags
            // center` liefert ihn für Knoten, Wege UND Relationen gleicher-
            // massen, und die Antwort ist dabei ein Drittel kleiner
            // (nachgemessen Ulm 84 → 57 KB, Frankfurt 89 → 55 KB).
            //
            // Das räumt nebenbei einen Sonderfall weg: mit `out geom` tragen
            // Relationen ihre Geometrie in den Mitgliedern, nicht am Objekt -
            // wer das übersieht, verliert sie stumm. Genau das ist hier
            // passiert und hat Friedrichshafen drei von vier Bahnsteigen
            // gekostet. `center` gibt es an jedem Objekt.
            $plat = ['lat' => null, 'lon' => null];
            if (isset($e['lat'], $e['lon'])) {
                $plat = ['lat' => (float) $e['lat'], 'lon' => (float) $e['lon']];
            } elseif (isset($e['center']['lat'], $e['center']['lon'])) {
                $plat = ['lat' => (float) $e['center']['lat'], 'lon' => (float) $e['center']['lon']];
            }
            if ($plat['lat'] === null) {
                continue;
            }

            // Handelt es sich um eine Bahnsteigfläche oder nur um einen
            // Haltepunkt auf dem Gleis? Davon hängt ab, in welchem Tag die
            // Gleisnummer steht - und wie brauchbar die Lage ist.
            $isStopNode = ($tags['public_transport'] ?? '') === 'stop_position'
                || ($tags['railway'] ?? '') === 'stop';

            // Ein Bahnsteig bedient oft zwei Gleise ("24;25"). Beide sollen
            // sich später über ihre Nummer wiederfinden lassen.
            //
            // WELCHES TAG die Nummer trägt, ist von Bahnhof zu Bahnhof
            // verschieden, und beide Lesarten kommen vor:
            //
            //   Mannheim Hbf  ref=1..12,  local_ref fehlt
            //   Zürich HB    local_ref=3..44,  ref=13030 - die Nummer des
            //                 BAHNHOFS im Netz der SBB, auf jedem Knoten
            //                 dieselbe
            //
            // Erst local_ref, dann ref - und was wie eine Stationsnummer
            // aussieht, fällt vorher raus (siehe stationCodes()). Nur
            // local_ref zu nehmen ließ Mannheim mit einem einzigen
            // Bahnsteig dastehen; nur ref zu nehmen machte aus Zürich
            // "Gleis 13030".
            $ref = trim((string) ($tags['local_ref'] ?? ''));
            if ($ref === '') {
                $ref = trim((string) ($tags['ref'] ?? ''));
            }
            $tracks = $ref === ''
                ? []
                : array_values(array_filter(array_map(
                    [self::class, 'cleanRef'],
                    preg_split('/[;,]/', $ref)
                )));

            // Vierstellige "Gleisnummern" gibt es nicht, und was an mehreren
            // Haltepunkten desselben Bahnhofs gleich lautet, ist die Nummer
            // des Bahnhofs. Beides würde nur eine Zuordnung vortäuschen.
            $tracks = array_values(array_filter(
                $tracks,
                static fn(string $t): bool => !preg_match('/^\d{4,}$/', $t)
                    && !isset($stationsnummern[$t])
            ));

            if ($tracks === []) {
                continue; // ohne Gleisnummer nicht zuzuordnen
            }

            // ABSCHNITTE: Manche Bahnhöfe sind in OSM je Bahnsteigabschnitt
            // erfasst - Ulm Hbf führt "4 Nord", "4 Süd", "5a", "5b" und
            // kein einziges nacktes "4". Der Fahrplan sagt aber "Gleis 4",
            // und die Suche nach dieser Nummer ging deshalb an einem
            // vollständig kartierten Bahnhof leer aus.
            //
            // Die bloße Nummer kommt als ZWEITNAME dazu, nicht als
            // gleichwertige. Wo es ein echtes "4" gibt, soll das gewinnen -
            // siehe preferBestSource().
            $alt = [];
            foreach ($tracks as $t) {
                if (preg_match('/^(\d+)[ ]?[\p{L}]+$/u', $t, $m) && !in_array($m[1], $tracks, true)) {
                    $alt[$m[1]] = true;
                }
            }

            // Tram, U-Bahn und Bus haben eigene, unabhängige Gleis- bzw.
            // Steignummern. Ohne diesen Filter wäre der "Steig 1" einer
            // Tramhaltestelle nicht vom Gleis 1 des Bahnhofs zu unterscheiden.
            //
            // Ausgeschlossen wird nur, was sich AUSDRUECKLICH als Nicht-Bahn
            // ausweist: ein fehlendes train-Tag heißt bei Bahnsteigen in der
            // Regel nur, dass es niemand eingetragen hat.
            //
            // Für HALTEPUNKTE gilt das Gegenteil: sie liegen auf einem Gleis
            // und müssen sagen, auf was für einem. Mannheim Hbf hat neben
            // den zwölf Bahngleisen die Bussteige "Steig E" bis "Steig G"
            // als stop_position ohne jedes Modus-Tag - ohne diese Bedingung
            // stünden sie als Gleise im Plan.
            if ($isStopNode
                && ($tags['train'] ?? '') !== 'yes'
                && ($tags['railway'] ?? '') !== 'stop') {
                continue;
            }

            if (($tags['train'] ?? '') !== 'yes') {
                foreach (['tram', 'subway', 'bus', 'light_rail', 'monorail'] as $other) {
                    if (($tags[$other] ?? '') === 'yes') {
                        continue 2;
                    }
                }
                if (($tags['highway'] ?? '') === 'platform') {
                    continue; // reine Bussteige
                }
            }

            // DER PUNKT AUF DEM GLEIS. Ein Haltepunkt liegt auf dem Gleis
            // selbst, eine Bahnsteigfläche daneben - und bei einem Bahnsteig
            // zwischen zwei Gleisen liegt ihr Schwerpunkt genau zwischen
            // beiden. Für die Frage "wo hält mein Zug" ist der Punkt deshalb
            // die bessere Auskunft, und an Bahnhöfen mit Bahnsteigabschnitten
            // ("4 Nord", "4 Süd") sogar die einzig brauchbare: die Fläche zu
            // "Gleis 4" ist dort willkürlich die eine oder die andere Hälfte.
            //
            // Gesammelt wird getrennt von den Flächen, weil preferBestSource()
            // die Haltepunkte gleich verwirft, sobald es eine Fläche gibt.
            if ($isStopNode) {
                foreach (array_merge($tracks, array_keys($alt)) as $t) {
                    $punkte[(string) $t] ??= [$plat['lat'], $plat['lon']];
                }
            }

            // Manche Bahnsteige tragen beide Tags und kämen sonst doppelt.
            $dedupe = implode('/', $tracks) . '@' . round($plat['lat'], 4) . ',' . round($plat['lon'], 4);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            // Güte der Quelle, für die Auswahl weiter unten. Eine
            // Bahnsteigfläche (Weg oder Relation) ist die verlässlichere
            // Angabe als ein einzelner Knoten, und ein Haltepunkt auf dem
            // Gleis die schwächste - ihn benutzen wir ohnehin getrennt,
            // nämlich als Punkt je Gleis (siehe $punkte).
            $rank = ($e['type'] ?? '') !== 'node' ? 0 : ($isStopNode ? 2 : 1);

            $out[] = [
                'tracks' => $tracks,
                'name'   => trim((string) ($tags['name'] ?? '')),
                'lat'    => $plat['lat'],
                'lon'    => $plat['lon'],
                // Ebene als Zahl, wenn OSM eine nennt. Ein Wechsel zwischen
                // Ebenen kostet deutlich mehr Zeit als die Luftlinie sagt.
                'level'  => isset($tags['level']) && is_numeric($tags['level'])
                    ? (float) $tags['level']
                    : null,
                '_rank'  => $rank,
                '_alt'   => array_keys($alt),
            ];
        }

        $platforms = self::preferBestSource($out);
        $platforms = self::fillNumberGaps($platforms);

        return [
            'ok'    => true,
            'error' => null,
            'data'  => [
                'platforms'   => $platforms,
                'trackPoints' => $punkte,
                'connectors'  => array_slice($connectors, 0, self::MAX_CONNECTORS),
            ],
        ];
    }

    /** Ist das ein Aufzug, eine Treppe oder eine Rolltreppe? */
    private static function isConnectorTagged(array $tags): bool
    {
        $hw = (string) ($tags['highway'] ?? '');
        return $hw === 'elevator' || $hw === 'steps' || isset($tags['conveying']);
    }

    /**
     * Ein Verbinder zwischen Ebenen im Format der App - oder null.
     *
     *   type    elevator | escalator | steps
     *   levels  alle Ebenen, die er berührt, als Zahlen
     *   line    bei Treppen und Rolltreppen der Verlauf, [[lat, lon], …]
     *   pos     Mittelpunkt
     *   dir     Rolltreppe: forward (in Zeichenrichtung), backward, both
     *
     * Ohne Ebene taugt er nicht: dann weiß man nicht, wohin er führt. Das
     * fängt nebenbei die Treppen im Straßenraum draußen vor dem Bahnhof ab,
     * die in OSM kaum je eine Ebene tragen.
     */
    private static function connector(array $e): ?array
    {
        $tags = $e['tags'] ?? [];
        if (!self::isConnectorTagged($tags)) {
            return null;
        }
        $levels = self::parseLevels((string) ($tags['level'] ?? ''));
        if ($levels === []) {
            $levels = self::parseLevels((string) ($tags['repeat_on'] ?? ''));
        }
        if ($levels === []) {
            return null;
        }

        $line = [];
        foreach ($e['geometry'] ?? [] as $pt) {
            if (isset($pt['lat'], $pt['lon'])) {
                $line[] = [round((float) $pt['lat'], 6), round((float) $pt['lon'], 6)];
            }
        }
        if (isset($e['lat'], $e['lon'])) {
            $pos = [round((float) $e['lat'], 6), round((float) $e['lon'], 6)];
        } elseif ($line !== []) {
            $pos = $line[intdiv(count($line), 2)];
        } else {
            return null;
        }

        $hw = (string) ($tags['highway'] ?? '');
        $type = $hw === 'elevator' ? 'elevator' : (isset($tags['conveying']) ? 'escalator' : 'steps');
        if ($type === 'steps' && !isset($tags['level'])) {
            return null;
        }

        $dir = null;
        if ($type === 'escalator') {
            $dir = match ((string) $tags['conveying']) {
                'forward', 'yes' => 'forward',
                'backward'       => 'backward',
                default          => 'both',     // reversible: fährt mal so, mal so
            };
        }

        return [
            'type'   => $type,
            'levels' => $levels,
            'line'   => count($line) > 1 ? [$line[0], $line[count($line) - 1]] : [],
            'pos'    => $pos,
            'dir'    => $dir,
            // Ein Aufzug ohne Rollstuhl-Angabe ist trotzdem ein Aufzug; die
            // Angabe "wheelchair=no" dagegen ist wichtig.
            'wheelchair' => isset($tags['wheelchair']) ? $tags['wheelchair'] !== 'no' : null,
        ];
    }

    /**
     * OSM-Ebenenangabe als Liste von Zahlen.
     *
     * Drei Schreibweisen kommen vor: einzeln ("-1"), aufgezählt ("0;-1")
     * und als Bereich ("-2--1", "0-2"). Halbe Ebenen ("0.5") für
     * Zwischengeschosse bleiben, wie sie sind.
     *
     * @return float[] absteigend sortiert
     */
    public static function parseLevels(string $raw): array
    {
        $out = [];
        foreach (preg_split('/;/', $raw) ?: [] as $teil) {
            $teil = trim($teil);
            if ($teil === '') {
                continue;
            }
            if (preg_match('/^(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)$/', $teil, $m)) {
                $a = (float) $m[1];
                $b = (float) $m[2];
                [$lo, $hi] = $a <= $b ? [$a, $b] : [$b, $a];
                if ($hi - $lo > 10) {
                    continue; // offensichtlich kein Ebenenbereich
                }
                for ($v = $lo; $v <= $hi + 1e-9; $v += 1) {
                    $out[(string) $v] = $v;
                }
                if (fmod($hi - $lo, 1.0) !== 0.0) {
                    $out[(string) $hi] = $hi;
                }
            } elseif (is_numeric($teil)) {
                $out[(string) (float) $teil] = (float) $teil;
            }
        }
        $out = array_values($out);
        rsort($out);
        return $out;
    }

    /**
     * Eine Gleisangabe aus OSM auf die nackte Nummer bringen.
     *
     * Manche Bahnhöfe schreiben das Wort mit hinein - `ref="Gleis 24"` statt
     * `ref="24"`. Der Fahrplan sagt aber schlicht "24", und die Suche ging
     * deshalb leer aus, während im Plan "Gleis Gleis 24" stand. Aufgefallen
     * ist es an einem Umstieg mit den Gleisen 21-24.
     *
     * Bewusst nur der Wortkopf, und nur wenn danach eine ZIFFER folgt: was
     * danach kommt, bleibt unangetastet ("4 Nord", "1a" behalten ihre Form),
     * und "Steig F" bleibt "Steig F". Mannheim führt seine Bussteige so, und
     * ein nacktes "F" wäre von einem Gleis nicht mehr zu unterscheiden.
     */
    private static function cleanRef(string $ref): string
    {
        $ref = trim($ref);
        $ohne = preg_replace(
            '/^(gleis|gl\.?|bahnsteig|bstg\.?|steig|track|voie|binario)\s+(?=\d)/iu',
            '',
            $ref
        );
        return trim($ohne ?? $ref);
    }

    /**
     * Einzelne fehlende Gleisnummern aus ihren Nachbarn ergänzen.
     *
     * WOZU: Mannheim Hbf hat in OSM die Gleise 1-5 und 7-12, aber kein 6 -
     * jemand hat es beim Erfassen ausgelassen. Fährt der Anschlusszug von
     * Gleis 6, entfiel deshalb der ganze Umstiegsplan, obwohl der Bahnhof
     * ringsum vollständig kartiert ist.
     *
     * WIE: Nur wo höchstens drei Nummern zwischen zwei vorhandenen fehlen
     * und die beiden Nachbarn entsprechend dicht beieinanderliegen. Gleis 6
     * liegt dann in der Mitte zwischen 5 und 7 - bei neun Metern Abstand ist
     * das kein Raten mehr, sondern die Bahnsteigkante dazwischen. Bei
     * größeren Lücken wird linear zwischen den Nachbarn geteilt.
     *
     * Drei und nicht eine, weil die Erhebung über dreißig Bahnhöfe zeigte,
     * dass Zweierlücken genauso häufig sind: Genf fehlen 8 und 9, Hamburg
     * und Berlin Hbf je 9 und 10, Nürnberg 10 und 11.
     *
     * WO NICHT: Zwischen Gleis 18 und 31 in Zürich klafft eine Lücke von
     * dreizehn Nummern und mehreren hundert Metern - das ist keine
     * Erfassungslücke, sondern ein zweiter Bahnhofsteil. Genauso Bern
     * (13 -> 21, RBS) und Basel SBB (20 -> 30, SNCF). Der Abstand entscheidet:
     * je fehlender Nummer sind knapp fünfzehn Meter zulässig, das ist eine
     * Gleisachse. Und was ergänzt wurde, sagt es über `estimated`, damit
     * die Anzeige es kenntlich machen kann.
     *
     * @param array<int,array> $platforms
     * @return array<int,array>
     */
    private static function fillNumberGaps(array $platforms): array
    {
        $nachNummer = [];
        foreach ($platforms as $p) {
            foreach ($p['tracks'] as $t) {
                if (preg_match('/^\d{1,3}$/', $t)) {
                    $nachNummer[(int) $t] ??= $p;
                }
            }
        }
        if (count($nachNummer) < 2) {
            return $platforms;
        }
        ksort($nachNummer);

        $nummern = array_keys($nachNummer);
        $neu = [];
        for ($i = 0, $n = count($nummern) - 1; $i < $n; $i++) {
            $lücke = $nummern[$i + 1] - $nummern[$i] - 1;
            if ($lücke < 1 || $lücke > self::MAX_GAP) {
                continue;
            }

            $a = $nachNummer[$nummern[$i]];
            $b = $nachNummer[$nummern[$i + 1]];
            $abstand = self::metres([$a['lat'], $a['lon']], [$b['lat'], $b['lon']]);
            if ($abstand > ($lücke + 1) * self::TRACK_SPACING_M) {
                continue;   // zu weit auseinander - das ist ein anderer Bahnhofsteil
            }

            // Linear teilen: bei einer Lücke von zwei liegen die beiden
            // fehlenden Gleise auf einem und zwei Dritteln der Strecke.
            for ($k = 1; $k <= $lücke; $k++) {
                $t = $k / ($lücke + 1);
                $neu[] = [
                    'tracks' => [(string) ($nummern[$i] + $k)],
                    'name'   => '',
                    'lat'    => $a['lat'] + ($b['lat'] - $a['lat']) * $t,
                    'lon'    => $a['lon'] + ($b['lon'] - $a['lon']) * $t,
                    'level'  => $a['level'] !== null && $a['level'] === $b['level'] ? $a['level'] : null,
                    'estimated' => true,
                ];
            }
        }

        return array_merge($platforms, $neu);
    }

    /** Entfernung zweier [lat, lon]-Punkte in Metern. */
    private static function metres(array $a, array $b): float
    {
        $lat = ($a[0] + $b[0]) / 2 * M_PI / 180;
        $dx = ($b[1] - $a[1]) * 111320 * cos($lat);
        $dy = ($b[0] - $a[0]) * 110540;
        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Werte, die keine Gleisnummer sein können, weil sie den ganzen
     * Bahnhof meinen.
     *
     * An Haltepunkten steht in `ref` mancherorts die Nummer des BAHNHOFS im
     * Netz des Betreibers - in Zürich HB die 13030, auf jedem der 26
     * Haltepunkte dieselbe. Ein Gleis hat höchstens zwei Haltepunkte, einen
     * je Richtung. Was auf DREI oder mehr auftaucht, ist deshalb keine
     * Gleisnummer.
     *
     * @param array<int,array> $elements Overpass-Rohdaten
     * @return array<string,true> verdächtige Werte als Schlüssel
     */
    private static function stationCodes(array $elements): array
    {
        $zähler = [];
        foreach ($elements as $e) {
            $tags = $e['tags'] ?? [];
            $istHalt = ($tags['public_transport'] ?? '') === 'stop_position'
                || ($tags['railway'] ?? '') === 'stop';
            if (!$istHalt) {
                continue;
            }

            // NUR BAHNHALTE ZÄHLEN. Ein Bussteig trägt eine Steignummer, die
            // mit der Nummer des Bahnhofs nichts zu tun hat - und an einem
            // grossen Busbahnhof kommt dieselbe Nummer leicht dreimal vor,
            // einmal als Haltepunkt und zweimal als Steig.
            //
            // München-Pasing hat genau daran das Gleis 10 verloren: die "10"
            // steht dort auf drei Bus-Haltepunkten, damit galt sie als
            // Bahnhofsnummer und flog aus JEDEM Bahnsteig heraus - auch aus
            // der Relation "9;10", die OSM sauber führt.
            $istBahn = ($tags['railway'] ?? '') === 'stop' || ($tags['train'] ?? '') === 'yes';
            if (!$istBahn) {
                continue;
            }
            $ref = trim((string) ($tags['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            foreach (preg_split('/[;,]/', $ref) as $teil) {
                $teil = trim($teil);
                if ($teil !== '') {
                    $zähler[$teil] = ($zähler[$teil] ?? 0) + 1;
                }
            }
        }

        return array_filter($zähler, static fn(int $n): bool => $n >= 3);
    }

    /**
     * Je Gleisnummer nur die beste Quelle behalten.
     *
     * Große Bahnhöfe sind in OSM oft doppelt erfasst: die Bahnsteigfläche
     * als Weg oder Relation UND ein Haltepunkt auf dem Gleis, beide mit
     * derselben Nummer. Blieben beide stehen, fiele die Wahl beim Zeichnen
     * willkürlich - mal die maßstäbliche Fläche, mal der Punkt daneben,
     * je nachdem, was Overpass zuerst ausgibt.
     *
     * Deshalb: ein Eintrag fällt weg, sobald ALLE seine Gleise schon von
     * einer besseren Quelle abgedeckt sind. Deckt er auch nur ein Gleis ab,
     * das sonst fehlen würde, bleibt er - lieber ein grober Punkt als eine
     * Lücke im Plan.
     *
     * @param array<int,array> $platforms
     * @return array<int,array>
     */
    private static function preferBestSource(array $platforms): array
    {
        usort($platforms, static fn(array $a, array $b): int => $a['_rank'] <=> $b['_rank']);

        $covered = [];
        $out = [];
        foreach ($platforms as $p) {
            $neu = false;
            foreach ($p['tracks'] as $t) {
                if (!isset($covered[$t])) {
                    $neu = true;
                }
            }
            if (!$neu) {
                continue;
            }
            foreach ($p['tracks'] as $t) {
                $covered[$t] = true;
            }
            $out[] = $p;
        }

        // Erst jetzt die Abschnittsnamen auf ihre nackte Nummer abbilden -
        // nach allen echten Nummern, damit ein vorhandenes "4" immer gewinnt
        // und "4 Nord" nur einspringt, wo keins existiert.
        foreach ($out as $i => $p) {
            foreach ($p['_alt'] as $t) {
                if (!isset($covered[$t])) {
                    $covered[$t] = true;
                    // Als Zeichenkette: PHP macht aus dem Array-Schlüssel "4"
                    // eine Zahl, und in der Liste stünden dann Text und Zahl
                    // gemischt.
                    $out[$i]['tracks'][] = (string) $t;
                }
            }
        }

        foreach ($out as $i => $p) {
            unset($out[$i]['_rank'], $out[$i]['_alt']);
        }
        return array_values($out);
    }
}
