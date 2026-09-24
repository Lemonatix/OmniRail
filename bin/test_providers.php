<?php
/**
 * Die Übersetzer der Fremdquellen prüfen - mit aufgezeichneten Antworten.
 *
 *   php bin/test_providers.php
 *
 * WOZU: Die Anbindungen an HAFAS, DB und MVG sind der zerbrechliche Teil der
 * App. Die Formate gehören anderen und ändern sich ohne Ankündigung, und ein
 * Fehler im Übersetzen fällt nicht als Absturz auf, sondern als falscher
 * Text: "U" statt "U5", ein Ausfall, der nicht als Ausfall ankommt, ein
 * Sparpreis, der als Flexpreis dasteht. Die Antworten unter bin/fixtures
 * sind echte, gekürzte Antworten der Dienste (aufgezeichnet 2026-09);
 * geprüft wird, was die App daraus macht.
 *
 * Kein Netz, keine Abhängigkeiten - läuft mit jedem PHP ab 8.1.
 */

declare(strict_types=1);

$lib = __DIR__ . '/../public/api/lib';
require $lib . '/Health.php';
require $lib . '/Http.php';
require $lib . '/Text.php';
require $lib . '/Products.php';
require $lib . '/Providers/OebbHafas.php';
require $lib . '/Providers/DbVendo.php';
require $lib . '/Providers/Mvg.php';
require $lib . '/CityTrips.php';
require $lib . '/Providers/Overpass.php';
require $lib . '/Cache.php';
require $lib . '/Providers/CoachSequence.php';
require $lib . '/Providers/SwissOpenData.php';

$gesamt = 0;
$fehler = 0;

function pruefe(string $name, mixed $ist, mixed $soll): void
{
    global $gesamt, $fehler;
    $gesamt++;
    if ($ist === $soll) {
        echo "  ok   $name\n";
        return;
    }
    $fehler++;
    echo "  FAIL $name\n";
    echo '         erwartet: ' . json_encode($soll, JSON_UNESCAPED_UNICODE) . "\n";
    echo '         bekommen: ' . json_encode($ist, JSON_UNESCAPED_UNICODE) . "\n";
}

/** Private Methode aufrufen - die Übersetzer sind absichtlich nicht öffentlich. */
function privat(object|string $ziel, string $methode, mixed ...$args): mixed
{
    $m = new ReflectionMethod($ziel, $methode);
    return $m->invoke(is_object($ziel) ? $ziel : null, ...$args);
}

function fixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name), true, 512, JSON_THROW_ON_ERROR);
}

$http = new Http(1);

// ---------------------------------------------------------------------
echo "\nMVG - Verbindungen im App-Format\n";

$mvg    = new Mvg($http, ['endpoint' => 'https://example.invalid']);
$routes = fixture('mvg_routes.json');
$j      = privat($mvg, 'mapRoute', $routes[0]);

pruefe('U-Bahn heißt nach der Linie', [$j['legs'][0]['category'], $j['legs'][0]['line']], ['U', 'U5']);
pruefe('keine Zugnummer im Linienverkehr', $j['legs'][0]['trainNumber'], '');
pruefe('Abfahrt mit Zone', substr((string) $j['departure'], 11, 5), '23:36');
pruefe('Ankunft aus "plannedDeparture" des Ziels', substr((string) $j['arrival'], 11, 5), '23:43');
pruefe('Tarifzone M', $j['tariffZones'], [0]);
pruefe('Deutschlandticket gilt im MVV', $j['legs'][0]['dTicket'], 'Deutschlandticket gültig');
pruefe('Streckenverlauf aus der Polylinie', count($j['legs'][0]['geometry']) > 2, true);
// Die Rolltreppe am Hauptbahnhof war bei der Aufnahme tatsächlich gestört,
// den Aufzug hat die Fixture dazugesetzt.
pruefe('gestörter Aufzug und Rolltreppe am Ausstieg',
    $j['legs'][0]['stationNotes'],
    [['at' => 'to', 'text' => 'Ein Aufzug und eine Rolltreppe außer Betrieb (laut MVG).']]);

$ausfall = $routes[1];
$ausfall['parts'][0]['isCancelled'] = true;
pruefe('fällt ein Abschnitt aus, ist die Verbindung keine Alternative',
    privat($mvg, 'mapRoute', $ausfall), null);

$mitFussweg = $routes[1];
$weg = $mitFussweg['parts'][0];
$weg['line'] = ['transportType' => 'PEDESTRIAN'];
array_unshift($mitFussweg['parts'], $weg);
$jw = privat($mvg, 'mapRoute', $mitFussweg);
pruefe('Fußweg ist ein eigener Abschnitt, kein Zug', [$jw['legs'][0]['mode'], $jw['changes']], ['walk', 0]);

