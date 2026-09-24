<?php
/**
 * Zentrale Konfiguration.
 *
 * Diese Datei ist die einzige, die du normalerweise anfassen musst, wenn sich
 * etwas am Hosting oder an den Upstream-APIs ändert.
 */

return [
    // Wer darf das Frontend-API aufrufen? Leeres Array = gleiche Domain (empfohlen).
    // Beispiel: ['https://deine-domain.tld'] wenn das Frontend woanders liegt.
    'cors_origins' => [],

    // Cache-Verzeichnis. Muss vom Webserver beschreibbar sein.
    // Standard: <api>/cache. Wenn dein Hoster das nicht mag, z.B. sys_get_temp_dir().
    'cache_dir' => __DIR__ . '/cache',

    // Wie lange Antworten zwischengespeichert werden (Sekunden).
    // Fahrpläne ändern sich selten, Preise öfter.
    'cache_ttl' => [
        'locations'   => 86400,  // Ortssuche: 1 Tag
        'journeys'    => 300,    // Verbindungen: 5 Minuten
        'prices'      => 600,    // Preise: 10 Minuten
        'disruptions' => 120,    // MVG-Störungsticker: 2 Minuten
        'fxrate'      => 21600,  // EZB-Wechselkurse: 6 Stunden (täglich neu)
        'platforms'   => 604800, // Bahnsteige aus OSM: 7 Tage (bewegen sich nicht)
        'works'       => 3600,   // Bauarbeiten: 1 Stunde
        // Streckenverläufe aus OSM. Der längste Wert hier bestimmt zugleich,
        // wie lange der Cache-Ordner Dateien behält - siehe gc() in index.php.
        'railgeom'    => 2592000, // 30 Tage (Schienen ziehen nicht um)
    ],

    // Timeout pro Upstream-Request in Sekunden.
    'http_timeout' => 25,

    // Einfaches Rate-Limit pro IP, damit dein Webspace nicht als Scraper auffällt
    // und du nicht aus Versehen gegen deinen Hoster-Vertrag verstößt.
    //
    // Gerechnet wird in PUNKTEN, nicht in Anfragen: eine Verbindungssuche
    // kostet 5, ein Zuglauf 2, die gecachte Abo-Liste gar nichts. Sonst
    // sperrt sich die eigene App aus — die Live-Verfolgung allein holt alle
    // 30 Sekunden zwei Zugläufe, und jede Kartenbewegung eine Zugposition.
    // Die Tabelle steht in api/index.php unter RATE_COST.
    'rate_limit' => [
        'enabled'  => true,
        'max'      => 150,  // Punkte
        'per_secs' => 60,   // pro Zeitfenster
    ],

    'providers' => [
        // --- ÖBB HAFAS: Fahrplan, Zuggattungen, Zugnummern. Sehr zuverlässig. ---
        // Liefert KEINE Preise, nur einen Deeplink in den ÖBB-Shop.
        'oebb' => [
            'enabled'  => true,
            'endpoint' => 'https://fahrplan.oebb.at/bin/mgate.exe',
            // Diese Werte stammen aus der öffentlichen ÖBB-App-Konfiguration.
            // Falls die API irgendwann "auth" moniert, müssen sie aktualisiert werden.
            'auth'     => ['type' => 'AID', 'aid' => 'OWDL4fE4ixNiPBBm'],
            'client'   => ['id' => 'OEBB', 'type' => 'IPH', 'name' => 'oebbPROD-ADHOC', 'v' => '6030600'],
            'ver'      => '1.57',
            'lang'     => 'deu',
        ],

        // --- Schweizer Fahrplan (transport.opendata.ch): offen, kein Key, keine Preise. ---
        'swiss' => [
            'enabled'  => true,
            'endpoint' => 'https://transport.opendata.ch/v1',
        ],

        // --- DB: die einzige der drei Quellen, die echte Preise liefert. ---
        // ACHTUNG: DB blockt Rechenzentrums-IPs häufig mit HTTP 403 "OPS_BLOCKED".
        // Ob es von deinem Webspace aus geht, zeigt dir check.php.
        'db' => [
            'enabled'  => true,
            // 'bahnde'  = Web-API von int.bahn.de (kein Key)
            // 'dbrest'  = eine db-rest Instanz (eigene oder öffentliche)
            'mode'     => 'bahnde',
            'bahnde'   => [
                'locations' => 'https://int.bahn.de/web/api/reiseloesung/orte',
                'journeys'  => 'https://int.bahn.de/web/api/angebote/fahrplan',
            ],
            // Nur relevant wenn mode = 'dbrest'. Trag hier deine eigene Instanz ein,
            // falls du eine betreibst (https://github.com/derhuerst/db-rest).
            'dbrest'   => [
                'base' => 'https://v6.db.transport.rest',
            ],
        ],

        // --- MVG: Münchner Nahverkehr (U-Bahn, Tram, Bus, S-Bahn). ---
        //
        // Ergänzt die Ortssuche um alle Halte des MVV/MVG-Netzes - HAFAS
        // kennt reine U-Bahn-Halte wie "Odeonsplatz" oder "Sendlinger Tor"
        // oft nicht. Zusätzlich liefert die API einen Störungsticker
        // (?action=disruptions), den die App als Live-Widget einblenden kann.
        //
        // Die API läuft ohne Auth und ist ausdrücklich für die MVG-Web-App
        // gedacht. Für den fairen Umgang: cache_ttl['disruptions'] deckelt
        // die Aufruffrequenz.
        //
        // Verbindungen sucht die MVG über /routes: Stadtfahrten, der Zubringer
        // zum Fernzug am Hauptbahnhof und Ersatzwege bei einem Ausfall (siehe
        // lib/CityTrips.php). Dazu die Abfahrtstafel über /departures.
        'mvg' => [
            'enabled'    => true,
            'endpoint'   => 'https://www.mvg.de/api/bgw-pt/v3',
            'user_agent' => 'train-maxxing (+https://github.com/)',
        ],

        // --- strecken.info (DB InfraGO): große Baustellen in Deutschland. ---
        //
        // Das Verzeichnis hinter strecken.info. Liefert Totalsperrungen im
        // ganzen deutschen Netz mit Abschnitt, Zeitraum, Art der Arbeiten und
        // Streckennummer - die ÖBB-Quelle deckt fast nur Österreich ab.
        //
        // Die Antwort umfasst mehrere Megabyte, deshalb greift cache_ttl
        // ['works']. Abschalten kostet nur die deutschen Baustellen; die
        // österreichischen kommen weiter über die ÖBB.
        'streckeninfo' => [
            'enabled'    => true,
            'user_agent' => 'train-maxxing (+https://github.com/)',
        ],

        // --- OpenStreetMap via Overpass: Bahnsteige für den Umstiegsplan. ---
        //
        // Liefert Gleisnummer, Lage und Ebene der Bahnsteige. Daraus baut die
        // App bei knappen Umstiegen einen maßstäblichen Lageplan mit der
        // Luftlinie zwischen Ankunfts- und Abfahrtsgleis.
        //
        // Overpass ist ein kostenlos betriebener Gemeinschaftsdienst. Sei
        // fair: Bahnsteige ändern sich praktisch nie, deshalb sind sieben
        // Tage Cache gesetzt. Eine öffentliche Instanz verträgt keine
        // Dauerlast - wer das Tool stark nutzt, sollte eine eigene Instanz
        // eintragen oder den Provider abschalten.
        'overpass' => [
            'enabled'    => true,
            // Der Reihe nach, bis eine antwortet. Die öffentlichen Instanzen
            // sind unterschiedlich gut gelaunt: die Hauptinstanz stellt
            // Anfragen bei Last in eine Warteschlange (gemessen: elf Sekunden
            // für eine triviale Abfrage), und die Ausweichserver sind
            // zeitweise ganz weg (HTTP 502). Mit nur einem Ausweichserver
            // hieß das im Betrieb regelmäßig "Dienst antwortet nicht".
            //
            // NUR WELTWEITE INSTANZEN. Regionale Auszüge wie overpass.osm.ch
            // antworten für einen deutschen Bahnhof mit HTTP 200 und einer
            // LEEREN Liste - nicht von "nicht kartiert" zu unterscheiden.
            'endpoints'  => [
                'https://overpass-api.de/api/interpreter',
                'https://overpass.kumi.systems/api/interpreter',
                'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
                'https://overpass.private.coffee/api/interpreter',
            ],
            'user_agent' => 'train-maxxing (+https://github.com/)',
            // Gesamtbudget für alle Versuche zusammen. Je Instanz wird ein
            // Teil davon angesetzt, damit eine tote Instanz nicht die ganze
            // Zeit frisst - vorher wartete die Anfrage zweimal fünfzig
            // Sekunden und gab dann auf.
            'timeout'    => 60,
        ],

        // --- Wagenreihung: liefert die Baureihe (ICE 4 = BR 412 usw.). ---
        //
        // Läuft über bahn.expert, weil der DB-eigene Endpunkt von außen
        // nicht ansprechbar ist (HTTP 422, siehe README). Gilt nur für
        // deutschen Fernverkehr am Reisetag.
        //
        // bahn.expert ist ein privat betriebenes Projekt. Sei fair: Ergebnisse
        // werden 30 Minuten gecacht, 'max_lookups' deckelt die Abfragen je
        // Suche, und was einmal geholt wurde, merkt sich Fleet.php dauerhaft.
        // Wer das Tool dauerhaft betreibt, sollte auf die offizielle RIS-API
        // des DB API Marketplace wechseln.
        'wagenreihung' => [
            'enabled'     => true,
            // ACHTUNG, DER PFAD WANDERT. Bis September 2026 lag der Dienst
            // unter /rpc, seither unter /api/trpc. Der alte Pfad antwortet
            // mit HTTP 500 "Only HTML requests are supported here" - also
            // nicht mit einem Fehler, den man als solchen erkennt. Wenn die
            // Baureihe irgendwann wieder fehlt: check.php sagt es, und hier
            // ist die Stelle.
            // Seit 2026-09 direkt bei bahn.de (source 'bahnde'). Die
            // Alternative ist 'bahnexpert' mit dem Endpunkt
            // https://bahn.expert/api/trpc - der antwortete zuletzt mit
            // HTTP 500, siehe lib/Providers/CoachSequence.php.
            'source'      => 'bahnde',
            'endpoint'    => 'https://www.bahn.de/web/api/reisebegleitung/wagenreihung/vehicle-sequence',
            // Wie viele Züge je SUCHE höchstens nachgeschlagen werden - nicht
            // je Verbindung. Sonst wüchse die Last mit der Zahl der Treffer.
            // Die Abfragen laufen gleichzeitig, zwölf kosten daher etwa so
            // viel Zeit wie eine. Was diesmal nicht drankommt, holt der
            // nächste Aufruf; was einmal geholt wurde, merkt sich Fleet.php.
            'max_lookups' => 12,
        ],
    ],
];
