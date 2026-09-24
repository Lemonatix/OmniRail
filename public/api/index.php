<?php
/**
 * API-Einstiegspunkt.
 *
 * Routen (alle GET):
 *   ?action=health                          Welche Quellen sind erreichbar?
 *   ?action=catalogue                       Abo-Katalog für das Frontend
 *   ?action=locations&q=Bern                Ortssuche (inkl. MVG-Halte)
 *   ?action=journeys&from=..&to=..&date=..  Verbindungen inkl. Preis
 *   ?action=livetrains&bbox=..              Live-Positionen im Ausschnitt
 *   ?action=traindetails&jid=..             Zuglauf mit Halten und Verspätung
 *   ?action=bestprices&from=..&to=..&date=.. Preisstrecke für eine Woche
 *   ?action=nextconnection&from=..&to=..    Nächster Anschluss nach einem knappen Umstieg oder Ausfall
 *   ?action=localroute&fromLat=..&toLat=..  Ersatzweg im MVV (U-Bahn, Tram, Bus) über die MVG
 *   ?action=offers&ctx=..                   Alle DB-Tarife einer Verbindung samt Bedingungen
 *   ?action=departures&station=..           Abfahrts-/Ankunftstafel (HAFAS, in München plus MVG)
 *   ?action=fxrate                          EZB-Tageskurse (für CHF neben EUR)
 *   ?action=platforms&lat=..&lon=..         Bahnsteiglage aus OSM für den Umstiegsplan
 *   ?action=works                           Bauarbeiten im Netz, mit Abschnitt und Zeitraum
 *   ?action=disruptions                     MVG-Störungsticker München
 *
 * Strategie bei journeys:
 *   1. Fahrplan von der ÖBB holen (zuverlässig, mit Zuggattung + Ländercodes)
 *   2. Preise von DB dazuholen und über Ab-/Ankunftszeit zuordnen
 *   3. Was ohne echten Preis bleibt, wird in Fares.php geschätzt
 */

declare(strict_types=1);

// --- Fehler gehören ins Log, nicht in die Antwort ------------------------
//
// Diese Datei liefert JSON. Eine einzige Warnung, die PHP direkt ausgibt,
// steht damit MITTEN in der Antwort, und json_decode() im Browser scheitert
// an einer Datei, die inhaltlich völlig in Ordnung wäre. Genau das ist
// schon einmal passiert - siehe die curl_close()-Notiz in lib/Http.php.
// Gemeldet wird weiterhin alles, nur eben ins Fehlerlog.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Ein Upstream darf länger brauchen, als PHP von Haus aus zulässt.
//
// http_timeout steht auf 25 Sekunden, und mehrere Handler rufen zwei
// Quellen NACHEINANDER (Fahrplan bei der ÖBB, Preise bei der DB). Die
// verbreitete Voreinstellung max_execution_time=30 reisst dem Skript dabei
// mitten im zweiten Aufruf den Boden weg. 0 ("unbegrenzt") ist keine gute
// Idee, weil ein hängender Socket dann einen Worker dauerhaft blockiert -
// deshalb ein großzügiger, aber endlicher Wert.
@set_time_limit(120);

// Ausgabe puffern, damit der Notausgang unten eine halb geschriebene
// Antwort noch verwerfen kann.
ob_start();

/**
 * NOTAUSGANG: aus einem Fatal wieder gültiges JSON machen.
 *
 * try/catch weiter unten fängt Exceptions - aber kein überschrittenes
 * Zeitlimit, keinen erschöpften Speicher und keinen Parse-Fehler. In diesen
 * Fällen endete die Antwort bisher einfach mitten im Satz, und im Frontend
 * stand nur "Das Backend hat keine gültige Antwort geliefert".
 *
 * Ein Flag, ob schon geantwortet wurde, braucht es nicht: ok() und fail()
 * beenden das Skript, ein Fatal DANACH kann es also nicht geben. Steht bei
 * Programmende ein Fatal im Fehlerspeicher, ist die Antwort garantiert
 * unfertig.
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(
        ['ok' => false, 'error' => 'Die Anfrage konnte nicht zu Ende bearbeitet werden (Zeit- oder Speichergrenze).'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
});

// Fallbacks für die mbstring-Extension. Auf produktiven Hostings ist sie
// praktisch immer da; für schlanke lokale CLI-Setups ohne php-mbstring
// können die Kernfunktionen aus mbstring hier durch strlen/strtolower
// ersetzt werden, ohne dass die Ortssuche kaputt geht. Für reine
// Längenprüfungen und Cache-Keys reicht die ASCII-Semantik völlig.
if (!function_exists('mb_strlen')) {
    /** @return int */
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        // strlen zählt Bytes; für die Untergrenze "mindestens N Zeichen"
        // ist das eine sichere Überschätzung (jedes UTF-8-Zeichen >= 1 Byte).
        return strlen($s);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $encoding = null): string
    {
        return strtolower($s);
    }
}

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Cache.php';
require __DIR__ . '/lib/Text.php';
require __DIR__ . '/lib/Fares.php';
require __DIR__ . '/lib/Products.php';
require __DIR__ . '/lib/Shops.php';
require __DIR__ . '/lib/Locations.php';
require __DIR__ . '/lib/Punctuality.php';
require __DIR__ . '/lib/Fleet.php';
require __DIR__ . '/lib/Health.php';
require __DIR__ . '/lib/Providers/OebbHafas.php';
require __DIR__ . '/lib/Providers/DbVendo.php';
require __DIR__ . '/lib/Providers/CoachSequence.php';
require __DIR__ . '/lib/Providers/Mvg.php';
require __DIR__ . '/lib/Providers/Overpass.php';
require __DIR__ . '/lib/Providers/StreckenInfo.php';
require __DIR__ . '/lib/RailGeometry.php';
require __DIR__ . '/lib/CityTrips.php';

$config = require __DIR__ . '/config.php';

// --- Header -------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$origins = $config['cors_origins'] ?? [];
if ($origins !== []) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// --- Infrastruktur ------------------------------------------------------

$cache = new Cache((string) $config['cache_dir']);
$http  = new Http((int) $config['http_timeout']);

// Gelegentlich aufräumen, damit der Cache-Ordner nicht unbegrenzt wächst.
//
// NICHT KUERZER ALS DIE LAENGSTE HALTBARKEIT: Der Standardwert von gc() ist
// ein Tag, und der warf täglich genau die Einträge weg, die am teuersten zu
// beschaffen sind - Bahnsteige gelten sieben Tage, Streckenverläufe dreißig.
// Overpass durfte sie danach jedes Mal neu liefern.
$maxTtl = max([86400, ...array_map('intval', array_values($config['cache_ttl'] ?? []))]);
if (random_int(1, 100) === 1) {
    $cache->gc($maxTtl + 86400);
}

/**
 * Was eine Aktion im Rate-Limit kostet.
 *
 * NICHT JEDE ANFRAGE IST GLEICH TEUER, und vorher zählte sie es doch: die
 * gecachte Abo-Liste so viel wie eine Verbindungssuche. Das trifft am Ende
 * die eigene App. Wer die Live-Verfolgung offen hat, holt alle 30 Sekunden
 * zwei Zugläufe; wer dabei die Karte schiebt, löst je Bewegung eine
 * Positionsabfrage aus. Damit war das Kontingent aufgebraucht, ohne dass
 * eine einzige Suche gelaufen wäre - und die nächste Suche bekam 429.
 *
 * Bepreist wird deshalb nach dem, was eine Aktion nach DRAUSSEN auslöst.
 * Was ohnehin aus dem Cache kommt, kostet nichts.
 */
const RATE_COST = [
    'health'         => 0,
    'catalogue'      => 0,
    'fxrate'         => 0,
    'disruptions'    => 1,
    'locations'      => 1,
    'livetrains'     => 2,
    'traindetails'   => 2,
    'works'          => 4,
    'platforms'      => 4,
    'nextconnection' => 4,
    'offers'         => 4,
    'departures'     => 2,
    'bestprices'     => 5,
    'journeys'       => 5,
];

/** Voreinstellung für alles, was nicht in der Tabelle steht. */
const RATE_COST_DEFAULT = 3;

// Ab hier wird jeder Aufruf nach draußen mitgezählt - check.php zeigt es.
Health::watch((string) $config['cache_dir']);

if (!rateLimitOk($cache, $config)) {
    fail('Zu viele Anfragen. Bitte kurz warten.', 429);
}

$action = (string) ($_GET['action'] ?? '');

try {
    switch ($action) {
        case 'health':
            handleHealth($http, $config, $cache);
            break;
        case 'catalogue':
            ok([
                'abos'     => Fares::catalogue(),
                'products' => Products::catalogue(),
            ]);
            break;
        case 'locations':
            handleLocations($http, $config, $cache);
            break;
        case 'journeys':
            handleJourneys($http, $config, $cache);
            break;
        case 'livetrains':
            handleLiveTrains($http, $config, $cache);
            break;
        case 'traindetails':
            handleTrainDetails($http, $config, $cache);
            break;
        case 'bestprices':
            handleBestPrices($http, $config, $cache);
            break;
        case 'nextconnection':
            handleNextConnection($http, $config, $cache);
            break;
        case 'localroute':
            handleLocalRoute($http, $config, $cache);
            break;
        case 'offers':
            handleOffers($http, $config, $cache);
            break;
        case 'departures':
            handleDepartures($http, $config, $cache);
            break;
        case 'fxrate':
            handleFxRate($http, $config, $cache);
            break;
        case 'platforms':
            handlePlatforms($http, $config, $cache);
            break;
        case 'works':
            handleWorks($http, $config, $cache);
            break;
        case 'disruptions':
            handleDisruptions($http, $config, $cache);
            break;
        default:
            fail('Unbekannte Aktion. Erlaubt: health, catalogue, locations, journeys, livetrains, traindetails, bestprices, nextconnection, localroute, offers, departures, fxrate, platforms, works, disruptions', 400);
    }
} catch (Throwable $e) {
    // Details bleiben im Log, der Client bekommt nur eine generische Meldung.
    error_log('[train-maxxing] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Interner Fehler bei der Verarbeitung.', 500);
}

// ======================================================================
// Handler
// ======================================================================