// ---------------------------------------------------------------------
echo "\nDB - alle Tarife einer Verbindung\n";

$recon = fixture('db_recon.json');
$ohne  = DbVendo::mapOffers($recon, false);
$zweite = array_values(array_filter($ohne['fares'], static fn($f) => $f['class'] === 2));
pruefe('drei Tarife in der 2. Klasse, günstigste zuerst',
    array_map(static fn($f) => [$f['name'], $f['amount']], $zweite),
    [['Super Sparpreis', 79.99], ['Sparpreis', 88.99], ['Flexpreis', 118.8]]);
pruefe('Bedingungen am Super Sparpreis',
    array_column($zweite[0]['conditions'], 'text'),
    ['Zugbindung', 'Stornierung ausgeschlossen', 'Kein City-Ticket']);
pruefe('ohne BahnCard: Rabattpreise getrennt, nicht als eigene Tarife',
    [count($ohne['withBahnCard']), min(array_column($ohne['withBahnCard'], 'amount'))], [3, 59.4]);
pruefe('Probe-BahnCards als Rechenbeispiel',
    array_column($ohne['bahncard'], 'name'), ['Probe BahnCard 25', 'Probe BahnCard 50']);
pruefe('Sitzplatzreservierung', [$ohne['seat']['amount'], $ohne['seat']['available']], [5.5, true]);

$mit = DbVendo::mapOffers($recon, true);
pruefe('mit BahnCard: der Rabattpreis IST der eigene Preis',
    count(array_filter($mit['fares'], static fn($f) => $f['discountNote'] !== null)), 3);

// ---------------------------------------------------------------------
echo "\nDB - Ausstattung und Zugang\n";

$vm = ['zugattribute' => [
    ['key' => 'BR', 'value' => 'Bordrestaurant'],
    ['key' => 'FB', 'value' => 'Fahrradmitnahme begrenzt möglich'],
    ['key' => 'FR', 'value' => 'Fahrradmitnahme reservierungspflichtig'],
    ['key' => 'IZ', 'value' => 'Intercity 2: Info unter www.bahn.de/ic2'],
    ['key' => 'RP', 'value' => 'Reservierungspflicht'],
]];
pruefe('Ausstattung: Werbung raus, Fahrrad einmal, die strengere Angabe gewinnt',
    array_map(static fn($a) => [$a['label'], $a['important']], DbVendo::amenitiesOf($vm)),
    [['Bordrestaurant', false], ['Fahrrad nur mit Reservierung', true], ['Reservierungspflicht', true]]);

$abschnitt = [
    'abfahrtsOrt' => 'Hannover Hbf',
    'ankunftsOrt' => 'Hamburg Hbf',
    'himMeldungen' => [
        ['text' => 'Hamburg Hbf: Aufgrund einer Aufzugserneuerung Gleis 13/14 steht dieser nicht zur Verfügung.'],
        ['text' => 'Celle: Aufzug außer Betrieb.'],
        ['text' => 'Hamburg Hbf: Bauarbeiten am Wochenende.'],
    ],
];
pruefe('Zugangsmeldung nur am eigenen Ausstieg, ohne Bahnhofsnamen',
    DbVendo::stationNotesOf($abschnitt),
    [['at' => 'to', 'text' => 'Aufgrund einer Aufzugserneuerung Gleis 13/14 steht dieser nicht zur Verfügung.']]);

// ---------------------------------------------------------------------
echo "\nHAFAS - Ausfall am eigenen Halt, Echtzeit-Erreichbarkeit\n";

$oebb = new OebbHafas($http, [
    'endpoint' => 'https://example.invalid', 'auth' => [], 'client' => [], 'ver' => '1', 'lang' => 'deu',
]);
$common = [
    'locL' => [
        ['name' => 'München Ost', 'extId' => '8000262', 'crd' => ['x' => 11604975, 'y' => 48127437]],
        ['name' => 'München Marienplatz', 'extId' => '8004135', 'crd' => ['x' => 11575382, 'y' => 48137047]],
    ],
    'prodL' => [['name' => 'S 2', 'prodCtx' => ['catOut' => 'DB', 'line' => 'S2', 'num' => '6234']]],
];
$con = [
    'date' => '20260924',
    'dep' => ['dTimeS' => '090000', 'dTZOffset' => 120],
    'arr' => ['aTimeS' => '090500', 'aTZOffset' => 120],
    'isNotRdbl' => true,
    'secL' => [[
        'type' => 'JNY',
        'dep' => ['locX' => 0, 'dTimeS' => '090000', 'dTZOffset' => 120],
        // Die S-Bahn fährt, hält aber am Marienplatz nicht - Stammstrecke gesperrt.
        'arr' => ['locX' => 1, 'aTimeS' => '090500', 'aTZOffset' => 120, 'aCncl' => true],
        'jny' => ['prodX' => 0, 'jid' => 'x', 'dirTxt' => 'Petershausen'],
    ]],
];
$hj = privat($oebb, 'mapConnection', $con, $common);
pruefe('Halt am Ziel ausgefallen = Abschnitt fällt aus', $hj['legs'][0]['cancelled'], true);
pruefe('isNotRdbl kommt als reachable=false an', $hj['reachable'], false);
pruefe('S-Bahn heißt nach der Linie', $hj['legs'][0]['line'], 'S2');

