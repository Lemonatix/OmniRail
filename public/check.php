<?php
/**
 * Selbsttest für den Webspace.
 *
 * Ruf diese Datei nach dem Hochladen einmal im Browser auf:
 *   https://deine-domain.tld/train/check.php
 *
 * Sie sagt dir, ob PHP passt, ob ausgehende Verbindungen erlaubt sind und
 * welche der Datenquellen von deinem Hoster aus erreichbar sind.
 *
 * WICHTIG: Nach erfolgreichem Test löschen oder umbenennen - die Datei gibt
 * Serverdetails preis, die nicht öffentlich sein müssen.
 */

declare(strict_types=1);

require __DIR__ . '/api/lib/Http.php';
require __DIR__ . '/api/lib/Health.php';
require __DIR__ . '/api/lib/Cache.php';
require __DIR__ . '/api/lib/Providers/OebbHafas.php';
require __DIR__ . '/api/lib/Providers/DbVendo.php';
require __DIR__ . '/api/lib/Providers/Mvg.php';

$config = require __DIR__ . '/api/config.php';

/**
 * So viele Aufrufe braucht ein Dienst, bevor seine Fehlerquote etwas
 * bedeutet. Bei zweien ist einer davon "50 %", und danach richtete sich
 * bisher das Gesamturteil der Seite.
 */
const MIN_PROBEN = 5;

$checks = [];

// --- Basis -------------------------------------------------------------

$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks[] = [
    'name'   => 'PHP-Version',
    'state'  => $phpOk ? 'ok' : 'fail',
    'detail' => PHP_VERSION . ($phpOk ? '' : ' - benötigt wird mindestens 8.0'),
    'hint'   => $phpOk ? '' : 'Stell in der Hoster-Verwaltung eine neuere PHP-Version ein.',
];

$curlOk = function_exists('curl_init');
$checks[] = [
    'name'   => 'cURL-Erweiterung',
    'state'  => $curlOk ? 'ok' : 'fail',
    'detail' => $curlOk ? 'verfügbar' : 'fehlt',
    'hint'   => $curlOk ? '' : 'Ohne cURL kann das Tool keine Daten abrufen. Beim Hoster aktivieren lassen.',
];

// Ohne TLS-1.3-Cipher-Steuerung blockt die DB jede Anfrage (Akamai prüft den
// TLS-Fingerprint). Das ist die häufigste Ursache, wenn Preise fehlen.
$tls13 = defined('CURLOPT_TLS13_CIPHERS');
$curlVersion = $curlOk ? (curl_version()['version'] ?? '?') : '?';
$sslVersion  = $curlOk ? (curl_version()['ssl_version'] ?? '?') : '?';
$checks[] = [
    'name'   => 'TLS-1.3-Cipher-Steuerung (für DB-Preise)',
    'state'  => $tls13 ? 'ok' : 'warn',
    'detail' => 'cURL ' . $curlVersion . ' / ' . $sslVersion
        . ($tls13 ? ' - CURLOPT_TLS13_CIPHERS verfügbar' : ' - CURLOPT_TLS13_CIPHERS fehlt'),
    'hint'   => $tls13
        ? ''
        : 'Ohne diese Option lässt sich der TLS-Fingerprint nicht anpassen und die DB antwortet mit 403. '
          . 'Nötig sind cURL 7.61+ mit OpenSSL 1.1.1+. Fahrplan und Schätzpreise funktionieren trotzdem.',
];

$cache   = new Cache((string) $config['cache_dir']);
$cacheOk = $cache->isAvailable();
$checks[] = [
    'name'   => 'Cache-Verzeichnis beschreibbar',
    'state'  => $cacheOk ? 'ok' : 'warn',
    // Kein absoluter Serverpfad: check.php ist öffentlich erreichbar, und
    // wo das Cache-Verzeichnis liegt, geht niemanden etwas an. Ob es
    // beschreibbar ist, ist die Auskunft, um die es geht.
    'detail' => $cacheOk ? 'beschreibbar' : 'NICHT beschreibbar',
    'hint'   => $cacheOk ? '' : 'Das Tool läuft auch ohne Cache, wird aber langsamer und stellt mehr Anfragen. Setz in api/config.php "cache_dir" z.B. auf sys_get_temp_dir().',
];

// --- Datenquellen ------------------------------------------------------