function handleHealth(Http $http, array $config, Cache $cache): void
{
    $out = [
        'php'          => PHP_VERSION,
        'curl'         => function_exists('curl_init'),
        'cacheWritable' => $cache->isAvailable(),
        'providers'    => [],
    ];

    // ÖBB
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $t0   = microtime(true);
    $r    = $oebb->locations('Wien', 1);
    $out['providers']['oebb'] = [
        'label'   => 'ÖBB HAFAS (Fahrplan, Zuggattungen)',
        'ok'      => $r['ok'],
        'error'   => $r['error'],
        'ms'      => (int) round((microtime(true) - $t0) * 1000),
        'critical' => true,
    ];

    // DB
    $db = new DbVendo($http, $config['providers']['db']);
    $t0 = microtime(true);
    $r  = $db->locations('Berlin', 1);
    $out['providers']['db'] = [
        'label'   => 'DB bahn.de (Preise)',
        'ok'      => $r['ok'],
        'error'   => $r['error'],
        'ms'      => (int) round((microtime(true) - $t0) * 1000),
        'critical' => false,
    ];

    // MVG - nur wenn aktiviert, sonst ist der Health-Check länger als nötig.
    if (($config['providers']['mvg']['enabled'] ?? false) === true) {
        $mvg = new Mvg($http, $config['providers']['mvg']);
        $t0  = microtime(true);
        $r   = $mvg->locations('Marienplatz', 1);
        $out['providers']['mvg'] = [
            'label'   => 'MVG (Münchner Nahverkehr, Störungsticker)',
            'ok'      => $r['ok'],
            'error'   => $r['error'],
            'ms'      => (int) round((microtime(true) - $t0) * 1000),
            'critical' => false,
        ];
    }

    ok($out);
}

function handleLocations(Http $http, array $config, Cache $cache): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        ok(['locations' => []]);
    }

    $key    = 'loc:' . mb_strtolower($q);
    $cached = $cache->get($key, (int) $config['cache_ttl']['locations']);
    if ($cached !== null) {
        ok(['locations' => $cached, 'cached' => true]);
    }

    // Beide Quellen: die DB kennt den deutschen Stadtverkehr, die ÖBB die
    // kleinen Halte in AT und CH.
    $loc = new Locations($http, $config['providers']);
    $res = $loc->search($q, 10);

    if (!$res['ok'] && $res['data'] === []) {
        fail('Ortssuche fehlgeschlagen: ' . ($res['error'] ?? 'unbekannt'), 502);
    }

    $cache->set($key, $res['data']);
    ok(['locations' => $res['data'], 'sources' => $res['sources'], 'cached' => false]);
}

/**
 * Züge, die gerade im Kartenausschnitt unterwegs sind.
 * Kurz gecacht, damit Zoomen und Verschieben nicht jedes Mal eine Anfrage
 * auslöst.
 */
function handleLiveTrains(Http $http, array $config, Cache $cache): void
{
    $bbox = array_map('trim', explode(',', (string) ($_GET['bbox'] ?? '')));
    if (count($bbox) !== 4) {
        fail('Parameter "bbox" erwartet vier Werte: süd,west,nord,ost', 400);
    }

    [$south, $west, $north, $east] = array_map('floatval', $bbox);
    if ($south >= $north || $west >= $east) {
        fail('Ungültiger Kartenausschnitt.', 400);
    }
    // Zu große Ausschnitte liefern nur Rauschen und belasten die Quelle.
    if (($north - $south) > 6 || ($east - $west) > 10) {
        ok(['trains' => [], 'note' => 'Ausschnitt zu groß - bitte weiter hineinzoomen.']);
    }

    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    $key = 'live:' . implode(',', array_map(static fn($v) => round((float) $v, 2), $bbox))
         . ':' . implode('+', $products);
    $cached = $cache->get($key, 30);
    if ($cached !== null) {
        ok(['trains' => $cached, 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->liveTrains($south, $west, $north, $east, 40, Products::bitmask($products));

    if (!$res['ok']) {
        // Live-Positionen sind Beiwerk - ein Fehler darf die Karte nicht stören.
        ok(['trains' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['trains' => $res['data'], 'cached' => false]);
}

/**
 * Der komplette Lauf eines Zuges mit Halten und Verspätung.
 * Kurz gecacht - Echtzeitdaten ändern sich, aber nicht im Sekundentakt.
 */
function handleTrainDetails(Http $http, array $config, Cache $cache): void
{
    // DREI QUELLEN für einen Zuglauf. HAFAS über die jid ist der Normalfall.
    // Wo die fehlt - U-Bahn, Tram und Bus in München, deren Fahrplan von der
    // DB oder der MVG kommt -, springen die DB mit ihrer journeyId und die
    // MVG mit ihrer Abfahrtstafel ein. Die Antwort hat in allen drei Fällen
    // dasselbe Format, die Live-Verfolgung merkt keinen Unterschied.
    $dbId = trim((string) ($_GET['db'] ?? ''));
    if ($dbId !== '') {
        if (strlen($dbId) > 600) {
            fail('Parameter "db" ist ungültig.', 400);
        }
        $key = 'jddb:' . md5($dbId);
        $cached = $cache->get($key, 30);
        if ($cached !== null) {
            ok(['train' => $cached, 'cached' => true]);
        }
        $res = (new DbVendo($http, $config['providers']['db']))->trip($dbId);
        if (!$res['ok']) {
            fail('Zuglauf bei der DB nicht verfügbar: ' . $res['error'], 502);
        }
        $cache->set($key, $res['data']);
        ok(['train' => $res['data'], 'cached' => false]);
    }

    $mvgFrom = trim((string) ($_GET['mvgFrom'] ?? ''));
    if ($mvgFrom !== '') {
        $leg = [
            'from'     => $mvgFrom,
            'to'       => trim((string) ($_GET['mvgTo'] ?? '')),
            'line'     => trim((string) ($_GET['line'] ?? '')),
            'dep'      => trim((string) ($_GET['dep'] ?? '')),
            'arr'      => trim((string) ($_GET['arr'] ?? '')),
            'fromName' => trim((string) ($_GET['fromName'] ?? '')),
            'toName'   => trim((string) ($_GET['toName'] ?? '')),
        ];
        foreach (['from', 'to'] as $k) {
            if (!preg_match('/^[A-Za-z0-9:_-]{3,60}$/', $leg[$k])) {
                fail('MVG-Halt ungültig.', 400);
            }
        }
        if ($leg['line'] === '' || $leg['dep'] === '' || $leg['arr'] === '') {
            fail('Parameter "line", "dep" und "arr" sind erforderlich.', 400);
        }
        if (($config['providers']['mvg']['enabled'] ?? false) !== true) {
            fail('MVG-Provider ist abgeschaltet.', 400);
        }
        $key = 'jdmvg:' . md5(json_encode($leg));
        $cached = $cache->get($key, 30);
        if ($cached !== null) {
            ok(['train' => $cached, 'cached' => true]);
        }
        $res = (new Mvg($http, $config['providers']['mvg']))->trip($leg);
        if (!$res['ok']) {
            fail('Echtzeit der MVG nicht verfügbar: ' . $res['error'], 502);
        }
        $cache->set($key, $res['data']);
        ok(['train' => $res['data'], 'cached' => false]);
    }

    $jid = trim((string) ($_GET['jid'] ?? ''));
    if ($jid === '') {
        fail('Parameter "jid", "db" oder "mvgFrom" fehlt.', 400);
    }

    // Die Pünktlichkeitshistorie kommt NICHT in den Cache: sie wächst mit
    // jedem beobachteten Zug, und aus einer gecachten Antwort wäre sie eine
    // Minute lang veraltet. Sie ist ohnehin nur ein Dateilesevorgang, also
    // wird sie auf beiden Wegen frisch angehängt.
    //
    // Vorher hing sie an $t, gecacht wurde aber $res['data'] - dieselbe
    // Anfrage lieferte die Historie deshalb beim ersten Aufruf mit und
    // sechzig Sekunden lang danach nicht mehr.
    $historie = static function (array $t) use ($config): array {
        $num = trim((string) ($t['trainNumber'] ?? ''));
        $cat = trim((string) ($t['category'] ?? ''));
        if ($num === '' || $cat === '') {
            return $t;
        }
        $stats = (new Punctuality((string) $config['cache_dir']))->stats($cat, $num);
        if ($stats !== null) {
            $t['history'] = $stats;
        }
        return $t;
    };

    $key    = 'jd:' . md5($jid);
    $cached = $cache->get($key, 60);
    if ($cached !== null) {
        ok(['train' => $historie($cached), 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->journeyDetails($jid);

    if (!$res['ok']) {
        fail('Zugdetails nicht verfügbar: ' . $res['error'], 502);
    }

    // Beobachtete Verspätung in die eigene Statistik aufnehmen. So füllt
    // sich die Historie mit der Nutzung, ohne dass jemand Daten einkaufen
    // muss. Nur hier, nicht im Cache-Zweig: sonst zählte dieselbe Fahrt bei
    // jedem Kartenklick erneut.
    $t = $res['data'];
    if (($t['hasRealtime'] ?? false) && ($t['trainNumber'] ?? '') !== '') {
        (new Punctuality((string) $config['cache_dir']))->record(
            (string) $t['category'],
            (string) $t['trainNumber'],
            (int) ($t['delay'] ?? 0),
            (string) (($t['stops'][0]['departure'] ?? null) ?? date('Y-m-d'))
        );
    }

    $cache->set($key, $t);
    ok(['train' => $historie($t), 'cached' => false]);
}

/**
 * Bestpreise über den Tag - beantwortet, ob sich eine andere Abfahrtszeit lohnt.
 */
function handleBestPrices(Http $http, array $config, Cache $cache): void
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));

    if ($from === '' || $to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Parameter "from", "to" und "date" (YYYY-MM-DD) sind erforderlich.', 400);
    }

    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));
    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    $key = 'bp:' . implode('|', [$from, $to, $date, $travelClass, implode('+', $discounts), implode('+', $products)]);
    $cached = $cache->get($key, 1800);
    if ($cached !== null) {
        ok(['intervals' => $cached, 'cached' => true]);
    }

    if (($config['providers']['db']['enabled'] ?? false) !== true) {
        ok(['intervals' => [], 'note' => 'DB-Provider ist abgeschaltet.']);
    }

    $db  = new DbVendo($http, $config['providers']['db']);
    $res = $db->bestPrices($from, $to, $date, $travelClass, $discounts, $products);

    if (!$res['ok']) {
        // Beiwerk - kein Grund, die Seite mit einem Fehler zu behelligen.
        ok(['intervals' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['intervals' => $res['data'], 'cached' => false]);
}

/**
 * Die nächsten Anschlüsse ab einem Umsteigebahnhof.
 *
 * WOZU: Bei ein bis vier Minuten Umsteigezeit ist die Frage nicht "schaffe ich
 * das", sondern "was passiert, wenn nicht". Und während der Fahrt, wenn der
 * Zubringer Verspätung hat, wird daraus "was nehme ich stattdessen".
 *
 * Deshalb liefert der Endpunkt vollständige Verbindungen (mit Abschnitten,
 * Halten und Zuglauf-IDs), nicht nur eine Kurzfassung: die Live-Verfolgung
 * soll direkt auf eine davon umschalten können, ohne neu zu suchen.
 *
 * Bewusst ein eigener Endpunkt und kein Teil von handleJourneys: die Suche
 * braucht eine zusätzliche HAFAS-Abfrage je betroffenem Umstieg. Im
 * Suchlauf würde das jede Suche spürbar verlangsamen, obwohl die Antwort
 * nur für die wenigen Fälle gebraucht wird, in denen es eng wird.
 */
function handleNextConnection(Http $http, array $config, Cache $cache): void
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? ''));

    if ($from === '' || $to === '') {
        fail('Parameter "from" und "to" sind erforderlich.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Parameter "date" muss YYYY-MM-DD sein.', 400);
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "time" muss HH:MM sein.', 400);
    }

    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    // Zugnummer des Anschlusses, den man verpasst hätte - der darf nicht
    // als eigene Rückfallebene zurückkommen.
    $exclude = trim((string) ($_GET['exclude'] ?? ''));
    // Wie viele Alternativen. Eine reicht für den Hinweis an der Karte,
    // während der Fahrt will man die Wahl haben.
    $limit = max(1, min(3, (int) ($_GET['limit'] ?? 1)));

    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));

    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    $key = 'next:' . implode('|', [
        $from, $to, $date, $time, $travelClass, $exclude, $limit,
        implode('+', $discounts), implode('+', $products),
    ]);
    $cached = $cache->get($key, (int) $config['cache_ttl']['journeys']);
    if ($cached !== null) {
        ok(['connections' => $cached ?: [], 'cached' => true]);
    }

    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    // Etwas mehr anfragen als gebraucht: der verpasste Zug selbst und
    // Verbindungen vor dem Stichzeitpunkt fallen unten noch heraus.
    // Immer mit Echtzeit: gesucht wird der Weg JETZT, um einen Ausfall oder
    // einen geplatzten Anschluss herum. Die Fahrplansuche bot hier die
    // nächste S-Bahn derselben gesperrten Strecke an.
    $res = $oebb->journeys(
        $from, $to, $date, $time, false, $limit + 3, $travelClass, [],
        Products::bitmask($products), 1, null, isNearNow($date, $time)
    );

    if (!$res['ok']) {
        // Beiwerk: lieber keine Rückfallebene zeigen als die Karte kaputt machen.
        ok(['connections' => [], 'error' => $res['error']]);
    }

    // Verglichen wird auf der Wanduhr des Bahnhofs, nicht auf Unixzeit: die
    // Anfrage nennt eine Ortszeit ohne Zonenangabe, und der Server muss nicht
    // in derselben Zone stehen wie die Strecke.
    $planned = $date . ' ' . $time;
    $out = [];

    foreach ($res['data'] as $j) {
        $dep = wallClock($j['departure'] ?? null);
        if ($dep === null || $dep < $planned) {
            continue;
        }
        // Denselben Zug noch einmal anzubieten wäre sinnlos.
        if ($exclude !== '' && firstTrainNumber($j) === $exclude) {
            continue;
        }
        // Eine Alternative, die selbst ausfällt, ist keine. Bei einer
        // gesperrten Strecke ist das der Normalfall: die nächste S-Bahn auf
        // derselben Linie fällt genauso aus wie die, für die hier Ersatz
        // gesucht wird - `exclude` erwischt nur die eine Zugnummer.
        if (hasCancelledLeg($j)) {
            continue;
        }

        // Vollständig aufbereiten, damit die Live-Verfolgung ohne weitere
        // Abfrage auf diese Verbindung umschalten kann.
        $j = annotateTransfers($j);
        $j = Fares::apply($j, $discounts, $travelClass);
        $j['trains'] = trainLabels($j);

        $out[] = $j;
        if (count($out) >= $limit) {
            break;
        }
    }

    // Auch ein negativer Befund wird gecacht - sonst fragt jede Neuzeichnung
    // der Liste erneut an.
    $cache->set($key, $out);
    ok(['connections' => $out, 'cached' => false]);
}