// ---------------------------------------------------------------------
echo "\nStadtfahrten - Blättern, Kennungen, Zusammenführen\n";

$ctx = CityTrips::scrollFor('2026-09-24T09:14:00+02:00', 1);
pruefe('Blätterkontext ist ein Zeitpunkt', $ctx, 'mvg|2026-09-24|09:15');
pruefe('und lässt sich zurücklesen', CityTrips::parseScroll((string) $ctx), ['2026-09-24', '09:15']);
pruefe('HAFAS-Kontext ist kein MVG-Kontext', CityTrips::parseScroll('3|OF|MT#14#1'), null);
pruefe('UTC für die MVG', CityTrips::utc('2026-09-24', '09:00'), '2026-09-24T07:00:00.000Z');

pruefe('dieselbe Fahrt, dieselbe Kennung - auch aus einer neuen Antwort',
    CityTrips::stableId($j) === CityTrips::stableId(privat($mvg, 'mapRoute', $routes[0])), true);

$labels = static fn(array $x): array => array_map(
    static fn($l) => (string) ($l['line'] ?? ''),
    array_values(array_filter($x['legs'], static fn($l) => $l['mode'] === 'train'))
);
$hafasGleich = array_merge($j, ['source' => 'oebb']);
$zweiteMvg = privat($mvg, 'mapRoute', $routes[1]);
$zusammen = CityTrips::merge([$hafasGleich], [$j, $zweiteMvg], $labels);
pruefe('Doppeltes aus HAFAS und MVG einmal, HAFAS gewinnt',
    array_map(static fn($x) => $x['source'], $zusammen), ['oebb', 'mvg']);

$fern = [
    'id' => 'ice', 'source' => 'oebb', 'countries' => ['de'], 'price' => ['amount' => 79.99],
    'departure' => '2026-09-23T23:55:00+02:00', 'arrival' => '2026-09-24T04:00:00+02:00',
    'legs' => [['mode' => 'train', 'line' => '', 'category' => 'ICE',
        'departure' => '2026-09-23T23:55:00+02:00', 'arrival' => '2026-09-24T04:00:00+02:00']],
];
$komplett = CityTrips::compose($j, $fern, true);
pruefe('Zubringer + ICE: eine Verbindung, Preis vom ICE',
    [$komplett['changes'], $komplett['price']['amount'], $komplett['feeder']['side'], $komplett['durationMin']],
    [1, 79.99, 'from', 264]);

// ---------------------------------------------------------------------
echo "\nDB - Zuglauf für die Live-Verfolgung (U-Bahn, Tram)\n";

$lauf = DbVendo::mapTrip([
    'zugName' => 'STR 18', 'cancelled' => false,
    'halte' => [
        ['id' => 'A=1@O=Schwanseestraße, München@X=11596750@Y=48103301@L=625624@', 'extId' => '625624',
         'name' => 'Schwanseestraße, München', 'kategorie' => 'STR',
         'abfahrt' => ['sollzeit' => '2026-09-23T22:34:00', 'echtzeit' => '2026-09-23T22:35:00']],
        ['id' => 'A=1@O=Ostfriedhof, München@X=11582880@Y=48119464@L=625594@', 'extId' => '625594',
         'name' => 'Ostfriedhof, München', 'kategorie' => 'STR',
         'ankunft' => ['sollzeit' => '2026-09-23T22:40:00', 'echtzeit' => '2026-09-23T22:43:00']],
    ],
]);
pruefe('Tram mit Linie und Echtzeit', [$lauf['category'], $lauf['line'], $lauf['hasRealtime'], $lauf['delay']], ['STR', '18', true, 3]);
pruefe('Koordinaten aus der Halt-Kennung', [$lauf['stops'][1]['lat'], $lauf['stops'][1]['lon']], [48.119464, 11.58288]);
pruefe('Zeiten mit Zone', $lauf['stops'][0]['departureReal'], '2026-09-23T22:35:00+02:00');

// ---------------------------------------------------------------------
echo "\nOSM - Ebenen von Treppen und Aufzügen\n";