if ($curlOk) {
    $http = new Http(20);

    $t0   = microtime(true);
    $oebb = new OebbHafas($http, $config['providers']['oebb']);
    $r    = $oebb->locations('Zürich', 1);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $checks[] = [
        'name'   => 'ÖBB HAFAS - Fahrplan, Zuggattungen (KRITISCH)',
        'state'  => $r['ok'] ? 'ok' : 'fail',
        'detail' => $r['ok']
            ? 'erreichbar in ' . $ms . ' ms, Testtreffer: ' . ($r['data'][0]['name'] ?? '-')
            : ($r['error'] ?? 'unbekannter Fehler'),
        'hint'   => $r['ok'] ? '' : 'Ohne diese Quelle funktioniert das Tool nicht. Prüf, ob dein Hoster ausgehende HTTPS-Verbindungen erlaubt.',
    ];

    $t0 = microtime(true);
    $db = new DbVendo($http, $config['providers']['db']);
    $r  = $db->locations('Berlin', 1);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $checks[] = [
        'name'   => 'DB bahn.de - Echtpreise (optional)',
        'state'  => $r['ok'] ? 'ok' : 'warn',
        'detail' => $r['ok']
            ? 'erreichbar in ' . $ms . ' ms, Testtreffer: ' . ($r['data'][0]['name'] ?? '-')
            : ($r['error'] ?? 'unbekannter Fehler'),
        'hint'   => $r['ok']
            ? 'Sehr gut - du bekommst echte Preise inklusive Abo-Rabatt.'
            : 'DB blockt Server-IPs häufig. Das Tool funktioniert weiter, zeigt Preise aber nur als Schätzung mit Spanne. Alles andere - Fahrplan, Züge, Umstiege - ist davon nicht betroffen.',
    ];

    // MVG nur prüfen, wenn der Provider aktiv ist - sonst ist ein "fail"
    // hier verwirrend für alle, die den Münchner Nahverkehr nicht nutzen.
    if (($config['providers']['mvg']['enabled'] ?? false) === true) {
        $t0  = microtime(true);
        $mvg = new Mvg($http, $config['providers']['mvg']);
        $r   = $mvg->locations('Marienplatz', 1);
        $ms  = (int) round((microtime(true) - $t0) * 1000);
        $checks[] = [
            'name'   => 'MVG (Münchner Nahverkehr, Störungsticker)',
            'state'  => $r['ok'] ? 'ok' : 'warn',
            'detail' => $r['ok']
                ? 'erreichbar in ' . $ms . ' ms, Testtreffer: ' . ($r['data'][0]['name'] ?? '-')
                : ($r['error'] ?? 'unbekannter Fehler'),
            'hint'   => $r['ok']
                ? 'Münchner U-Bahn-Halte werden in der Ortssuche gefunden, der Störungsticker ist aktiv.'
                : 'Ohne MVG bleibt die Ortssuche für den Münchner Nahverkehr auf DB/ÖBB angewiesen und der Störungsticker fehlt. Nicht kritisch.',
        ];
    }

    // Wagenreihung: die einzige harte Quelle für die Baureihe. Sie ist
    // Beiwerk - ohne sie fehlt nur die Angabe "ICE 4" statt "ICE" -, aber
    // ihr Ausfall war bisher unsichtbar, weil der Provider bei jedem Fehler
    // stillschweigend weitermacht. Genau deshalb steht sie hier.
    if (($config['providers']['wagenreihung']['enabled'] ?? false) === true) {
        $wr   = $config['providers']['wagenreihung'];
        $vonDb = ($wr['source'] ?? 'bahnde') === 'bahnde';
        $t0  = microtime(true);
        if ($vonDb) {
            // Irgendein Zug genügt: 200 (Reihung da) und 404 (für diesen Zug
            // gerade keine) heißen beide "Schnittstelle antwortet". Kaputt ist
            // sie bei 403 (TLS-Profil) oder 422 (Parameterformat geändert).
            $res = $http->withBrowserTls()->getJson(
                rtrim((string) $wr['endpoint'], '/') . '?' . http_build_query([
                    'administrationId' => '80', 'category' => 'ICE', 'date' => date('Y-m-d'),
                    'evaNumber' => '8000105', 'number' => '1074', 'time' => gmdate('Y-m-d\TH:i:00.000\Z'),
                ]),
                // Ohne Accept-Language blockt bahn.de hier mit OPS_BLOCKED -
                // nachgemessen, derselbe Aufruf mit dem Kopf geht durch.
                ['Accept' => 'application/json', 'Accept-Language' => 'de-DE,de;q=0.9', 'Referer' => 'https://www.bahn.de/',
                 'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36']
            );
            $ok = in_array($res['status'], [200, 404], true);
        } else {
            $res = $http->getJson(
                rtrim((string) $wr['endpoint'], '/')
                    . '/coachSequence.departureSequence?input='
                    . rawurlencode(json_encode(json_encode([
                        ['evaNumber' => 1, 'plannedDeparture' => 2, 'initialDeparture' => 3,
                         'journeyNumber' => 4, 'category' => 5, 'administration' => 6],
                        '8000105',
                        ['Date', gmdate('Y-m-d\TH:i:s.000\Z')],
                        ['Date', gmdate('Y-m-d\T00:00:00.000\Z')],
                        1074, 'ICE', '80',
                    ]))),
                ['Accept' => 'application/json', 'User-Agent' => 'train-maxxing/1.0 (privates Fahrplanwerkzeug)']
            );
            $ok = $res['ok'] && $res['json'] !== null;
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $checks[] = [
            'name'   => ($vonDb ? 'bahn.de' : 'bahn.expert') . ' - Wagenreihung und Baureihe (optional)',
            'state'  => $ok ? 'ok' : 'warn',
            'detail' => $ok
                ? 'erreichbar in ' . $ms . ' ms'
                : 'HTTP ' . $res['status'] . ' - Schnittstelle antwortet nicht wie erwartet',
            'hint'   => $ok
                ? 'Deutscher Fernverkehr am Reisetag wird mit der Baureihe angezeigt (ICE 4, ICE 3neo …), '
                  . 'und der Umstiegsplan zeigt, wo die Wagen am Bahnsteig halten.'
                : 'Ohne diese Quelle bleibt es bei der Gattung; das Fahrzeug wird nur dort genannt, '
                  . 'wo es sich aus der Gattung oder der Strecke zwingend ergibt (railjet, Nightjet, Giruno …), '
                  . 'und der Wagenplan im Umstiegsplan fehlt. Die Stelle: lib/Providers/CoachSequence.php.',
        ];
    }
}

// --- Wie lief es zuletzt? ----------------------------------------------
//
// Die Prüfungen oben sagen, ob ein Dienst JETZT antwortet. Das reicht nicht:
// bahn.expert war wochenlang tot, und weil jeder Provider still degradiert,
// hat es niemand gemerkt. Health zählt deshalb jeden Aufruf im Betrieb mit -
// hier steht das Ergebnis der letzten 24 Stunden.
$verlauf = Health::summary((string) $config['cache_dir']);
if ($verlauf === []) {
    $checks[] = [
        'name'   => 'Verlauf der letzten 24 Stunden',
        'state'  => 'ok',
        'detail' => 'noch keine Aufrufe verbucht',
        'hint'   => 'Sobald jemand sucht, sammelt sich hier, wie zuverlässig die Quellen antworten.',
    ];
} else {
    foreach ($verlauf as $dienst => $v) {
        $gesamt  = $v['ok'] + $v['fail'];
        $prozent = (int) round($v['quote'] * 100);

        // ERST AB GENUG AUFRUFEN URTEILEN. Overpass stellt Anfragen in eine
        // Warteschlange und lässt gelegentlich eine laufen; bei zwei
        // Aufrufen ist einer davon "50 % Fehlerquote", und die Seite meldete
        // daraufhin einen Fehler, obwohl schlicht zu wenig Daten da waren.
        // Ab einem Viertel Fehlschlägen stimmt etwas nicht, ab der Hälfte
        // ist der Dienst praktisch weg - aber eben erst ab MIN_PROBEN.
        $genug = $gesamt >= MIN_PROBEN;
        $state = !$genug ? 'ok'
            : ($v['quote'] >= 0.5 ? 'fail' : ($v['quote'] >= 0.25 ? 'warn' : 'ok'));

        $checks[] = [
            'name'   => 'Verlauf: ' . $dienst,
            'state'  => $state,
            // KEIN K.-o.-KRITERIUM: hier steht, wie es einem fremden Dienst
            // in den letzten 24 Stunden ging - nicht, ob dieser Webspace das
            // Tool tragen kann. Vorher setzte ein einzelner Aussetzer bei
            // Overpass die Kopfzeile auf "Noch nicht startklar", während
            // jede echte Prüfung darunter grün war.
            'critical' => false,
            'detail' => sprintf('%d Aufrufe, davon %d fehlgeschlagen (%d %%)%s',
                $gesamt, $v['fail'], $prozent,
                $v['err'] !== '' ? ' - zuletzt: ' . $v['err'] : ''),
            'hint'   => $state === 'ok'
                ? ($genug || $v['fail'] === 0 ? '' :
                    'Zu wenige Aufrufe für ein Urteil - ein Aussetzer unter ' . MIN_PROBEN
                    . ' Aufrufen sagt noch nichts.')
                : 'Ein Provider, der still degradiert, fällt sonst wochenlang niemandem auf. '
                  . 'Prüf den Endpunkt in api/config.php.',
        ];
    }
}

// Nur was als kritisch markiert ist, kippt das Gesamturteil auf "nicht
// startklar". Alles andere ist höchstens ein Hinweis - ohne den Unterschied
// stand über einer durchweg grünen Seite eine Fehlermeldung.
$hasFail = false;
$hasWarn = false;
foreach ($checks as $c) {
    $kritisch = $c['critical'] ?? true;
    if ($c['state'] === 'fail' && $kritisch) { $hasFail = true; }
    if ($c['state'] === 'warn' || ($c['state'] === 'fail' && !$kritisch)) { $hasWarn = true; }
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>train-maxxing - Selbsttest</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
         max-width: 780px; margin: 0 auto; padding: 2rem 1.25rem; }
  h1 { font-size: 1.5rem; margin-bottom: .25rem; }
  .lead { opacity: .75; margin-top: 0; }
  .card { border: 1px solid rgba(128,128,128,.3); border-radius: 10px;
          padding: .9rem 1.1rem; margin: .6rem 0; }
  .card h2 { font-size: 1rem; margin: 0 0 .3rem; display: flex; gap: .5rem; align-items: baseline; }
  .badge { font-size: .72rem; font-weight: 700; letter-spacing: .04em;
           padding: .1rem .5rem; border-radius: 999px; text-transform: uppercase; }
  .ok   .badge { background: #d9f2e0; color: #14532d; }
  .warn .badge { background: #fdf0cd; color: #713f12; }
  .fail .badge { background: #fbdcdc; color: #7f1d1d; }
  .ok   { border-left: 4px solid #16a34a; }
  .warn { border-left: 4px solid #d99407; }
  .fail { border-left: 4px solid #dc2626; }
  .detail { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .85rem; }
  .hint { font-size: .9rem; opacity: .8; margin: .45rem 0 0; }
  .summary { padding: 1rem 1.15rem; border-radius: 10px; margin: 1.25rem 0;
             background: rgba(128,128,128,.12); }
  a { color: inherit; }
  @media (prefers-color-scheme: dark) {
    .ok .badge   { background: #14532d; color: #d9f2e0; }
    .warn .badge { background: #713f12; color: #fdf0cd; }
    .fail .badge { background: #7f1d1d; color: #fbdcdc; }
  }
</style>
</head>
<body>
  <h1>train-maxxing &mdash; Selbsttest</h1>
  <p class="lead">Prüft, ob dein Webspace alles kann, was das Tool braucht.</p>

  <div class="summary">
    <?php if ($hasFail): ?>
      <strong>Noch nicht startklar.</strong> Mindestens eine kritische Prüfung ist fehlgeschlagen &mdash; siehe unten.
    <?php elseif ($hasWarn): ?>
      <strong>Startklar mit Einschränkungen.</strong> Das Tool läuft. Einzelne Komfortfunktionen &mdash; in der Regel die Echtpreise &mdash; stehen nicht zur Verfügung.
    <?php else: ?>
      <strong>Alles in Ordnung.</strong> Du kannst <a href="index.html">das Tool öffnen</a>.
    <?php endif; ?>
  </div>

  <?php foreach ($checks as $c): ?>
    <div class="card <?= htmlspecialchars($c['state'], ENT_QUOTES) ?>">
      <h2>
        <span class="badge"><?= $c['state'] === 'ok' ? 'ok' : ($c['state'] === 'warn' ? 'hinweis' : 'fehler') ?></span>
        <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
      </h2>
      <div class="detail"><?= htmlspecialchars($c['detail'], ENT_QUOTES) ?></div>
      <?php if ($c['hint'] !== ''): ?>
        <p class="hint"><?= htmlspecialchars($c['hint'], ENT_QUOTES) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <p class="hint">
    Wenn alles passt: diese Datei löschen oder umbenennen. Sie verrät sonst
    unnötig Details über deinen Server.
  </p>
</body>
</html>