/**
 * Zeitstempel als 'YYYY-MM-DD HH:MM' in seiner eigenen Zone.
 *
 * DateTimeImmutable übernimmt den Offset aus dem String, format() gibt ihn
 * also in Ortszeit zurück - unabhängig von date_default_timezone_get().
 */
function wallClock(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d H:i');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Liegt ein Zeitpunkt nah genug an jetzt, dass die Echtzeitlage zählt?
 *
 * Eine Stunde zurück (wer die Verbindung von eben nachschlägt) bis drei
 * Stunden voraus - weiter reicht keine Prognose. Datum und Uhrzeit sind
 * mitteleuropäische Ortszeit, wie im Formular.
 */
function isNearNow(string $date, string $time): bool
{
    try {
        $t = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin')))->getTimestamp();
    } catch (Exception) {
        return false;
    }
    $d = $t - time();
    return $d >= -3600 && $d <= 3 * 3600;
}

/** Fällt irgendein Zugabschnitt dieser Verbindung aus? */
function hasCancelledLeg(array $journey): bool
{
    foreach ($journey['legs'] ?? [] as $leg) {
        if (($leg['mode'] ?? '') === 'train' && !empty($leg['cancelled'])) {
            return true;
        }
    }
    return false;
}

/**
 * Ersatzweg innerhalb des MVV - über die MVG statt über HAFAS.
 *
 * WOZU: Die Fahrplanquelle der ÖBB kennt die Münchner U-Bahn nicht. Fällt
 * die S-Bahn-Stammstrecke aus, konnte die App deshalb nur weitere S-Bahnen
 * anbieten - die ebenfalls ausfallen - und nie die U-Bahn, mit der man
 * tatsächlich weiterkommt. Die MVG kennt das ganze Netz samt Echtzeit.
 *
 * Gesucht wird zwischen zwei PUNKTEN, nicht zwischen HAFAS-Kennungen: die
 * MVG versteht EVA-Nummern nicht. Beide Enden werden auf die nächste
 * MVG-Haltestelle abgebildet; liegt eines ausserhalb (weiter als 400 m von
 * jeder Haltestelle), gibt es hier nichts zu holen, und die Antwort ist leer.
 *
 * Gedacht als ÜBERBRÜCKUNG eines ausgefallenen Abschnitts: vom Einstieg des
 * ausgefallenen Zuges bis zu seinem Ausstieg. Was danach kommt, setzt das
 * Frontend wieder an.
 */
function handleLocalRoute(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['mvg']['enabled'] ?? false) !== true) {
        ok(['connections' => [], 'note' => 'MVG-Provider ist abgeschaltet.']);
    }

    $num = static fn(string $k): ?float => is_numeric($_GET[$k] ?? null) ? (float) $_GET[$k] : null;
    $fromLat = $num('fromLat');
    $fromLon = $num('fromLon');
    $toLat   = $num('toLat');
    $toLon   = $num('toLon');
    $date = trim((string) ($_GET['date'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? ''));

    if ($fromLat === null || $fromLon === null || $toLat === null || $toLon === null) {
        fail('Parameter "fromLat", "fromLon", "toLat" und "toLon" sind erforderlich.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "date" (YYYY-MM-DD) und "time" (HH:MM) sind erforderlich.', 400);
    }

    // Kurz halten: gesucht wird ein Weg um einen AKTUELLEN Ausfall herum, und
    // die Echtzeitlage ändert sich minütlich.
    $key = sprintf('localroute:%.4f,%.4f|%.4f,%.4f|%s %s', $fromLat, $fromLon, $toLat, $toLon, $date, $time);
    $ttl = (int) ($config['cache_ttl']['disruptions'] ?? 120);
    $cached = $cache->get($key, $ttl);
    if ($cached !== null) {
        ok(['connections' => $cached, 'cached' => true]);
    }

    $mvg = new Mvg($http, $config['providers']['mvg']);
    $von = $mvg->nearestStation($fromLat, $fromLon);
    $bis = $mvg->nearestStation($toLat, $toLon);

    if ($von === null || $bis === null || $von['globalId'] === $bis['globalId']) {
        $cache->set($key, []);
        ok(['connections' => [], 'note' => 'Nicht im MVG-Netz.']);
    }

    // Die Anfrage nennt Münchner Ortszeit; die MVG will UTC.
    try {
        $utc = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.000\Z');
    } catch (Exception) {
        fail('Ungültige Zeitangabe.', 400);
    }

    $res = $mvg->routes($von['globalId'], $bis['globalId'], $utc, 4);
    if (!$res['ok']) {
        ok(['connections' => [], 'error' => $res['error']]);
    }

    $out = [];
    foreach ($res['data'] as $j) {
        $j = annotateTransfers($j);
        $j['trains'] = trainLabels($j);
        $out[] = $j;
    }

    $cache->set($key, $out);
    ok(['connections' => $out, 'cached' => false]);
}

/**
 * Alle Tarife einer Verbindung bei der DB.
 *
 * Die Trefferliste zeigt den günstigsten Preis. Ob der eine Zugbindung hat,
 * was der Flexpreis kostet und ob sich die 1. Klasse lohnt, steht dort
 * nicht - das liefert die DB erst auf Nachfrage für genau eine Verbindung,
 * über deren ctxRecon (siehe DbVendo::offers()). Das Frontend fragt beim
 * Aufklappen.
 */
function handleOffers(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['db']['enabled'] ?? false) !== true) {
        fail('Die DB-Anbindung ist abgeschaltet.', 400);
    }

    $ctx = (string) ($_GET['ctx'] ?? '');
    // Ein ctxRecon ist rund tausend Zeichen lang. Was deutlich länger ist,
    // kommt nicht von der DB.
    if ($ctx === '' || strlen($ctx) > 8000) {
        fail('Parameter "ctx" fehlt oder ist ungültig.', 400);
    }
    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));

    $key = 'offers:' . sha1($ctx . '|' . $travelClass . '|' . implode('+', $discounts));
    $cached = $cache->get($key, (int) ($config['cache_ttl']['prices'] ?? 600));
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $db  = new DbVendo($http, $config['providers']['db']);
    $res = $db->offers($ctx, $travelClass, $discounts);
    if (!$res['ok']) {
        fail($res['error'] ?? 'Tarife nicht verfügbar.', 502);
    }

    $cache->set($key, $res['data']);
    ok($res['data'] + ['cached' => false]);
}