pruefe('aufgezählt', Overpass::parseLevels('0;-1'), [0.0, -1.0]);
pruefe('Bereich mit negativen Zahlen', Overpass::parseLevels('-2--1'), [-1.0, -2.0]);
pruefe('Bereich aufwärts', Overpass::parseLevels('0-2'), [2.0, 1.0, 0.0]);
pruefe('Unsinn ist keine Ebene', Overpass::parseLevels('EG'), []);
$treppe = privat(Overpass::class, 'connector', [
    'type' => 'way', 'tags' => ['highway' => 'steps', 'conveying' => 'backward', 'level' => '0;-2'],
    'geometry' => [['lat' => 47.1, 'lon' => 8.1], ['lat' => 47.2, 'lon' => 8.2], ['lat' => 47.3, 'lon' => 8.3]],
]);
pruefe('Rolltreppe mit Richtung, Anfang und Ende',
    [$treppe['type'], $treppe['dir'], $treppe['levels'], $treppe['line']],
    ['escalator', 'backward', [0.0, -2.0], [[47.1, 8.1], [47.3, 8.3]]]);
pruefe('Treppe ohne Ebene bleibt draußen (Straßenraum)',
    privat(Overpass::class, 'connector', ['type' => 'way', 'tags' => ['highway' => 'steps'],
        'geometry' => [['lat' => 1, 'lon' => 1], ['lat' => 2, 'lon' => 2]]]), null);

// ---------------------------------------------------------------------
echo "\nDB - Wagenreihung (ICE 1211, München Hbf Gleis 12)\n";

$wr  = fixture('db_wagenreihung.json');
$seq = CoachSequence::mapSequence($wr);
pruefe('Bahnsteig mit Sektoren A–G', [$seq['platform'], array_column($seq['sectors'], 'name')],
    ['12', ['A', 'B', 'C', 'D', 'E', 'F', 'G']]);
pruefe('zwei Zugteile, 14 Wagen', [count($seq['trains']), count($seq['vehicles'])], [2, 14]);
pruefe('Wagen mit Nummer, Sektor und Lage in Metern',
    [$seq['vehicles'][0]['n'], $seq['vehicles'][0]['sector'], $seq['vehicles'][0]['start']], ['21', 'G', 361.8]);
pruefe('Baureihe aus der Bauart "I4115": ICE T', CoachSequence::seriesFromVehicles($wr, 'ICE'),
    ['series' => '411', 'seriesName' => 'ICE T (BR 411)']);
pruefe('ICE 4 schreibt die Baureihe hinten ("I1412")', CoachSequence::seriesFromVehicles(
    ['groups' => [['vehicles' => [['type' => ['constructionType' => 'I0812']], ['type' => ['constructionType' => 'I1412']]]]]], 'ICE'),
    ['series' => '412', 'seriesName' => 'ICE 4 (BR 412)']);
pruefe('Schweizer Wagen ("B11") haben keine DB-Baureihe', CoachSequence::seriesFromVehicles(
    ['groups' => [['vehicles' => [['type' => ['constructionType' => 'B11']]]]]], 'EC'), null);

// ---------------------------------------------------------------------
echo "\nSchweiz - eigenen Zug auf der Tafel finden\n";

$tafel = [
    ['category' => 'S', 'number' => '12', 'to' => 'Winterthur', 'stop' => ['departure' => '2026-09-24T09:02:00+0200']],
    ['category' => 'IR', 'number' => '37', 'to' => 'Basel SBB', 'stop' => ['departure' => '2026-09-24T09:02:00+0200',
        'prognosis' => ['departure' => '2026-09-24T09:06:00+0200', 'platform' => '14']]],
    ['category' => 'IR', 'number' => '36', 'to' => 'Chur', 'stop' => ['departure' => '2026-09-24T09:02:00+0200']],
];
$plan = strtotime('2026-09-24T09:02:00+02:00');
pruefe('gleiche Minute, Gattung und Richtung entscheiden',
    SwissOpenData::find($tafel, 'departure', $plan, 'IR', 'Basel SBB')['number'] ?? null, '37');
pruefe('Richtung mit Klammerzusatz ("Chur (GR)")',
    SwissOpenData::find($tafel, 'departure', $plan, 'IR', 'Chur (GR)')['number'] ?? null, '36');
pruefe('andere Minute: kein Treffer',
    SwissOpenData::find($tafel, 'departure', $plan + 300, 'IR', ''), null);

// ---------------------------------------------------------------------
echo "\nText - Fremdtexte als Klartext\n";

pruefe('Absätze bleiben, Tags fallen, Entities werden Zeichen',
    Text::plain('<p>S-Bahn&nbsp;gesperrt</p><p>Bitte <b>U-Bahn</b> nutzen</p>'),
    "S-Bahn gesperrt\nBitte U-Bahn nutzen");

echo "\n" . ($gesamt - $fehler) . " von $gesamt bestanden.\n";
exit($fehler === 0 ? 0 : 1);