/**
 * Abfahrts- oder Ankunftstafel eines Bahnhofs.
 *
 * HAFAS liefert die Tafel für jeden Bahnhof in CH, DE und AT. In München
 * kommt die MVG dazu, aus zwei Gründen: HAFAS kennt die U-Bahn nicht, und
 * für die S-Bahn oft nur den Fahrplan - die MVG hat für beides Echtzeit.
 * Eine S-Bahn, die beide Quellen nennen, steht einmal da: mit der jid von
 * HAFAS (für den Zuglauf) und der Ist-Zeit der MVG.
 *
 * Reine MVG-Halte ("mvg:…") haben nur die MVG-Tafel.
 */
function handleDepartures(Http $http, array $config, Cache $cache): void
{
    $station = trim((string) ($_GET['station'] ?? ''));
    if ($station === '' || strlen($station) > 80) {
        fail('Parameter "station" fehlt.', 400);
    }
    $now  = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $date = trim((string) ($_GET['date'] ?? $now->format('Y-m-d')));
    $time = trim((string) ($_GET['time'] ?? $now->format('H:i')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "date" (YYYY-MM-DD) und "time" (HH:MM) sind ungültig.', 400);
    }
    $arrivals = ($_GET['type'] ?? 'dep') === 'arr';
    $duration = max(15, min(180, (int) ($_GET['duration'] ?? 60)));
    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));
    $num = static fn(string $k): ?float => is_numeric($_GET[$k] ?? null) ? (float) $_GET[$k] : null;
    $lat = $num('lat');
    $lon = $num('lon');

    // Eine Tafel lebt von der Echtzeit - eine Minute ist genug.
    $key = 'board:' . implode('|', [$station, $date, $time, $arrivals ? 'a' : 'd', $duration, implode('+', $products)]);
    $cached = $cache->get($key, 60);
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $isMvg   = str_starts_with($station, 'mvg:');
    $entries = [];
    $info    = null;
    $sources = [];
    $error   = null;

    if (!$isMvg) {
        $oebb = new OebbHafas($http, $config['providers']['oebb']);
        $res  = $oebb->stationBoard($station, $date, $time, $arrivals, $duration, 60, Products::bitmask($products));
        if ($res['ok']) {
            $entries = $res['data'];
            $info    = $res['station'];
            $sources[] = 'hafas';
        } else {
            $error = $res['error'];
        }
    }

    // MVG: nur für Abfahrten (Ankünfte kennt sie nicht), nur ab jetzt bis
    // einen Tag voraus, und nur, wo überhaupt eine MVG-Haltestelle ist.
    $offset = (int) floor((strtotime($date . ' ' . $time . ' Europe/Berlin') - time()) / 60);
    $mvgOn  = ($config['providers']['mvg']['enabled'] ?? false) === true;
    if ($mvgOn && !$arrivals && $offset > -15 && $offset < 1440) {
        $mvg  = new Mvg($http, $config['providers']['mvg']);
        $gids = $isMvg ? [substr($station, 4)] : [];
        $bLat = $lat ?? ($info['lat'] ?? null);
        $bLon = $lon ?? ($info['lon'] ?? null);
        if (!$isMvg && $bLat !== null && $bLon !== null && Mvg::inArea($bLat, $bLon)) {
            // Alle MVG-Halte des Bahnhofs, eine Woche gemerkt.
            $gkey = sprintf('mvgaround:%.4f,%.4f', $bLat, $bLon);
            $gids = $cache->get($gkey, 604800) ?? $mvg->stationsAround($bLat, $bLon);
            $cache->set($gkey, $gids);
        }
        if ($gids !== []) {
            $m = $mvg->departuresMany($gids, max(0, $offset), 40);
            if ($m['ok']) {
                $entries = mergeBoards($entries, $m['data']);
                $sources[] = 'mvg';
            } elseif ($entries === []) {
                $error = $m['error'];
            }
        }
    }

    if ($entries === [] && $error !== null) {
        fail('Tafel nicht verfügbar: ' . $error, 502);
    }

    // Auf das Zeitfenster beschränken und nach Plan sortieren. Die MVG
    // liefert eine feste Anzahl, nicht ein Zeitfenster.
    $von = strtotime($date . ' ' . $time . ' Europe/Berlin');
    $bis = $von + $duration * 60;
    $entries = array_values(array_filter($entries, static function ($e) use ($von, $bis) {
        $t = strtotime((string) $e['planned']);
        return $t !== false && $t >= $von - 60 && $t <= $bis;
    }));
    usort($entries, static fn($a, $b) => strcmp((string) $a['planned'], (string) $b['planned']));

    $payload = [
        'station'    => $info,
        'arrivals'   => $arrivals,
        'departures' => $entries,
        'sources'    => $sources,
        'until'      => date('c', $bis),
    ];
    $cache->set($key, $payload);
    ok($payload + ['cached' => false]);
}

/**
 * MVG-Abfahrten in eine HAFAS-Tafel einsortieren.
 *
 * Gleiche Linie, gleiche Planminute: derselbe Zug. Dann bekommt der
 * HAFAS-Eintrag die Ist-Zeit der MVG, falls er selbst keine hat, und
 * behält seine jid. Alles andere - U-Bahn, Tram, Bus - kommt dazu.
 */
function mergeBoards(array $hafas, array $mvg): array
{
    // "RB 16" (MVG) und "RB16" (HAFAS) sind dieselbe Linie.
    $key = static fn(array $e): string => mb_strtolower(str_replace(' ', '', (string) $e['line']))
        . '|' . substr((string) $e['planned'], 0, 16);
    $index = [];
    foreach ($hafas as $i => $e) {
        $index[$key($e)] = $i;
    }
    foreach ($mvg as $e) {
        $k = $key($e);
        if (!isset($index[$k])) {
            $hafas[] = $e;
            continue;
        }
        $i = $index[$k];
        if ($hafas[$i]['real'] === null && $e['real'] !== null) {
            $hafas[$i]['real']  = $e['real'];
            $hafas[$i]['delay'] = $e['delay'];
        }
        if ($e['cancelled']) {
            $hafas[$i]['cancelled'] = true;
        }
        if (!empty($e['remarks'])) {
            $hafas[$i]['remarks'] = $e['remarks'];
        }
        $hafas[$i]['source'] = 'hafas+mvg';
    }
    return $hafas;
}

/** Zugnummer des ersten Zuges einer Verbindung, '' wenn unbekannt. */
function firstTrainNumber(array $journey): string
{
    foreach (($journey['legs'] ?? []) as $leg) {
        if (($leg['mode'] ?? '') === 'train') {
            return trim((string) ($leg['trainNumber'] ?? ''));
        }
    }
    return '';
}

/**
 * Kurzbezeichnungen der Züge einer Verbindung, z.B. ["ICE 599", "RE 5"].
 *
 * @return string[]
 */
function trainLabels(array $journey): array
{
    $out = [];
    foreach (($journey['legs'] ?? []) as $leg) {
        if (($leg['mode'] ?? '') !== 'train') {
            continue;
        }
        // Dieselbe Regel wie trainLabel() im Frontend: im Linienverkehr
        // benennt die LINIE den Zug ("S 33", "U5"), im Fernverkehr die
        // Zugnummer ("ICE 593"). Vorher stand hier `trainNumber ?? line` -
        // ein leerer String ist aber nicht null, der Rückfall griff nie, und
        // aus der U5 wurde ein nacktes "U".
        $cat  = trim((string) ($leg['category'] ?? ''));
        $line = trim((string) ($leg['line'] ?? ''));
        $num  = trim((string) ($leg['trainNumber'] ?? ''));

        // Eine Linie, die mit einem Buchstaben beginnt ("S5", "RE3", "U5"),
        // benennt sich selbst - die Gattung davor waere hoechstens falsch:
        // HAFAS fuehrt die Muenchner S-Bahn unter der Gattung "DB", und
        // "DB S5" steht auf keinem Bahnsteig. Nur eine rein numerische Linie
        // ("33") braucht die Gattung davor.
        if ($line !== '') {
            $label = $cat !== '' && preg_match('/^\d/', $line) ? $cat . ' ' . $line : $line;
        } else {
            $label = trim($cat . ' ' . $num);
        }
        if ($label !== '') {
            $out[] = $label;
        }
    }
    return $out;
}

/**
 * Wechselkurse der Europäischen Zentralbank.
 *
 * WOZU: Die Preise kommen von der DB und damit in Euro. Wer eine Fahrt
 * München-Zürich einordnen will, denkt aber in beiden Währungen. Ein
 * Gegenwert in Franken beantwortet das, ohne dass wir Preise aus einer
 * zweiten Quelle bräuchten.
 *
 * WARUM DIE EZB: keine Registrierung, kein Schlüssel, offizieller
 * Referenzkurs, und sie nennt das Datum dazu. Der Referenzkurs ist bewusst
 * KEIN Bankkurs - beim Kartenzahlen kommen Aufschläge dazu. Deshalb wird er
 * in der Anzeige auch als Näherung gekennzeichnet.
 *
 * Die Kurse werden einmal am Werktag gegen 16 Uhr veröffentlicht; sechs
 * Stunden Cache sind entsprechend großzügig genug.
 */
function handleFxRate(Http $http, array $config, Cache $cache): void
{
    $key    = 'fx:ecb';
    $cached = $cache->get($key, (int) ($config['cache_ttl']['fxrate'] ?? 21600));
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $res = $http->request('GET', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
        ['Accept' => 'application/xml']);
    if (!$res['ok'] || !is_string($res['body']) || $res['body'] === '') {
        // Kurse sind Beiwerk - ohne sie fehlt nur der Gegenwert.
        ok(['base' => 'EUR', 'rates' => [], 'date' => null, 'error' => 'EZB nicht erreichbar']);
    }

    $rates = [];
    if (preg_match_all("/currency='([A-Z]{3})'\s+rate='([0-9.]+)'/", $res['body'], $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $rates[$hit[1]] = (float) $hit[2];
        }
    }
    if ($rates === []) {
        ok(['base' => 'EUR', 'rates' => [], 'date' => null, 'error' => 'Kursliste unlesbar']);
    }

    preg_match("/time='(\d{4}-\d{2}-\d{2})'/", $res['body'], $d);

    $payload = [
        'base'  => 'EUR',
        'rates' => $rates,
        'date'  => $d[1] ?? null,
        'note'  => 'EZB-Referenzkurs, kein Bankkurs.',
    ];
    $cache->set($key, $payload);
    ok($payload + ['cached' => false]);
}

/**
 * Bahnsteige eines Bahnhofs, für den Umstiegsplan.
 *
 * Beantwortet die Frage, die bei vier Minuten Umsteigezeit wirklich zählt:
 * liegen Ankunfts- und Abfahrtsgleis nebeneinander oder an entgegengesetzten
 * Enden, und muss ich dabei die Ebene wechseln.
 *
 * Quelle ist OpenStreetMap. Das ist bewusst KEIN Gebäudeplan - Treppen und
 * Laufwege sind dort zu lückenhaft erfasst, um daraus einen Fußweg zu
 * rechnen. Geliefert werden Lage und Ebene der Bahnsteige, alles Weitere
 * wäre geraten.
 */
/**
 * Große Baustellen im Netz, mit betroffenem Abschnitt und Zeitraum.
 *
 * ZWEI QUELLEN, weil keine allein reicht:
 *
 *   strecken.info (DB InfraGO) für DEUTSCHLAND. Liefert Totalsperrungen im
 *   ganzen Netz, mit Betriebsstelle, Zeitraum, Art der Arbeiten und
 *   Streckennummer.
 *
 *   HAFAS Information Manager der ÖBB für OESTERREICH und die Schweiz.
 *
 * Deutschland steht vorn: das Netz ist das größte im deutschsprachigen
 * Raum, und die österreichische Quelle liefert ohnehin fast nur Meldungen
 * zu Nebenbahnen. Fällt eine Quelle aus, bleibt die andere - eine leere
 * Liste gibt es nur, wenn beide schweigen.
 *
 * STRECKENVERLAUF: Die ÖBB liefert ihn mit. Für die deutschen Abschnitte
 * wird er über die Streckennummer aus OpenStreetMap geholt (RailGeometry),
 * nach und nach und dauerhaft gecacht. Wo er fehlt, zeichnet die Karte die
 * Verbindung der beiden Endpunkte.
 */
function handleWorks(Http $http, array $config, Cache $cache): void
{
    $days = max(1, min(90, (int) ($_GET['days'] ?? 30)));

    $key    = 'works:' . $days;
    $cached = $cache->get($key, (int) ($config['cache_ttl']['works'] ?? 3600));
    if ($cached !== null) {
        ok(['works' => $cached, 'cached' => true]);
    }

    $works  = [];
    $fehler = [];

    // --- Deutschland ---------------------------------------------------
    if (($config['providers']['streckeninfo']['enabled'] ?? true) === true) {
        $si  = new StreckenInfo($http, $cache, $config['providers']['streckeninfo'] ?? []);
        $res = $si->works($days);
        if ($res['ok']) {
            $works = array_merge($works, $res['data']);
        } else {
            $fehler[] = $res['error'];
        }
    }

    // --- Österreich und Schweiz ---------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $res  = $oebb->works($days);
    if ($res['ok']) {
        $works = array_merge($works, $res['data']);
    } else {
        $fehler[] = $res['error'];
    }

    if ($works === []) {
        // Beiwerk - ohne Baustellenliste funktioniert alles andere weiter.
        ok(['works' => [], 'error' => implode('; ', array_filter($fehler))]);
    }

    // Die wichtigsten zuerst. "Wichtig" heißt hier dreierlei, in dieser
    // Reihenfolge: Deutschland, dann Fernverkehr, dann Dauer.
    //
    // Die Reihenfolge zählt, weil die Liste nur die ersten Einträge zeigt.
    // Nach Dauer allein standen dort österreichische Nebenbahnen mit den
    // längsten Sperrungen - richtig sortiert, aber nicht das, was jemand
    // sucht, der wissen will, wo im Netz gerade gebaut wird.
    usort($works, static function (array $a, array $b): int {
        $land = (int) (($b['country'] ?? '') === 'de') <=> (int) (($a['country'] ?? '') === 'de');
        if ($land !== 0) {
            return $land;
        }
        $fern = (int) ($b['longDistance'] ?? false) <=> (int) ($a['longDistance'] ?? false);
        if ($fern !== 0) {
            return $fern;
        }
        $da = strtotime((string) $a['end']) - strtotime((string) $a['start']);
        $db = strtotime((string) $b['end']) - strtotime((string) $b['start']);
        return $db <=> $da;
    });

    // Streckenverlauf ergänzen, soweit noch nicht bekannt. Nur für die
    // vorderen Einträge, und die Ergebnisse halten dreißig Tage - der
    // Verlauf einer Strecke ändert sich nicht.
    if (($config['providers']['overpass']['enabled'] ?? false) === true) {
        $rg = new RailGeometry($http, $cache, $config['providers']['overpass']);
        $works = $rg->enrich($works);
    }

    $cache->set($key, $works);
    ok(['works' => $works, 'cached' => false]);
}

function handlePlatforms(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['overpass']['enabled'] ?? false) !== true) {
        ok(['platforms' => [], 'note' => 'Overpass-Provider ist abgeschaltet.']);
    }

    $lat = (float) ($_GET['lat'] ?? 0);
    $lon = (float) ($_GET['lon'] ?? 0);
    if ($lat === 0.0 || $lon === 0.0 || abs($lat) > 90 || abs($lon) > 180) {
        fail('Parameter "lat" und "lon" sind erforderlich.', 400);
    }

    $station = stationData($http, $config, $cache, $lat, $lon, $why);
    if ($station === null) {
        // Grund mitgeben: ohne ihn ist im Betrieb nicht zu unterscheiden, ob
        // Overpass überlastet war oder der Bahnhof schlicht nicht kartiert ist.
        ok(['platforms' => [], 'error' => $why ?? 'Bahnhofsdaten nicht verfügbar.']);
    }

    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));

    // Ohne Gleisangaben nur die Bahnsteige - dann will jemand bloß wissen,
    // was der Bahnhof überhaupt hat. Das ist inzwischen der Normalfall:
    // der Plan wird an JEDEM Umstieg angeboten, und die Gleisnummer steht
    // im Fahrplan längst nicht immer.
    if ($from === '' || $to === '') {
        ok([
            'platforms'   => $station['platforms'],
            'trackPoints' => $station['trackPoints'] ?? [],
            'connectors'  => $station['connectors'] ?? [],
        ]);
    }

    $find = static function (array $platforms, string $track): ?array {
        foreach ($platforms as $p) {
            foreach ($p['tracks'] as $t) {
                if ((string) $t === $track) {
                    return $p;
                }
            }
        }
        return null;
    };

    $a = $find($station['platforms'], $from);
    $b = $find($station['platforms'], $to);

    // KEIN LAUFWEG MEHR. Früher wurde hier aus den Fußwegen und Treppen
    // von OpenStreetMap der genaue Weg von Bahnsteig zu Bahnsteig gerechnet
    // und samt Meter- und Minutenangabe angezeigt. Die Rechnung stand und
    // fiel damit, wie vollständig ein Bahnhof innen kartiert ist - und das
    // ist er fast nirgends. Herausgekommen sind zu oft Wege, die es so nicht
    // gibt, und Zahlen, die genauer aussahen als sie waren. Der Plan zeigt
    // jetzt nur noch die LAGE der beiden Bahnsteige; wie man dazwischen
    // läuft, sieht man auf der Karte selbst.
    ok([
        'platforms' => $station['platforms'],
        // Der Punkt auf dem Gleis, wo OSM einen Haltepunkt kennt. Er ist die
        // bessere Markierung als der Schwerpunkt der Bahnsteigfläche - siehe
        // Overpass::stationData().
        'trackPoints' => $station['trackPoints'] ?? [],
        // Treppen, Rolltreppen und Aufzüge - wo es von Ebene zu Ebene geht.
        'connectors'  => $station['connectors'] ?? [],
        // Damit die Anzeige "gleicher Bahnsteig" von "andere Seite der Halle"
        // unterscheiden kann.
        'samePlatform' => $a !== null && $a === $b,
    ]);
}

/**
 * Die Bahnsteige eines Bahnhofs, gecacht.
 *
 * Auf drei Nachkommastellen gerundet (~100 m): Anfragen zum selben Bahnhof
 * treffen denselben Cache-Eintrag, auch wenn die Quellen leicht abweichende
 * Mittelpunkte melden. Bahnsteige bewegen sich nicht, deshalb eine Woche -
 * das schont den Gemeinschaftsdienst Overpass.
 *
 * @return ?array{platforms:array,trackPoints:array}
 */
function stationData(Http $http, array $config, Cache $cache, float $lat, float $lon, ?string &$error = null): ?array
{
    $key  = sprintf('station:%.3f,%.3f', $lat, $lon);
    $long = (int) ($config['cache_ttl']['platforms'] ?? 604800);

    $cached = $cache->get($key, $long);
    // Einträge von vor den Treppen und Aufzügen einmal neu holen - sonst
    // fehlten sie eine Woche lang an jedem schon besuchten Bahnhof.
    if ($cached !== null && !array_key_exists('connectors', $cached)) {
        $cached = null;
    }
    if ($cached !== null) {
        // Ein LEERES Ergebnis darf nicht eine Woche lang gelten. Overpass
        // antwortet unter Last mit Zeitüberschreitungen; die Antwort ist dann
        // formal in Ordnung, aber leer. Ohne diese Unterscheidung merkt sich
        // der Cache einen einmaligen Aussetzer als "Bahnhof nicht kartiert" -
        // und der Umstiegsplan bleibt tagelang weg.
        $leer = ($cached['platforms'] ?? []) === [];
        $alter = time() - (int) ($cached['ts'] ?? 0);
        if (!$leer || $alter < 900) {
            return $cached;
        }
    }

    $op  = new Overpass($http, $config['providers']['overpass']);
    $res = $op->stationData($lat, $lon);
    if (!$res['ok']) {
        $error = $res['error'];
        return null;
    }

    $cache->set($key, $res['data'] + ['ts' => time()]);
    return $res['data'];
}

function handleJourneys(Http $http, array $config, Cache $cache): void
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));
    $time = trim((string) ($_GET['time'] ?? '08:00'));

    if ($from === '' || $to === '') {
        fail('Parameter "from" und "to" sind erforderlich (EVA-Nummern).', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Parameter "date" muss YYYY-MM-DD sein.', 400);
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        fail('Parameter "time" muss HH:MM sein.', 400);
    }

    $arrival     = ($_GET['arrival'] ?? '0') === '1';
    $travelClass = ((string) ($_GET['class'] ?? '2')) === '1' ? 1 : 2;
    $results     = max(1, min(12, (int) ($_GET['results'] ?? 8)));

    $discounts = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['discounts'] ?? ''))),
        static fn($d) => $d !== ''
    ));

    $viaIds = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['via'] ?? ''))),
        static fn($v) => $v !== ''
    ));

    // Verkehrsmittel-Auswahl. Fehlt der Parameter, ist alles erlaubt.
    $products = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_GET['products'] ?? ''))),
        static fn($p) => $p !== '' && in_array($p, Products::allIds(), true)
    ));

    // Mindestumsteigezeit. Unter einer Minute ist keine Umsteigezeit, deshalb
    // ist 1 die Untergrenze; 60 Minuten sind die sinnvolle Obergrenze.
    $minChange = isset($_GET['minchange'])
        ? max(1, min(60, (int) $_GET['minchange']))
        : null;

    // Blättern: Kontext aus der vorigen Antwort. HAFAS liefert je Anfrage
    // nur rund sechs Treffer, weitere Abfahrten gibt es nur so. Der Kontext
    // trägt seine Richtung selbst - der aus 'scrollBack' führt zu früheren
    // Abfahrten, der aus 'scroll' zu späteren.
    $scroll = trim((string) ($_GET['scroll'] ?? ''));

    // ECHTZEIT-ROUTING für alles, was bald fährt. Wer jetzt losfahren will,
    // braucht keine Verbindung, deren Anschluss eine Verspätung längst
    // gekappt hat - und keine, die über einen ausgefallenen Zug führt. Für
    // die Suche nach nächster Woche gibt es keine Echtzeitlage; dort bleibt
    // es beim Fahrplan.
    $realtime = isNearNow($date, $time);

    $cacheKey = 'jny:' . implode('|', [
        $realtime ? 'rt' : 'plan',
        $from, $to, $date, $time, $arrival ? 'a' : 'd',
        $travelClass, $results, implode('+', $discounts), implode('+', $viaIds),
        implode('+', $products), $minChange ?? '-',
        $scroll === '' ? '-' : substr(sha1($scroll), 0, 12),
    ]);
    $cached = $cache->get($cacheKey, (int) $config['cache_ttl']['journeys']);
    if ($cached !== null) {
        ok($cached + ['cached' => true]);
    }

    $notices = [];

    // --- 0. Stadtfahrt oder Zubringer in München? ----------------------
    //
    // Siehe CityTrips. Kurz: liegen beide Enden im MVG-Netz, sucht (auch)
    // die MVG. Ist nur ein Ende ein reiner MVG-Halt, sucht HAFAS ab bzw. bis
    // München Hbf, und die MVG liefert das Stück dazu.
    $coord = static fn(string $k): ?float => is_numeric($_GET[$k] ?? null) ? (float) $_GET[$k] : null;
    $mvg = ($config['providers']['mvg']['enabled'] ?? false) === true
        ? new Mvg($http, $config['providers']['mvg']) : null;
    $city = null;          // 'local' | 'from' | 'to'
    $fromGid = null;
    $toGid = null;
    $hubGid = null;
    $fromIsMvg = str_starts_with($from, 'mvg:');
    $toIsMvg   = str_starts_with($to, 'mvg:');
    $mvgScroll = CityTrips::parseScroll($scroll);
    if ($mvg !== null && $viaIds === []) {
        $fromGid = CityTrips::station($mvg, $cache, $from, $coord('fromLat'), $coord('fromLon'));
        if ($fromGid !== null || $toIsMvg) {
            $toGid = CityTrips::station($mvg, $cache, $to, $coord('toLat'), $coord('toLon'));
        }
        if ($fromGid !== null && $toGid !== null && $fromGid !== $toGid) {
            $city = 'local';
        } elseif ($fromIsMvg xor $toIsMvg) {
            $hubGid = CityTrips::hub($mvg, $cache);
            if ($hubGid !== null && ($fromIsMvg ? $fromGid : $toGid) !== null) {
                $city = $fromIsMvg ? 'from' : 'to';
            }
        }
    }

    // Stadtfahrt mit einem reinen MVG-Halt: den kennt HAFAS nicht, dann
    // bleibt es bei der MVG allein. Ebenso beim Weiterblättern einer solchen
    // Liste - der Kontext ist dann ein Zeitpunkt, siehe CityTrips::scrollFor().
    $localJourneys = [];
    $mvgOnly = $city === 'local' && ($fromIsMvg || $toIsMvg || $mvgScroll !== null);

    // Zubringer: HAFAS sucht ab bzw. bis München Hbf. Auf der ersten Seite
    // verschiebt sich dabei die Uhrzeit um die Fahrt in der Stadt - wer um
    // 8:00 am Odeonsplatz losfährt, erreicht keinen ICE um 8:01.
    $hFrom = $from;
    $hTo   = $to;
    $hDate = $date;
    $hTime = $time;
    if ($city === 'from' || $city === 'to') {
        if ($city === 'from') {
            $hFrom = CityTrips::HUB_EVA;
        } else {
            $hTo = CityTrips::HUB_EVA;
        }
        $verschieben = $scroll === '' && (($city === 'from' && !$arrival) || ($city === 'to' && $arrival));
        if ($verschieben) {
            $iso = CityTrips::utc($date, $time);
            $probe = $iso !== null
                ? $mvg->routes($city === 'from' ? $fromGid : $hubGid, $city === 'from' ? $hubGid : $toGid, $iso, 1, $arrival)
                : ['data' => []];
            $dauer = (int) ($probe['data'][0]['durationMin'] ?? 15) + CityTrips::TRANSFER_MIN;
            try {
                $t = (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Europe/Berlin')))
                    ->modify(sprintf('%+d minutes', $city === 'from' ? $dauer : -$dauer));
                $hDate = $t->format('Y-m-d');
                $hTime = $t->format('H:i');
            } catch (Exception) {
                // Bleibt bei der eingegebenen Zeit.
            }
        }
        $notices[] = $city === 'from'
            ? 'Fernverkehr ab ' . CityTrips::HUB_NAME . ', dorthin mit U-Bahn, Tram oder Bus (MVG).'
            : 'Fernverkehr bis ' . CityTrips::HUB_NAME . ', von dort weiter mit U-Bahn, Tram oder Bus (MVG).';
    }

    // --- 1. Fahrplan von der ÖBB -------------------------------------
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $hScroll = $scroll === '' || $mvgScroll !== null ? null : $scroll;
    if ($mvgOnly) {
        // Reiner MVG-Halt oder MVG-Blätterkontext: HAFAS kann damit nichts.
        $sched = ['ok' => true, 'error' => null, 'data' => [], 'scrollF' => null, 'scrollB' => null];
    } else {
        $sched = $oebb->journeys(
            $hFrom, $hTo, $hDate, $hTime, $arrival, $results, $travelClass, $viaIds,
            Products::bitmask($products), $minChange, $hScroll, $realtime
        );
        // Findet die Echtzeitsuche gar nichts, zeigt der Fahrplan wenigstens,
        // was eigentlich fahren sollte - samt Ausfall-Kennzeichnung und Ersatz.
        if ($realtime && $sched['ok'] && $sched['data'] === []) {
            $sched = $oebb->journeys(
                $hFrom, $hTo, $hDate, $hTime, $arrival, $results, $travelClass, $viaIds,
                Products::bitmask($products), $minChange, $hScroll
            );
        }
    }

    $journeys    = $sched['ok'] ? $sched['data'] : [];

    // Beim Weiterblättern liegt das Zeitfenster woanders als in $time. Für
    // die Preisabfrage zählt, wann die gelieferten Verbindungen tatsächlich
    // fahren - sonst holt die DB Preise für den falschen Tagesabschnitt.
    $priceDate = $hDate;
    $priceTime = $hTime;
    if ($scroll !== '' && $journeys !== []) {
        $firstDep = $journeys[0]['departure'] ?? null;
        if ($firstDep !== null) {
            try {
                $d = new DateTimeImmutable($firstDep);
                $priceDate = $d->format('Y-m-d');
                $priceTime = $d->format('H:i');
            } catch (Exception $e) {
                // Bleibt beim ursprünglichen Zeitfenster.
            }
        }
    }
    // Stadtfahrt: die MVG-Treffer für dasselbe Zeitfenster. Beim Blättern
    // einer gemischten Liste ist das der Anfang der neuen HAFAS-Seite.
    if ($city === 'local') {
        [$lDate, $lTime] = $mvgScroll ?? [$priceDate, $priceTime];
        $iso = CityTrips::utc($lDate, $lTime);
        $lr = $iso !== null
            ? $mvg->routes($fromGid, $toGid, $iso, max(4, $results), $arrival && $scroll === '')
            : ['ok' => false, 'data' => [], 'error' => null];
        foreach ($lr['data'] as $j) {
            $j['id'] = CityTrips::stableId($j);
            $j = annotateTransfers($j);
            $j['trains'] = trainLabels($j);
            $localJourneys[] = $j;
        }
    }

    $priceSource = 'estimate';
    $dbEnabled   = ($config['providers']['db']['enabled'] ?? false) === true && !$mvgOnly;
    $db          = $dbEnabled ? new DbVendo($http, $config['providers']['db']) : null;

    // --- 2. Preise von der DB, notfalls auch den Fahrplan --------------
    //
    // Die ÖBB kennt nur Stationen mit echter EVA-Nummer. Nahverkehrshalte
    // wie "Sendlinger Tor, München" haben lokale Kennungen und werden dort
    // mit "location missing or invalid" abgelehnt. Die DB kennt sie - also
    // übernimmt sie in dem Fall auch den Fahrplan.
    if ($db !== null) {
        $priced = $db->journeys(
            $hFrom, $hTo, $priceDate, $priceTime, $arrival, $travelClass, $discounts, true, $products, $minChange
        );

        if ($journeys === [] && $priced['ok'] && $priced['data'] !== []) {
            $journeys    = $priced['data'];
            $priceSource = 'db';
            $notices[]   = 'Fahrplan von der DB — die ÖBB kennt diese Station nicht. '
                         . 'Auf der Karte fehlt dadurch der genaue Streckenverlauf.';
        } elseif ($journeys !== [] && $priced['ok'] && $priced['data'] !== []) {
            // Läuft auch ohne Preise: der Merge bringt Echtzeit und Auslastung.
            $matched = mergePrices($journeys, $priced['data']);
            if ($matched > 0) {
                $priceSource = 'db';
                $notices[]   = $matched . ' von ' . count($journeys) . ' Verbindungen mit Echtpreis der DB.';
            }

            // Nur BahnCards rechnet die DB selbst. Alles andere kommt aus
            // unserem Modell - das gehört transparent gemacht.
            $ownAbos = array_values(array_diff($discounts, $priced['usedDiscounts']));
            if ($matched > 0 && $ownAbos !== []) {
                $notices[] = 'Die DB kennt nur BahnCards. '
                    . implode(', ', $ownAbos)
                    . ' wird auf den Echtpreis hochgerechnet und ist damit eine Schätzung.';
            }
        } elseif (!$priced['ok'] && $journeys !== []) {
            $notices[] = $priced['error'];
        }
    }

    if ($journeys === [] && $localJourneys === []) {
        if (!$sched['ok'] && $db === null) {
            fail('Fahrplanabfrage fehlgeschlagen: ' . $sched['error'], 502);
        }
        $hint = $products !== [] || $viaIds !== []
            ? 'Keine Verbindungen gefunden. Vielleicht sind die Filter zu eng.'
            : 'Keine Verbindungen gefunden.';
        ok([
            'journeys' => [], 'priceSource' => $priceSource,
            'notices' => $scroll === '' ? [$hint] : [],
            'scroll' => null, 'scrollBack' => null, 'cached' => false,
        ]);
    }

    // --- 3. Baureihe ergänzen ------------------------------------------
    //
    // ZWEI STUFEN. Die Wagenreihung ist die harte Quelle, aber sie gilt nur
    // am Reisetag, nur für deutschen Fernverkehr und - aus Rücksicht auf
    // bahn.expert - für höchstens drei Züge je Verbindung. Alles, was sie je
    // geliefert hat, merkt sich Fleet unter der Zugnummer und füllt damit
    // auch die Abschnitte, für die gerade nicht gefragt werden konnte: die
    // vierte Etappe, die Rückfahrt, die Suche für nächsten Dienstag.
    $fleet = new Fleet((string) $config['cache_dir']);
    $lernt = $fleet->isAvailable();

    // Erst das Gelernte einsetzen - was hier schon steht, muss gar nicht
    // erst abgefragt werden.
    if ($lernt) {
        foreach ($journeys as $i => $j) {
            $journeys[$i] = $fleet->fill($j);
        }
    }

    if (($config['providers']['wagenreihung']['enabled'] ?? false) === true) {
        $cs = new CoachSequence($http, $config['providers']['wagenreihung'], $cache);
        // Alle Verbindungen auf einmal: die Abfragen laufen gleichzeitig und
        // doppelte Züge werden nur einmal geholt - siehe enrichAll().
        $journeys = $cs->enrichAll($journeys, $date);
    }

    if ($lernt) {
        foreach ($journeys as $j) {
            $fleet->learn($j);
        }
        $fleet->flush();
    }

    // --- 4. Umstiege bewerten, zu knappe aussortieren ------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = annotateTransfers($j);
    }

    // Beide Quellen kennen eine Mindestumsteigezeit und wurden entsprechend
    // gefragt. Dieser Nachfilter ist nur das Sicherheitsnetz, falls doch
    // etwas Zu-Knappes durchrutscht. Bleibt nichts übrig, zeigen wir lieber
    // die knappen Verbindungen als eine leere Liste.
    if ($minChange !== null) {
        $kept = array_values(array_filter(
            $journeys,
            static fn($j) => ($j['minTransferMin'] ?? null) === null
                          || $j['minTransferMin'] >= $minChange
        ));
        if ($kept !== []) {
            $journeys = $kept;
        } elseif ($journeys !== []) {
            $notices[] = 'Keine Verbindung erreicht ' . $minChange
                . ' Minuten Umsteigezeit — es werden die knapperen gezeigt.';
        }
    }

    // --- 5. Abos anwenden bzw. schätzen ------------------------------
    foreach ($journeys as $i => $j) {
        $journeys[$i] = Fares::apply($journeys[$i], $discounts, $travelClass);
        // Ticketshops der berührten Länder, Startland zuerst.
        $journeys[$i]['shops'] = Shops::forJourney($journeys[$i], $date, $time, $travelClass);
    }

    // Pünktlichkeitshistorie, soweit wir schon welche gesammelt haben.
    $punct = new Punctuality((string) $config['cache_dir']);
    if ($punct->isAvailable()) {
        foreach ($journeys as $i => $j) {
            $h = $punct->forJourney($j);
            if ($h !== []) {
                $journeys[$i]['history'] = $h;
            }
        }
    }

    // --- 6. München: Zubringer anhängen, Stadtfahrten dazunehmen -------
    if ($city === 'from' || $city === 'to') {
        $mitZubringer = CityTrips::attachFeeders(
            $mvg, $city === 'from' ? $fromGid : $toGid, $hubGid, $journeys, $city
        );
        if ($mitZubringer !== []) {
            // Der Umstieg zwischen U-Bahn und Fernzug zählt jetzt mit.
            $journeys = array_map('annotateTransfers', $mitZubringer);
        } elseif ($journeys !== []) {
            $notices[] = 'Für das Stück in der Stadt hat die MVG gerade keine Verbindung geliefert - '
                . 'gezeigt ist nur der Fernverkehr ab bzw. bis ' . CityTrips::HUB_NAME . '.';
        }
    }

    $scrollF = $sched['scrollF'] ?? null;
    $scrollB = $sched['scrollB'] ?? null;
    if ($localJourneys !== []) {
        $journeys = CityTrips::merge($journeys, $localJourneys, 'trainLabels');
        // Reine Stadtfahrt: kein Preis zu schätzen, die MVG nennt Tarifzonen.
        // Ohne das stünde oben "alle Preise sind Schätzungen".
        if ($mvgOnly && $priceSource === 'estimate') {
            $priceSource = 'mvv';
        }
        if ($mvgOnly || ($scrollF === null && $scrollB === null)) {
            // Die MVG blättert nicht; weiter geht es ab der letzten Abfahrt.
            // Ebenso, wenn der Rest der Liste von der DB kam - die liefert
            // keinen Blätterkontext, den HAFAS verstünde.
            $scrollF = CityTrips::scrollFor(end($localJourneys)['departure'] ?? null, 1);
            $scrollB = CityTrips::scrollFor($localJourneys[0]['departure'] ?? null, -30);
        }
        if ($scroll === '') {
            $notices[] = 'Stadtfahrt: Verbindungen mit U-Bahn, Tram und Bus von der MVG. '
                . 'Im ganzen MVV gilt das Deutschlandticket.';
        }
    }

    $payload = [
        'journeys'    => $journeys,
        'priceSource' => $priceSource,
        'discounts'   => $discounts,
        'notices'     => $notices,
        // Womit sich die nächste Seite holen lässt; null = Ende der Fahne.
        'scroll'      => $scrollF,
        // Dasselbe rückwärts, für den Knopf "Frühere Verbindungen".
        'scrollBack'  => $scrollB,
    ];

    // Leere Ergebnisse NICHT cachen. Sonst friert eine einmalige leere
    // Antwort (temporärer Provider-Aussetzer, kaputter Konfig-Zustand,
    // exotische Kombination) den Nutzer für die nächsten Minuten in
    // "0 Verbindungen" ein, obwohl schon der nächste Live-Aufruf wieder
    // Treffer hätte.
    if ($journeys !== []) {
        $cache->set($cacheKey, $payload);
    }
    ok($payload + ['cached' => false]);
}

// ======================================================================
// Hilfsfunktionen
// ======================================================================

/**
 * Ordnet DB-Daten den ÖBB-Verbindungen zu.
 *
 * Gematcht wird über Abfahrts- UND Ankunftszeit mit 4 Minuten Toleranz -
 * damit erwischen wir dieselbe Verbindung auch dann, wenn die beiden Systeme
 * bei Echtzeitdaten leicht auseinanderliegen.
 *
 * WICHTIG: Die Zuordnung läuft über ALLE DB-Treffer, nicht nur über die
 * mit Preis. Die DB liefert Echtzeit und Auslastung auch dann, wenn sie die
 * Relation nicht verkauft - nachts oder bei Auslandsverbindungen ist das der
 * Normalfall. Würden wir preislose Treffer überspringen, ginge genau dort
 * die Verspätungsanzeige verloren, wo sie am meisten hilft.
 *
 * @param array $journeys wird per Referenz um Preise und Echtzeit ergänzt
 * @return int Anzahl zugeordneter ECHTPREISE (nicht: zugeordneter Treffer)
 */
function mergePrices(array &$journeys, array $priced): int
{
    $count = 0;

    foreach ($journeys as $i => $journey) {
        $depA = toTimestamp($journey['departure'] ?? null);
        $arrA = toTimestamp($journey['arrival'] ?? null);
        if ($depA === null || $arrA === null) {
            continue;
        }

        $best     = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($priced as $p) {
            $depB = toTimestamp($p['departure'] ?? null);
            $arrB = toTimestamp($p['arrival'] ?? null);
            if ($depB === null || $arrB === null) {
                continue;
            }

            $diff = abs($depA - $depB) + abs($arrA - $arrB);
            if ($diff <= 480 && $diff < $bestDiff) { // 480 s = 4 min je Seite
                $bestDiff = $diff;
                $best     = $p;
            }
        }

        if ($best === null) {
            continue;
        }

        // Echtzeit, Auslastung und Deutschlandticket hängen nicht am Preis.
        mergeLegFlags($journeys[$i]['legs'], $best['legs'] ?? []);

        // Der Schlüssel zu allen Tarifen dieser Verbindung - siehe handleOffers().
        if (!empty($best['dbRecon'])) {
            $journeys[$i]['dbRecon'] = $best['dbRecon'];
        }

        if (($best['price'] ?? null) !== null) {
            $journeys[$i]['price']      = $best['price'];
            $journeys[$i]['bookingUrl'] = $journey['bookingUrl'] ?? $best['bookingUrl'] ?? null;
            $count++;
        }
    }

    return $count;
}

/**
 * Überträgt DB-spezifische Angaben auf die ÖBB-Abschnitte.
 *
 * Drei Fälle, die HAFAS nicht liefert:
 *
 *   1. Die DB markiert selbst, auf welchen Teilstrecken das
 *      Deutschlandticket gilt. Ohne diese Übertragung wüsste Fares.php
 *      nichts davon und müsste allein anhand der Gattung raten.
 *   2. Auslastungsangaben.
 *   3. ECHTZEIT. Die DB schickt Ist-Zeiten und Verspätungsgründe direkt in
 *      der Suchantwort mit. HAFAS bräuchte dafür je Abschnitt eine eigene
 *      Abfrage - deshalb ist das hier der billigste Weg zu Verspätungen
 *      schon in der Trefferliste.
 *
 * Zugeordnet wird über die Zugnummer, ersatzweise über die Gattung.
 */
function mergeLegFlags(array &$legs, array $dbLegs): void
{
    $byNumber = [];
    $dbTrains = [];
    foreach ($dbLegs as $dl) {
        if (($dl['mode'] ?? '') !== 'train') {
            continue;
        }
        $dbTrains[] = $dl;
        $num = trim((string) ($dl['trainNumber'] ?? ''));
        if ($num !== '') {
            $byNumber[$num] = $dl;
        }
    }

    // Eigene Zugabschnitte in derselben Reihenfolge - Grundlage für den
    // Positionsabgleich weiter unten.
    $ownTrains = [];
    foreach ($legs as $i => $leg) {
        if (($leg['mode'] ?? '') === 'train') {
            $ownTrains[] = $i;
        }
    }
    $sameShape = count($ownTrains) === count($dbTrains);

    foreach ($legs as $i => $leg) {
        if (($leg['mode'] ?? '') !== 'train') {
            continue;
        }
        $num = trim((string) ($leg['trainNumber'] ?? ''));
        $match = $num !== '' ? ($byNumber[$num] ?? null) : null;

        // Bei S-Bahnen nennt die DB die Linie ("S8"), die ÖBB die Zugnummer
        // ("35884") - über die Nummer findet sich da nichts. Haben beide
        // Quellen gleich viele Zugabschnitte, ist die Position eindeutig
        // genug: die Verbindung wurde ja bereits über Ab- UND Ankunftszeit
        // zugeordnet.
        if ($match === null && $sameShape) {
            $pos = array_search($i, $ownTrains, true);
            $match = $pos !== false ? ($dbTrains[$pos] ?? null) : null;
        }

        if ($match === null) {
            continue;
        }
        if (!empty($match['dTicket'])) {
            $legs[$i]['dTicket'] = $match['dTicket'];
        }
        if (!empty($match['occupancy'])) {
            $legs[$i]['occupancy'] = $match['occupancy'];
        }
        if (($leg['operator'] ?? '') === '' && ($match['operator'] ?? '') !== '') {
            $legs[$i]['operator'] = $match['operator'];
        }

        // Echtzeit übernehmen, wenn die DB welche hat.
        foreach (['departureReal', 'arrivalReal', 'delay'] as $k) {
            if (($match[$k] ?? null) !== null) {
                $legs[$i][$k] = $match[$k];
            }
        }
        if (!empty($match['hasRealtime'])) {
            $legs[$i]['hasRealtime'] = true;
        }
        if (!empty($match['remarks'])) {
            $legs[$i]['remarks'] = $match['remarks'];
        }
        // Ausstattung (Bordrestaurant, WLAN, Reservierungspflicht) und
        // gestörte Aufzüge am Ein- und Ausstieg - beides kennt nur die DB.
        if (!empty($match['amenities'])) {
            $legs[$i]['amenities'] = $match['amenities'];
        }
        if (!empty($match['stationNotes'])) {
            $legs[$i]['stationNotes'] = $match['stationNotes'];
        }
        // Zweite Quelle für die Live-Verfolgung: kennt HAFAS für die
        // Münchner S-Bahn nur den Fahrplan, hat die DB oft die Ist-Zeit.
        if (!empty($match['dbJourneyId'])) {
            $legs[$i]['dbJourneyId'] = $match['dbJourneyId'];
        }
    }
}

/**
 * Markiert knappe Umstiege.
 *
 * Die Umsteigezeit ist die Lücke zwischen Ankunft des einen und Abfahrt des
 * nächsten Zuges. Was knapp ist, hängt vom Bahnhof ab - als Faustregel
 * gelten unter 5 Minuten als riskant und unter 10 als knapp. Fußwege
 * zwischen den Zügen werden mitgerechnet, denn die zählen ja auch.
 *
 * Liegen Ist-Zeiten vor (von der DB), wird ZUSAETZLICH mit ihnen gerechnet:
 * ein im Fahrplan bequemer Umstieg kann durch eine Verspätung schon vor der
 * Abfahrt geplatzt sein. Das steht dann als eigener Wert an der Verbindung,
 * damit die Anzeige Plan und Wirklichkeit auseinanderhalten kann.
 *
 * Zusätzlich wird die knappste Umsteigezeit der ganzen Verbindung vermerkt,
 * damit die Liste danach warnen kann.
 */
function annotateTransfers(array $journey): array
{
    $legs = $journey['legs'] ?? [];
    $minGap = null;
    $minLiveGap = null;

    for ($i = 0; $i < count($legs); $i++) {
        if (($legs[$i]['mode'] ?? '') !== 'train') {
            continue;
        }
        // Nächsten Zug suchen; Fußwege dazwischen überspringen.
        $next = null;
        for ($k = $i + 1; $k < count($legs); $k++) {
            if (($legs[$k]['mode'] ?? '') === 'train') {
                $next = $k;
                break;
            }
        }
        if ($next === null) {
            break;
        }

        $arr = toTimestamp($legs[$i]['arrival'] ?? null);
        $dep = toTimestamp($legs[$next]['departure'] ?? null);
        if ($arr === null || $dep === null) {
            continue;
        }

        $gap = (int) round(($dep - $arr) / 60);
        $legs[$next]['transferMin'] = $gap;
        $legs[$next]['transferRisk'] = $gap < 5 ? 'risky' : ($gap < 10 ? 'tight' : 'ok');

        if ($minGap === null || $gap < $minGap) {
            $minGap = $gap;
        }

        // Dasselbe noch einmal mit den Ist-Zeiten, soweit vorhanden.
        $arrReal = toTimestamp($legs[$i]['arrivalReal'] ?? null) ?? $arr;
        $depReal = toTimestamp($legs[$next]['departureReal'] ?? null) ?? $dep;
        if (($legs[$i]['arrivalReal'] ?? null) !== null || ($legs[$next]['departureReal'] ?? null) !== null) {
            $liveGap = (int) round(($depReal - $arrReal) / 60);
            $legs[$next]['transferMinLive'] = $liveGap;
            if ($minLiveGap === null || $liveGap < $minLiveGap) {
                $minLiveGap = $liveGap;
            }
        }
    }

    $journey['legs'] = $legs;
    $journey['minTransferMin'] = $minGap;
    $journey['transferRisk'] = $minGap === null
        ? null
        : ($minGap < 5 ? 'risky' : ($minGap < 10 ? 'tight' : 'ok'));
    $journey['minTransferLive'] = $minLiveGap;

    // Größte Verspätung über alle Abschnitte, für die Trefferliste.
    $delay = null;
    foreach ($legs as $l) {
        if (($l['delay'] ?? null) !== null) {
            $delay = $delay === null ? (int) $l['delay'] : max($delay, (int) $l['delay']);
        }
    }
    $journey['delay'] = $delay;

    // Ist-Abfahrt und Ist-Ankunft der ganzen Reise: erster und letzter Zug.
    $trains = array_values(array_filter($legs, static fn($l) => ($l['mode'] ?? '') === 'train'));
    if ($trains !== []) {
        $journey['departureReal'] = $trains[0]['departureReal'] ?? null;
        $journey['arrivalReal']   = $trains[count($trains) - 1]['arrivalReal'] ?? null;
    }

    return $journey;
}

function toTimestamp(?string $iso): ?int
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * MVG-Störungsticker.
 *
 * Aktive Meldungen der Münchner Verkehrsgesellschaft. Beste Aktualität ist
 * nicht das Ziel - die App zeigt einen Überblick, die Detailseite bleibt
 * die MVG-App. Deshalb kurz cachen (2 Minuten), das schützt auch die MVG.
 *
 * Bei ausgeschaltetem MVG-Provider oder Fehler wird eine leere Liste
 * zurückgegeben statt hart zu scheitern - der Ticker ist Beiwerk, kein
 * Kernfeature.
 */
function handleDisruptions(Http $http, array $config, Cache $cache): void
{
    if (($config['providers']['mvg']['enabled'] ?? false) !== true) {
        ok(['disruptions' => [], 'note' => 'MVG-Provider ist in der Konfiguration deaktiviert.']);
    }

    $key    = 'mvg:disruptions';
    $cached = $cache->get($key, (int) ($config['cache_ttl']['disruptions'] ?? 120));
    if ($cached !== null) {
        ok(['disruptions' => $cached, 'cached' => true]);
    }

    $mvg = new Mvg($http, $config['providers']['mvg']);
    $res = $mvg->messages();
    if (!$res['ok']) {
        // Fehler nicht durchreichen, damit ein MVG-Ausfall die UI nicht bricht.
        ok(['disruptions' => [], 'error' => $res['error']]);
    }

    $cache->set($key, $res['data']);
    ok(['disruptions' => $res['data'], 'cached' => false]);
}

function rateLimitOk(Cache $cache, array $config): bool
{
    $rl = $config['rate_limit'] ?? [];
    if (($rl['enabled'] ?? false) !== true || !$cache->isAvailable()) {
        return true;
    }

    $kosten = RATE_COST[(string) ($_GET['action'] ?? '')] ?? RATE_COST_DEFAULT;
    if ($kosten === 0) {
        return true;
    }

    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $window = (int) $rl['per_secs'];
    $key    = 'rl:' . $ip . ':' . intdiv(time(), $window);

    $hits = (int) ($cache->get($key, $window * 2) ?? 0);
    if ($hits >= (int) $rl['max']) {
        return false;
    }
    $cache->set($key, $hits + $kosten);

    return true;
}

/** @param array<string,mixed> $data */
function ok(array $data): void
{
    echo json_encode(
        ['ok' => true] + $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(
        ['ok' => false, 'error' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
