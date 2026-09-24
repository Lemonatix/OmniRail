# OmniRail

Vergleicht Zugverbindungen durch **Schweiz, Deutschland und Österreich** — nicht nur
nach Preis und Dauer, sondern auch danach, in welchem Zug du sitzt. Mit Abo-Auswahl
(Halbtax, GA, BahnCard, Vorteilscard, KlimaTicket) und zwei Modi:

- **Normal** — Preis und Zeit, eine sortierte Liste, fertig.
- **Nerd** — Zuggattung, Zugnummer, Streckenverlauf, Fahrzeugmodell,
  Routenzwang über eine bestimmte Stadt und eine Sortierung nach
  **Routenvarianten**: der Weg über den Gotthard und der über den Arlberg
  sind keine Abstufung derselben Sache, sondern eine Entscheidung.

Dazu eine Routenkarte, ein Verkehrsmittel-Filter (Bus und
Schienenersatzverkehr lassen sich vorab ausschließen), eine **Live-Verfolgung**
der gewählten Verbindung samt GPS-Mitfahrt und Benachrichtigung bei Ausfall,
Verspätung oder Gleiswechsel, und Buchungslinks zu SBB, DB oder ÖBB, je
nachdem welche Länder die Reise berührt.

Neben der Suche gibt es eine **Abfahrtstafel** als eigenen Tab, **Favoriten**
für die Orte, die man oft fährt, **Stadtfahrten in München** mit U-Bahn,
Tram und Bus, alle **Tarife der DB** samt Bedingungen, und die App läuft auch
**offline** mit dem zuletzt geladenen Stand weiter.

Gebaut als statisches Frontend plus schlankes PHP-Backend, damit du den Ordner
einfach auf deinen Webspace ziehen kannst. Die Oberfläche ist für Telefone
ausgelegt: keine horizontalen Scrollleisten, Touch-Flächen ab 44 px, und die
Karte wechselt auf schmalen Bildschirmen ins Hochformat.

---

## Was du wissen musst, bevor du loslegst

| Quelle | Fahrplan | Zuggattung & -nummer | Geometrie | Preise |
|---|---|---|---|---|
| ÖBB HAFAS | ja | ja, sehr detailliert | ja (Karte) | nein, nur Shop-Link |
| DB bahn.de | ja | ja | nein | **ja** |
| MVG (München) | ja, im MVV* | Linien-Label | ja (Polylinie) | nein, Tarifzonen |

*Die MVG liefert Ortssuche, Störungsmeldungen, Abfahrten und Verbindungen im
Münchner Nahverkehr — für Stadtfahrten, als Zubringer zum Fernzug und als
Ersatz bei einem Ausfall. Details unter [Münchner Nahverkehr](#münchner-nahverkehr-über-die-mvg-api)
und [Stadtfahrten](#stadtfahrten-und-zubringer-in-münchen).

### Preise: warum nur die DB

Nachgemessen, ob sich SBB oder ÖBB als zweite Preisquelle anzapfen lassen:

| Endpunkt | Ergebnis |
|---|---|
| `journey-service-int.api.sbb.ch` | **401** — SBB-Partner-API, braucht Registrierung |
| `www.sbb.ch/api/journeys` | **403** — Bot-Schutz, wie bei bahn.de |
| `shop.oebbtickets.at/api/domria/…` | **403 / 404** — Shop-API abgeriegelt |
| `transport.opendata.ch` | **200**, aber **kein Preisfeld** (nachgeprüft) |

Kurz: **ohne Zugangsdaten gibt es keine Schweizer oder österreichischen
Preise.** Die DB bleibt die einzige Quelle, und ÖBB/SBB steuern nur den
Deeplink in ihren Shop bei.

Was stattdessen geht: der **Gegenwert in der anderen Währung**. Die
Tageskurse kommen von der Europäischen Zentralbank
(`?action=fxrate`, sechs Stunden gecacht) — kein Schlüssel, keine
Registrierung, offizieller Referenzkurs mit Datum. An jeder Verbindung steht
damit z.B. `40,99 €` und darunter `≈ 38,34 CHF`. Bewusst mit „≈" und als
Nebenzeile: der Referenzkurs ist **kein Bankkurs**, beim Kartenzahlen kommen
Aufschläge dazu.

### Warum ÖBB HAFAS den Fahrplan liefert und die DB nur die Preise

Nachgemessen für fünf Relationen, jeweils dieselbe Abfrage an beide Quellen:

| | ÖBB HAFAS | DB bahn.de |
|---|---|---|
| Treffer CH/AT-Relationen | 6–10 | 5–6 |
| Streckengeometrie (Karte) | **alle Abschnitte** | keine |
| Zuglauf-ID für Echtzeit | **alle Abschnitte** | `journeyId` je Abschnitt |
| Preise | keine | **4–6 je Suche** |
| Auslastung | keine | **ja**, sogar je Halt |
| Zwischenhalte mit Koordinaten | 100 % | 100 % |
| Gattung, Zugnummer, Gleis, Ländercode | ja | ja |
| Antwortzeit | 0,8–7,0 s | 0,7–4,4 s |

Die Aufteilung ist also kein Zufall, sondern folgt den Lücken: **ohne HAFAS gäbe
es keine Karte** (die DB liefert keine Polylines) und **ohne die DB keine Preise**.
Auf österreichischen und Schweizer Relationen findet HAFAS zudem mehr — Wien→München
10 statt 5 Treffer, Zürich→Wien 8 statt 6.

Was die DB besser kann und was das Tool bereits nutzt: **Auslastungsangaben**
werden beim Preis-Merge auf die HAFAS-Abschnitte übertragen, ebenso die Angabe,
wo das Deutschlandticket gilt. Beides gibt es bei HAFAS nicht.

**Echtzeit kommt von der DB, nicht von HAFAS.** Die DB schickt neben
`sollzeit` ein Feld `echtzeit` direkt in der Suchantwort mit, dazu unter
`risNotizen` den Grund („Verspätung eines vorausfahrenden Zuges",
„Polizeieinsatz"). HAFAS bräuchte dafür je Abschnitt eine eigene Abfrage.
Deshalb übernimmt `mergeLegFlags()` die Ist-Zeiten auf die ÖBB-Abschnitte —
so stehen Verspätungen schon in der Trefferliste, ohne Zusatzabfrage.

Drei Fallstricke, die dabei aufgefallen sind:

- **Die DB liefert Zeiten ohne Zeitzone** (`2026-08-22T00:47:00`), die ÖBB mit
  Offset (`…+02:00`). Ohne Normalisierung interpretiert PHP den DB-Wert in der
  Serverzone. Auf einem deutschen Webspace fällt das nie auf, auf einem
  UTC-Server liegen beide Quellen zwei Stunden auseinander und der Abgleich
  über Ab-/Ankunftszeit findet gar nichts mehr — oder das Falsche.
  `DbVendo::iso()` hängt deshalb `Europe/Berlin` an.
- **Der Merge darf nicht am Preis hängen.** Die DB liefert Echtzeit auch für
  Relationen, die sie nicht verkauft — nachts und im Ausland der Normalfall.
  Früher übersprang `mergePrices()` preislose Treffer und verlor damit genau
  dort die Verspätung, wo sie am meisten hilft.
- **Bei S-Bahnen nennt die DB die Linie („S8"), die ÖBB die Zugnummer
  („35884").** Über die Nummer findet sich da nichts, deshalb fällt
  `mergeLegFlags()` auf die Position zurück, wenn beide Quellen gleich viele
  Zugabschnitte haben.

Und: die DB-Abschnitte tragen eine eigene `journeyId`. Damit wäre die
Live-Verfolgung auch für DB-Fahrpläne möglich — es fehlt nur ein
Zuglauf-Endpunkt auf DB-Seite.

### Die DB blockt nach TLS-Fingerprint, nicht nach IP

bahn.de läuft hinter Akamai Bot Manager. Der prüft nicht Header oder IP, sondern
den **TLS-ClientHello**. Mit den Standardeinstellungen von cURL kommt immer
`HTTP 403 OPS_BLOCKED` zurück — auch von einem gewöhnlichen Privatanschluss.

Setzt man die Cipher-Reihenfolge eines Chrome-Browsers, geht dieselbe Anfrage
durch. Nachgemessen: ohne Cipher-Liste 403, mit Cipher-Liste 200 in 5 von 5
Versuchen — und zwar sogar ohne User-Agent. Genau das macht `Http::withBrowserTls()`.

Voraussetzung ist cURL 7.61+ mit OpenSSL 1.1.1+, damit `CURLOPT_TLS13_CIPHERS`
existiert. `check.php` prüft das.

### Die DB kennt nur BahnCards

Die Angebots-API akzeptiert ausschließlich `BAHNCARD25/50/100`. Halbtax, GA,
VORTEILScard, KlimaTicket und Deutschlandticket werden **stillschweigend
ignoriert** — die API liefert denselben Preis wie ohne Ermäßigung und meldet
keinen Fehler. Nachgemessen für Zürich→München, 2. Klasse:

| Ermäßigung | günstigster Preis |
|---|---|
| ohne | 34,19 € |
| BahnCard 25 | 29,57 € |
| BahnCard 50 | 29,57 € |
| BahnCard 100 | 21,60 € |
| Halbtax | 34,19 € (wirkungslos) |
| GA | 34,19 € (wirkungslos) |
| frei erfundener Wert | 34,19 € (wirkungslos) |

Deshalb der Hybrid: Der Echtpreis der DB ist die Basis, und die Abos, die sie
nicht kennt, rechnet das Tool auf den Länderanteil hoch. Das wird als
„Echtpreis + Abo geschätzt" gekennzeichnet und im Detail vorgerechnet.

### Preise gibt es nur für Relationen, die die DB verkauft

Zürich→München liefert Preise. Zürich→Wien liefert Verbindungen **ohne** Preis,
weil die DB diese Relation nicht vertreibt. Dann fällt das Tool auf die
Schätzung mit Spanne zurück. Fahrplan, Züge, Umstiege, Streckenverlauf und Karte
sind davon nicht betroffen.

---

## Installation

1. **Hochladen.** Den kompletten Inhalt von `public/` in dein Webverzeichnis
   ziehen, z.B. nach `/train/`. Die Struktur muss erhalten bleiben:

   ```
   train/
   ├── index.html
   ├── .htaccess       ← Browser-Cache (nicht vergessen, Punktdateien
   │                     blendet mancher FTP-Client aus)
   ├── check.php
   ├── assets/
   └── api/
       ├── index.php
       ├── config.php
       ├── cache/          ← muss beschreibbar sein
       └── lib/
   ```

2. **Selbsttest aufrufen:** `https://deine-domain.tld/train/check.php`
   Die Seite prüft PHP-Version, cURL, Schreibrechte und beide Datenquellen.

3. **Falls das Cache-Verzeichnis nicht beschreibbar ist:** entweder per FTP auf
   `0775` setzen, oder in `api/config.php` einen anderen Pfad eintragen:

   ```php
   'cache_dir' => sys_get_temp_dir() . '/train-maxxing',
   ```

4. **`check.php` löschen**, wenn alles läuft — sie verrät sonst unnötig
   Serverdetails.

5. Fertig: `https://deine-domain.tld/train/`

### Nach einem Update: Browser-Cache

Erledigt `public/.htaccess`. Die Datei setzt für `.html`, `.js` und `.css`
den Header `Cache-Control: no-cache, must-revalidate` — der Browser behält
die Dateien, fragt aber vor jeder Benutzung kurz nach, ob sie noch aktuell
sind. Hat sich nichts geändert, antwortet der Server mit `304` und ohne
Inhalt; das kostet ein paar Bytes und erspart das harte Neuladen nach jedem
Upload.

**Warum nicht `?v=2` an den Pfaden**, wie es oft empfohlen wird: die Skripte
sind ES-Module und laden einander mit festen Pfaden nach
(`import { api } from './api.js'`). Eine Versionsnummer am Einstiegspunkt
erreicht diese Importe nie — `app.js` käme frisch vom Server, `api.js` weiter
aus dem Cache. Damit laufen zwei Stände gleichzeitig, und das ist schlimmer
als gar kein Cache-Busting.

Voraussetzung ist Apache mit `mod_headers`. Fehlt beides, hilft weiterhin nur
einmal hart neu laden (`Strg`+`Shift`+`R`) — die nginx-Fassung steht unten.

### Voraussetzungen

- PHP 8.0 oder neuer
- cURL-Erweiterung aktiv
- Ausgehende HTTPS-Verbindungen erlaubt (bei manchen Billig-Hostern gesperrt)

### nginx statt Apache?

Die mitgelieferten `.htaccess`-Dateien schützen `api/cache/` und `api/lib/` vor
direktem Zugriff und regeln den Browser-Cache. Unter nginx wirken sie
**nicht** — trag dort stattdessen ein:

```nginx
location ~ ^/train/api/(cache|lib)/ { deny all; }
location ~* \.(html|js|css)$ { add_header Cache-Control "no-cache, must-revalidate"; }
```

---

## In die eigene Website einbauen

Das Frontend hat keine JavaScript-Abhängigkeiten. Alle API-Pfade sind relativ
(`api/…`), es funktioniert also in jedem Unterordner ohne Konfiguration.

**Gestaltung:** Glasmorphismus-Grundgerüst wie auf mika-riesterer.de
(Panels mit `backdrop-filter`, dünne Ränder, Sky/Purple/Emerald als
Akzente, Outfit + JetBrains Mono), kombiniert mit der Informationsarchitektur des
DB-Navigators — große Zeitangaben in Tabellenziffern, farbcodierte
Zuggattungs-Chips, Zeitachse im Detailbereich, Verkehrsrot als Signalfarbe für
den Fernverkehr.

Die Schriften werden in `index.html` von Google Fonts geladen. Ist das Tool in
deine Website eingebettet, sind sie ohnehin da — dann kannst du die beiden
`<link>`-Zeilen ersatzlos streichen.

**Farben anpassen:** alles läuft über CSS-Variablen. Nach dem Einbinden von
`style.css` überschreiben:

```css
:root {
  --accent: #c084fc;
  --radius: 4px;
}
```

Die Seite kennt **hell und dunkel**. Ohne gespeicherte Wahl folgt sie dem
Betriebssystem, der Umschalter oben rechts überschreibt das und legt die
Entscheidung unter `train-maxxing:theme` ab. Die hellen Werte stehen in
`:root`, die dunklen in `[data-theme="dark"]` — alle Komponenten leiten ihre
Farben davon ab.

### Umstiegsplan: wo die beiden Gleise liegen

Bei vier Minuten Umsteigezeit ist die Gleisnummer allein wertlos: entscheidend
ist, ob man zwanzig Meter weiter oder ans andere Hallenende muss. Die
Fahrplanquellen wissen das nicht, OpenStreetMap teilweise schon.

`?action=platforms` liefert die **nummerierten Bahnsteige** eines Bahnhofs mit
Umriss und Ebene. Der Plan zeigt sie auf derselben Karte wie überall sonst, nur
im **Bahnhofsmodus** (`new RouteMap(el, { mode: 'station' })`) — Kacheln als
Untergrund, ziehen und zoomen inklusive. Ankunftsgleis blau, Abfahrtsgleis
grün, der Rest als Orientierung ringsum. Das spart eine zweite
Kartenmaschinerie; die SBB nimmt dafür das MapLibre-SDK, hier reichen die
vorhandenen `<img>`-Kacheln mit SVG darüber.

**Kein Laufweg mehr — und das war eine Korrektur, keine Vereinfachung.** Hier
stand einmal eine Dijkstra-Suche über die Fußwege und Treppen aus OSM, mit
Länge, geschätzter Gehzeit und Treppenwarnung. Sie war rechnerisch in Ordnung
und trotzdem falsch, weil ihre Voraussetzung fast nie erfüllt ist: sie
funktioniert nur an einem Bahnhof, der **innen vollständig kartiert** ist.
Selbst dort, wo es reichte, kam ein Weg heraus, der so nicht existiert — durch
eine Unterführung, die man in Wirklichkeit gar nicht nimmt —, und Meter- und
Minutenangaben suggerierten eine Genauigkeit, die dahinter nie stand. Ein
Umsteigeplan, der einen falschen Weg selbstbewusst einzeichnet, ist schlechter
als einer, der nur die Lage zeigt und den Rest dem Bahnhof überlässt: dort
hängen Schilder.

Geblieben ist damit die Frage, die sich überhaupt beantworten lässt — *liegen
die beiden Bahnsteige nebeneinander oder an entgegengesetzten Enden?* Wo beide
Gleisnummern bekannt sind, sind sie hervorgehoben; ein Ebenenwechsel wird
dazugesagt, denn der kostet mehr Zeit, als die Entfernung vermuten lässt.

#### Treppen, Rolltreppen und Aufzüge je Ebene

Zwischen „nur die Lage" und „ein berechneter Weg, den es so nicht gibt" liegt
etwas, das OSM verlässlich hat: **die Stellen, an denen es die Ebene
wechselt.** Treppen, Rolltreppen und Aufzüge sind an großen Bahnhöfen fast
vollständig und mit Ebene erfasst — nachgezählt im Umkreis von 250 m:

| Bahnhof | Aufzüge | Treppen | Rolltreppen | davon mit Ebene |
|---|---|---|---|---|
| Zürich HB | 20 | 48 | 81 | 143 von 149 |
| München Hbf | 8 | 65 | 63 | 127 von 136 |
| Mannheim Hbf | 11 | 31 | 14 | 43 von 56 |

Sie kommen in derselben Overpass-Abfrage mit (zweiter Block, `out tags geom`,
weil eine Rolltreppe ohne Geometrie keine Fahrtrichtung hat) und stehen je
Ebene im Plan:

- Die Karte beginnt auf der **Ebene des Ankunftsgleises**. Hervorgehoben ist,
  was von dort Richtung Abfahrtsgleis führt — liegt es tiefer, alles, was
  nach unten geht. Mit ▲ ▼ folgt man dem Weg Ebene für Ebene; Zürich Gleis 8
  (Ebene 0) → Gleis 33 (Ebene −4) führt so über die Rolltreppen ins
  Untergeschoss und weiter hinunter.
- Rolltreppen tragen einen **Pfeil in Fahrtrichtung** (`conveying`), neben
  jedem Verbinder steht, zu welcher Ebene er führt.
- Ebenen, auf denen es keinen Bahnsteig gibt, aber zu denen Treppen führen,
  sind jetzt im Umschalter — das Untergeschoss ist oft genau der Weg. Nicht
  dagegen die Bürohäuser nebenan: gezählt wird von zwei Ebenen unter dem
  tiefsten bis eine über dem höchsten Bahnsteig (in Zürich kamen sonst
  Aufzüge bis zum fünften Stock dazu).
- Treppen **ohne** Ebenenangabe bleiben draußen — das sind fast immer Stufen
  im Straßenraum vor dem Bahnhof.

Einen Laufweg zeichnet der Plan weiterhin nicht. Die Gänge zwischen den
Treppen sind in OSM zu lückenhaft; siehe oben.

#### Wagenreihung am Umstieg

Unter dem Plan stehen beide Züge **maßstäblich am Bahnsteig**: oben die
Sektoren, darunter die Wagen mit Nummer, die 1. Klasse bernsteinfarben, das
Bordrestaurant grün unterstrichen. Darunter in Worten, was man beim
Umsteigen wissen will — „1. Klasse: Sektor F–G · Bordrestaurant: F ·
Fahrräder: A" — und, rot, wenn ein Zug **geteilt** wird: ein Flügelzug mit
zwei Zielen ist die Falle, in die man sonst tappt. Quelle ist die
Wagenreihung der DB (siehe „Der direkte DB-Weg"), also deutscher Fernverkehr
am Reisetag. Nachgeprüft am Umstieg Mannheim Hbf, ICE 202 (Gleis 3) → ICE 692
(Gleis 2).

Was noch fehlt: die Sektoren auf der Karte selbst. Dafür müssten die
Sektortafeln in OSM erfasst sein, und das sind sie nur an wenigen Bahnhöfen.

**Die SBB kann mehr — aber nicht frei.** Die exakten Wege über mehrere Etagen
in der SBB-App kommen aus der *Journey Maps*-API (`/v1/transfer`, „ROKAS
enhanced pedestrian routing"; dazu `/v1/master-data/…/floor-connectors`).
Nachgesehen im [SBB-Developer-Portal](https://developer.sbb.ch/apis/journey-maps-apikey/documentation):
alle Tarife „Approval required", die Variante mit API-Schlüssel ist als
*deprecated* markiert, und die Daten decken nur Schweizer Bahnhöfe ab. Ohne
freigeschalteten Zugang lässt sich das weder einbauen noch testen.

Weggefallen sind mit dem Weg auch `StationPlan.php` und die Fußwege in der
Overpass-Abfrage. Letztere waren der größte Teil der Antwort — die Abfrage ist
seither deutlich kleiner, und Overpass ist ein Gemeinschaftsdienst.

**Der Plan erscheint an jedem Umstieg, nicht nur an den knappen.** Vorher galten
zwei Bedingungen zugleich: die Umsteigezeit musste unter zehn Minuten liegen
*und* beide Gleisnummern mussten im Fahrplan stehen. Damit fiel er bei den
allermeisten Umstiegen aus — knapp ist nur eine Minderheit, und ob Gleise
mitgeliefert werden, hängt am Bahnhof und am Betreiber. Jetzt reicht ein
Umstieg. Fehlen die Nummern, zeigt der Plan den Bahnhof mit allen erfassten
Gleisen; auch das beantwortet „ein Bahnsteig oder eine halbe Halle". Kosten
entstehen dadurch keine: geladen wird erst beim Aufklappen.

**Der Ebenenumschalter** bleibt. Was auf einer anderen Ebene liegt,
verschwindet nicht, sondern wird blass gezeichnet: sonst verliert man beim
Umschalten die Orientierung, weil das halbe Bild wegfällt. Gibt es nur eine
Ebene, bleibt der Umschalter ganz weg, statt untätig herumzustehen.

**Der Ausschnitt folgt den beiden Gleisen, nicht dem Bahnhof.** Ein großer
Bahnhof ist vierhundert Meter lang; passt er ganz ins Bild, sind die zwei
Bahnsteige, um die es geht, zwei Striche unter achtundzwanzig. Sind die
Nummern unbekannt, ist der Überblick über alle das Richtige — dann gibt es
nichts Engeres zu zeigen.

**Der Plan besteht nur noch aus Punkten — einer je Gleis.** Hier wurden
einmal die Bahnsteigumrisse als Linien gezeichnet. Das sah nach mehr Auskunft
aus, als darin steckte: ein Umriss sagt, wo der Bahnsteig liegt, nicht wo der
Zug hält, und bei einem Bahnsteig zwischen zwei Gleisen ist er für beide
derselbe. Dazu kamen die Gleisflächen aus OSM ins Bild, und am Ende war der
Plan ein Liniengewirr, in dem die zwei Punkte untergingen, um die es geht.

Zwei Gleise ohne eigenen Haltepunkt fallen dabei auf denselben Punkt — dann
steht dort eben „2/3", und das ist ehrlicher als zwei Punkte, die Genauigkeit
vortäuschen.

Das hat die Datenmenge nebenbei zusammenfallen lassen. Die Umrisse waren der
Löwenanteil der Antwort, und ohne sie genügt Overpass `out tags center` statt
`out geom`:

| | vorher | jetzt |
|---|---|---|
| Overpass-Antwort (Ulm) | 84 KB | **57 KB** |
| Overpass-Antwort (Frankfurt) | 89 KB | **55 KB** |
| unsere API-Antwort (Ulm, 21 Bahnsteige) | mit Umrissen | **2,3 KB** |

`center` räumt zugleich einen Sonderfall weg: mit `out geom` tragen Relationen
ihre Geometrie in den *Mitgliedern*, nicht am Objekt — wer das übersieht,
verliert sie stumm. Genau das war passiert (siehe unten).

**Auch bei gleichem Bahnsteig wird die Karte gezeigt.** Vorher endete die
Anzeige dort bei einem Satz („Gleis gegenüber — nur die Seite wechseln"). Das
ist zwar eine gute Nachricht, aber man will trotzdem sehen, wo im Bahnhof man
steht — und an einem Bahnsteig mit vier Abschnitten ist „gegenüber" auch nicht
überall dasselbe.

**Der Ausschnitt richtet sich nach den beiden Gleisen — sonst nichts.**
Liegen sie nebeneinander, wird es sehr eng, und genau das ist richtig: die
Frage lautet „wo genau", nicht „wie sieht der Bahnhof aus". Gemessen an drei
Umstiegen. Die Untergrenze liegt bei **50 m**: bei zwanzig steht die
Maßstabsleiste noch auf „20 m", und der Ausschnitt ist so eng, dass ausser
den beiden Punkten nichts mehr zu sehen ist. Eine Stufe weiter draussen sind
die Nachbargleise mit im Bild, und man weiss, wo man steht.

**Die Markierung sitzt auf dem GLEIS, nicht auf dem Bahnsteig.** Ein
Bahnsteig zwischen Gleis 2 und 3 hat seinen Schwerpunkt genau zwischen beiden
— die Punkte für „Gleis 2" und „Gleis 3" lägen übereinander. Und wo ein
Bahnhof in Abschnitten erfasst ist (Ulm führt „4 Nord" und „4 Süd"), ist die
Fläche zu „Gleis 4" willkürlich die eine oder die andere Hälfte; genau das sah
im Plan seltsam aus. Wo OSM einen **Haltepunkt** kennt — Ulm hat 23, Frankfurt
29 —, sitzt die Markierung dort. Sonst weiterhin auf der Bahnsteigmitte.

Fünf Fehler, die den Plan gedrückt oder verfälscht haben:

1. **Relationen fielen stumm durch.** Mit `out geom` liefert Overpass die
   Geometrie einer Relation nicht am Objekt selbst, sondern in den `members`.
   Der Code prüfte `lat/lon`, dann `geometry`, dann `center` — eine Relation
   hat nichts davon und landete bei `continue`. Friedrichshafen Stadtbahnhof
   führt vier Bahnsteige, **drei davon als Relation**; bei uns kam genau der
   eine an, der als Weg erfasst ist: **1 → 4 Bahnsteige.** Seit der Umstellung
   auf `out tags center` stellt sich die Frage nicht mehr — `center` steht an
   jedem Objekt.
2. **`ref="Gleis 24"` statt `ref="24"`.** Manche Bahnhöfe schreiben das Wort
   mit hinein. Der Fahrplan sagt „24", die Suche ging leer aus, und im Plan
   stand „Gleis Gleis 24". Der Wortkopf wird jetzt abgeschnitten — aber nur,
   wenn danach eine Ziffer folgt, damit Mannheims Bussteig „Steig F" nicht zu
   einem „F" wird, das man für ein Gleis halten könnte.
3. **Ein Busbahnhof bekommt keinen Gleisplan.** Der Plan zeigt Bahnsteige aus
   OpenStreetMap — an einer Bushaltestelle gibt es die nicht, und was der
   350-Meter-Umkreis stattdessen einfängt, ist der nächstgelegene Bahnhof. Für
   den Fernbus am **„München ZOB (Hackerbrücke)"** kamen so die Gleise 5–36 des
   Hauptbahnhofs heraus, 600 m weiter — ein Plan, der eine ganz andere Station
   zeigt und nichts davon sagt. Ist eine der beiden Seiten ein Bus, entfällt
   der Plan.
4. **Bussteignummern galten als Bahnhofsnummer.** Damit Zürichs `ref=13030`
   (die Nummer des *Bahnhofs*, die dort auf 26 Haltepunkten steht) nicht als
   „Gleis 13030" durchgeht, fliegt raus, was auf drei oder mehr Haltepunkten
   gleich lautet. Gezählt wurden dabei aber auch **Bussteige** — und an einem
   grossen Busbahnhof kommt dieselbe Nummer leicht dreimal vor. **München-
   Pasing** hat so sein Gleis 10 verloren: die „10" steht dort auf drei
   Bus-Haltepunkten, und damit flog sie aus jedem Bahnsteig heraus, auch aus
   der Relation `ref="9;10"`, die OSM sauber führt. Gezählt werden jetzt nur
   noch Bahnhalte (`railway=stop` oder `train=yes`); Zürich bleibt korrekt.
5. **Nur Bussteige sind keine Bahnsteige.** Radolfzell liefert 33 OSM-Objekte,
   und **alle 33 sind Bushaltestellen** — kein einziger Bahnsteig ist dort
   erfasst. Der Plan bleibt daher leer, und das ist richtig so; die Anzeige
   sagt es auch. Nichts, was sich im Code lösen ließe: das gehört in
   OpenStreetMap eingetragen.

Zwei Dinge, die beim Erfassen der Gleisnummern zu beachten waren:

- **Bahnsteigabschnitte auf die nackte Nummer abbilden.** Ulm Hbf führt in OSM
  „4 Nord", „4 Süd", „5a", „5b" — und kein einziges nacktes „4". Der Fahrplan
  sagt aber „Gleis 4". Die bloße Nummer kommt als Zweitname dazu, nachrangig:
  wo es ein echtes „4" gibt, gewinnt das.
- **Fehlende Nummern aus den Nachbarn ergänzen.** Mannheim Hbf hat in OSM die
  Gleise 1–5 und 7–12, aber **kein 6** — jemand hat es beim Erfassen
  ausgelassen. Fuhr der Anschlusszug von Gleis 6, entfiel deshalb die
  Hervorhebung, obwohl der Bahnhof ringsum vollständig kartiert ist. Genau
  daher rührt auch der Eindruck, der Plan verhalte sich „mal so, mal so" für
  denselben Bahnhof: die Koordinaten sind stabil, die *Gleisnummern* der
  jeweiligen Verbindung sind es nicht.

  Ergänzt werden Lücken von höchstens drei Nummern, und nur wenn die beiden
  Nachbarn entsprechend dicht beieinanderliegen — je fehlender Nummer knapp
  fünfzehn Meter, das ist eine Gleisachse. Der Abstand ist der eigentliche
  Wächter: Zürichs Sprung von 18 auf 31, Berns von 13 auf 21 (RBS) und Basels
  von 20 auf 30 (SNCF) sind keine Erfassungslücken, sondern eigene
  Bahnhofsteile, und die liegen hunderte Meter auseinander. Was ergänzt wurde,
  sagt die Anzeige dazu.

**Die Abdeckung ist sehr unterschiedlich** — und war lange schlechter, als sie
sein musste. Zwei Fehler steckten dahinter:

1. Die Overpass-Abfrage entstand per `sprintf` in einem **doppelt gequoteten**
   PHP-String. Dort liest PHP `%1$d` als `%1` gefolgt von der Variablen `$d`;
   die war nie gesetzt, `sprintf` bekam `%1,` zu sehen und warf *Unknown format
   specifier*. Die Abfrage kam gar nicht erst zustande — **jeder** Bahnhof ohne
   Cache-Eintrag meldete „keine Bahnsteige erfasst".
2. An Haltepunkten trägt `ref` **mancherorts** die Nummer des Bahnhofs, nicht
   die des Gleises. Zürich HB lieferte darüber „Gleis 13030" — die Gleisnummer
   steht dort in `local_ref`. Nur `local_ref` zu nehmen war aber auch falsch:
   Mannheim Hbf führt seine zwölf Gleise als `ref` und kennt kein `local_ref`,
   und der Lageplan zeigte dort **einen** Bahnsteig. Jetzt gilt `local_ref`
   vor `ref`, und was wie eine Stationsnummer aussieht, fällt vorher raus: was
   auf **drei oder mehr** Haltepunkten gleich lautet, kann keine Gleisnummer
   sein — ein Gleis hat höchstens zwei, einen je Richtung.

#### Abdeckung, über 33 Bahnhöfe erhoben

Von den 33 abgefragten Bahnhöfen (CH/DE/AT) lieferten 28 Daten; die übrigen
fünf liefen an dem Tag in Overpass-Fehler und sind beim nächsten Versuch
wieder dabei. **Alle 28 haben nummerierte Bahnsteige und damit einen Plan.**

Die Lücken in der Nummerierung sind meistens **echt**, keine Datenlücken:
Hamburg Hbf und Berlin Hbf haben schlicht keine Gleise 9 und 10, Genf keine 8
und 9. Deshalb wird dort auch nichts ergänzt — der Abstandstest weist es
korrekt ab. Tatsächlich ergänzt wurden bei der Stichprobe Mannheim 6,
Nürnberg 10 und 11 sowie Bern 11.

Nach der Korrektur, nachgemessen:

| Bahnhof | Bahnsteige mit Nummer |
|---|---|
| Zürich HB | 24 (Gleis 3–18, 31–34, 41–44) |
| Mannheim Hbf | 12 (vorher 1) |
| Frankfurt Hbf | 28 (vorher 0) |
| Stuttgart Hbf | 17 (vorher 1) |
| München Hbf | 17 |
| Bern | 16 |
| Ulm Hbf | 18 (Abschnitte) |
| Winterthur | 10 |
| Olten | 14 |

Findet OpenStreetMap für den Bahnhof gar nichts, sagt die Anzeige, was fehlt —
und unterscheidet dabei „Dienst gerade überlastet" von „Bahnhof nicht
kartiert". Vorher stand in beiden Fällen dieselbe Zeile, und in einem davon war
sie falsch.

**Overpass ist der wunde Punkt.** Der Dienst stellt Anfragen bei Last in eine
Warteschlange — gemessen: elf Sekunden für eine *triviale* Abfrage —, und die
Ausweichserver sind zeitweise ganz weg (HTTP 502). Drei Dinge dagegen:

- **Vier Instanzen statt zwei**, der Reihe nach, und jede bekommt nur einen
  Teil des Zeitbudgets. Vorher wartete eine Anfrage zweimal fünfzig Sekunden
  und gab dann auf, obwohl eine dritte Instanz sofort geantwortet hätte.
  Nachgemessen über fünf Bahnhöfe: 2,4 s bis 35,7 s, alle mit Ergebnis.
- **Nur weltweite Instanzen.** Regionale Auszüge wie `overpass.osm.ch`
  antworten für einen deutschen Bahnhof mit HTTP 200 und einer *leeren*
  Liste — von „nicht kartiert" nicht zu unterscheiden. Aus demselben Grund
  gilt eine Antwort nur dann als Erfolg, wenn sie sich als JSON lesen lässt:
  überlastete Instanzen schicken eine HTML-Fehlerseite mit Status 200.
- **Es wird wiederholt.** Vorher setzte die Anzeige ihr `geladen`-Flag,
  *bevor* die Antwort da war — schlug sie fehl, tat erneutes Aufklappen
  nichts mehr, und der Rat „später noch einmal aufklappen" ging ins Leere.
  Jetzt gilt ein Versuch erst als erledigt, wenn er etwas geliefert hat, und
  zwei Wiederholungen mit wachsendem Abstand laufen von selbst; der
  Zwischenstand steht im Kasten, statt dass minutenlang „Lade Bahnsteige …"
  stehen bleibt.

Der **Streckenverlauf der Baustellen** fragt dieselben Instanzen in
*umgekehrter* Reihenfolge ab. Er ist Beiwerk, der Umstiegsplan nicht — fragt
das Beiwerk zuerst die Hauptinstanz, verbraucht es genau das Kontingent, das
gleich der Bahnhofsplan braucht.

Ein Fallstrick, der beim Bauen aufgefallen ist: Würzburg Hbf liefert vierzehn
Objekte mit den Nummern 1–14 — das ist aber der **Busbahnhof davor**
(`bus=yes`, `highway=platform`). Ohne den Filter in `Overpass.php` hätte die
App Bussteige als Zuggleise angezeigt. Ausgeschlossen wird nur, was sich
ausdrücklich als Nicht-Bahn ausweist; ein fehlendes `train`-Tag heißt bei
Bahnsteigen meist nur, dass es niemand eingetragen hat.

**Das Aufräumen darf die teuren Einträge nicht wegwerfen.** Der Cache-Ordner
wird gelegentlich durchgesehen, damit er nicht unbegrenzt wächst — mit dem
Standardwert von einem Tag. Genau der traf aber jeden Tag die Einträge, die am
teuersten zu beschaffen sind: Bahnsteige gelten sieben Tage, Streckenverläufe
dreißig, und Overpass durfte sie danach jedes Mal neu liefern. Die Grenze
richtet sich jetzt nach der längsten eingestellten Haltbarkeit.

Geladen wird erst beim Aufklappen: Overpass ist ein kostenlos betriebener
Gemeinschaftsdienst, ungefragte Abfragen für jeden sichtbaren Umstieg wären
unfair. Die Bahnsteige eines Bahnhofs werden sieben Tage gecacht.


### Große Baustellen im Netz

`?action=works` liefert Bauarbeiten mit **betroffenem Abschnitt** (von Bahnhof
A bis Bahnhof B), Zeitraum und — soweit ermittelbar — dem tatsächlichen
Streckenverlauf.

**Die Liste ist nach Ländern gruppiert und vollständig erreichbar.** Vorher
standen dort acht Zeilen und sonst nichts — an die übrigen siebenundachtzig
kam man gar nicht heran. Alle auf einmal auszuschütten wäre aber auch nichts:
hundert Zeilen unter der Karte liest niemand. Also je Land ein aufklappbarer
Block mit Anzahl in der Überschrift, das erste offen, und innerhalb eines
Landes schiebt ein Knopf die nächsten acht nach.

**Jede Meldung lässt sich aufklappen.** Zugeklappt steht da, was für die
Übersicht zählt: Abschnitt, Thema, wie lange noch. Der eigentliche Satz —
*„Totalsperrung — Brückenarbeiten (Strecke 3640)."* — hing vorher nur im
`title`-Attribut, und auf einem Berührungsbildschirm gibt es kein Darüberfahren:
dort war er schlicht unerreichbar. Aufgeklappt steht er als Satz da, mit dem
vollständigen Zeitraum und den betroffenen Streckennummern darunter, und der
Knopf „Auf der Karte zeigen" sitzt dort statt in der Kopfzeile — in einem
`<summary>` wäre er ein Knopf im Knopf und würde beim Aufklappen mitgetroffen.

**Geladen wird erst, wenn der Kasten ins Bild kommt.** Die Baustellenabfrage
ist die langsamste der ganzen App — das Verzeichnis der DB InfraGO umfasst
mehrere Megabyte, kalt gemessen 28 Sekunden. Browser halten je Host nur etwa
sechs Verbindungen offen, und die Seite feuert beim Aufbau schon Katalog,
Kurse, Störungsticker, Live-Züge und die eigentliche Suche ab. Die Baustellen
belegten davon einen Platz für eine halbe Minute — und die **Trefferliste
wartete dahinter**, obwohl ihre eigene Antwort längst da war. Ein
`IntersectionObserver` löst das: der Kasten sitzt ganz unten, bis dahin ist die
Suche lange fertig. Ohne Observer greift ein Zeitgeber nach sechs Sekunden.

**Eine eigene Karte, nicht die Routenkarte.** Baustellen und Suchergebnisse
beantworten verschiedene Fragen und stehen einander im Weg: über einer
gefundenen Verbindung liegen ein Dutzend markierter Abschnitte, die mit ihr
nichts zu tun haben, und der Ausschnitt kann nicht beiden gerecht werden — die
Route will Zürich–Wien zeigen, die Baustellenkarte das ganze Netz. Der Kasten
unter der Trefferliste bringt deshalb seine eigene Karte mit; gebaut wird sie
erst beim Aufklappen, denn eine Karte lädt Kacheln.

#### Zwei Quellen, Deutschland zuerst

| Land | Quelle | liefert |
|---|---|---|
| Deutschland | DB InfraGO über `strecken-info.de/api/baustellen` | Totalsperrungen im ganzen Netz, mit Betriebsstelle, Zeitraum, Art der Arbeiten und Streckennummer |
| Österreich, Schweiz | HAFAS Information Manager der ÖBB (`HimSearch`) | Betriebsmeldungen mit Abschnitt und Zeitraum, teils mit Streckenverlauf |

Vorher gab es nur die ÖBB-Quelle, und die ist österreichlastig: nachgemessen
über 500 Meldungen 452 mit österreichischem, 17 mit deutschem und 9 mit
schweizerischem Anfangsbahnhof — nach Kategorie- und Dauerfilter blieb aus
Deutschland praktisch nichts übrig. Für eine Übersicht „wo wird gerade groß
gebaut" war das die falsche Hälfte des Bildes. Jetzt stehen 60 deutsche
Vorhaben vor 35 österreichischen.

**Für die Schweiz gibt es keine Quelle.** Die ÖBB-Instanz kennt zwar
schweizerische Meldungen — neun von 500 —, aber keine davon übersteht den
Kategorie- und Dauerfilter; in der Liste steht deshalb nichts aus der Schweiz.
Geprüft und ergebnislos: der offene Datenkatalog der SBB (`data.sbb.ch`)
enthält keinen Datensatz zu Bauarbeiten, und `opentransportdata.swiss` verlangt
für die interessanten Datensätze einen Schlüssel.

Zwei Eigenheiten der DB-Schnittstelle haben Arbeit gemacht:

- **`revision`.** Jede Anfrage muss den Datenstand nennen, auf den sie sich
  bezieht; einen Endpunkt, der ihn allein liefert, gibt es nicht (die
  Weboberfläche bekommt ihn beim Start mitgeliefert). Die Zahl wächst monoton,
  und der Server nimmt ein Fenster von einigen hundert Ständen an.

  Entscheidend ist, dass er sagt, **in welche Richtung** man suchen muss:
  *„Angefragte Revision 3520724 zu alt"* gegen *„Revision 3530000 existiert
  noch nicht"*. Genau diese Unterscheidung fehlte im ersten Wurf — jeder
  Fehlschlag galt als „zu neu", also lief die Suche nach unten, während der
  hinterlegte Ausgangswert in Wirklichkeit veraltet war und es nach oben
  gegangen wäre. Die Folge: **sobald der Startwert alt genug war, blieben die
  deutschen Baustellen stumm aus** und die Liste zeigte wieder nur Österreich.
  Jetzt wird die Fehlermeldung ausgewertet, exponentiell nach oben getastet
  und dann halbiert. Der zuletzt gültige Stand wird gemerkt; im Normalfall
  bleibt es bei einer einzigen kleinen Abfrage.
- **Koordinaten in EPSG:3857**, nicht in Grad.

Gefiltert wird auf **Totalsperrungen ab einer Woche Dauer** und nach
`baustellenID`-Präfix gebündelt: ein Bauvorhaben zerfällt in der Quelle in
viele Einzeleinträge (je Richtung, je Abschnitt, je Zeitfenster), aus 66
Einträgen „1E79F.x" wird ein Vorhaben. Ohne das stünden über viertausend
nächtliche Sperrpausen in der Liste.

Für die ÖBB-Seite gilt weiterhin: nach Kategorie filtern (1–3 sind
Betriebsmeldungen, 4 sind Reisehinweise — ohne den Filter standen 117
„ACHTUNG: Starker Reisetag" in der Liste), nach Land filtern, und richtungs-
wie zeitraumunabhängig entdoppeln.

#### Der Streckenverlauf

Eine gerade Linie zwischen zwei Betriebsstellen läuft quer durchs Gelände,
während die Schiene einen Bogen macht. Deshalb zwei Wege zum echten Verlauf:

- **Die ÖBB liefert ihn mit** — `getPolyline: true` im `HimSearch`-Request.
  Der Schalter gehört in `req`, nicht in `cfg`; dort quittiert ihn HAFAS mit
  *„Parse fail"*.
- **Für die deutschen Abschnitte** kommt er aus OpenStreetMap: deutsche
  Strecken tragen ihre VzG-Nummer als `ref` an den Gleisen. `RailGeometry`
  holt das Stück Netz mit dieser Nummer — begrenzt auf ein Rechteck um die
  beiden Endpunkte, sonst antwortet Overpass mit einer Zeitüberschreitung —
  und sucht darin per Dijkstra den Weg von einem Endpunkt zum anderen.

  **Lücken schließen:** Innerhalb eines Bahnhofs tragen die Gleise meist
  keine Streckennummer; sie klebt an der freien Strecke. Der Graph riss
  deshalb genau dort auseinander, wo die Endpunkte liegen. Enden zweier
  Gleisstücke, die keine vierzig Meter auseinanderliegen, werden verbunden.

Alle Abschnitte kommen in **einer** Overpass-Abfrage mit je einer begrenzten
Teilabfrage — sechzig einzelne Anfragen wären unhöflich und langsam. Pro
Aufruf werden höchstens zwölf Abschnitte nachgeladen, das Ergebnis hält
dreißig Tage (Schienen ziehen nicht um), und die Karte wird so von Aufruf zu
Aufruf genauer. Wo es nicht klappt — keine Streckennummer, in OSM nicht
erfasst, Overpass überlastet — bleibt es bei der geraden Linie; sie wird
gestrichelt gezeichnet, der echte Verlauf durchgezogen.

**Erfolg hält dreißig Tage, Misserfolg nur einen.** Ein leerer Eintrag heißt
nämlich nicht zwingend „gibt es in OSM nicht" — er entsteht genauso, wenn
Overpass an dem Tag nur einen Teil der Gleise geliefert hat. Gemessen an
denselben zwanzig Abschnitten schwankte die Ausbeute je nach erwischter
Instanz zwischen 7 und 11; ohne diese Unterscheidung hätte sich so eine
Schwankung für einen Monat festgesetzt.

Nachgemessen: **41 von 60 deutschen Abschnitten** (68 %) mit echtem
Streckenverlauf, dazu 4 der 35 österreichischen aus den HAFAS-Polylinien. Die
Längen sind plausibel — Berlin Zoologischer Garten bis Friedrichstraße 4,9 km
Verlauf gegen 4,0 km Luftlinie, Meerbeck–Xanten 26,3 gegen 25,3.

Was übrig bleibt, scheitert an der Erfassung, nicht am Verfahren: die Strecke
zerfällt in OSM in mehrere unverbundene Teile (Hochrheinbahn Rheinfelden–
Waldshut, Berlin Bornholmer Straße–Schönholz) oder am gemeldeten Endpunkt
liegt gar kein Gleis (Neustadt–Puttgarden: 52 km bis zum nächsten). Dort bleibt
die gerade Linie.

### Leistung auf schwacher Hardware

Vier Posten, die auf einem älteren Laptop ohne GPU-Beschleunigung spürbar sind
und deshalb entschärft wurden:

- **`backdrop-filter` nur noch auf `.panel` und `.map`.** Vorher lag er auf
  zehn Selektoren, davon acht mit inzwischen deckendem Hintergrund — der Blur
  war dort unsichtbar und kostete trotzdem je eine Compositing-Ebene, bei
  `.journey` sogar eine **pro Ergebniskarte**.
- **Der Hintergrundverlauf liegt auf `body::before`**, nicht mehr per
  `background-attachment: fixed` auf dem `body` selbst. Fixierte Hintergründe
  erzwingen bei jedem Scrollschritt ein Neuzeichnen des ganzen Sichtbereichs;
  als eigene fixierte Ebene wird nur noch verschoben.
- **Die Nerd-Regler entstehen erst beim ersten Öffnen** des Modus. Es sind über
  fünfzig Schieberegler; vorher wurden sie bei jedem Seitenaufruf gebaut, auch
  im Normal-Modus hinter `display: none`. Gemessen: 294 statt 683 DOM-Knoten
  und 1 statt 49 `input[type=range]` beim Start.
- **Kartenschwenks sind auf einen Frame gebündelt.** `pointermove` feuert auf
  schnellen Mäusen über hundertmal pro Sekunde, und jedes `render()` baut
  Kachelgitter und SVG-Overlay komplett neu auf.

**Eine Regel dabei ist wichtig: alles Anklickbare bekommt einen deckenden
Grund.** Karten, Knöpfe, Panels und Eingabefelder benutzen `--surface-card`
bzw. `--field-bg`, nicht das halbtransparente `--surface`. Der Grund ist
handfest: über dem Glas-Panel und der Kartenkachel-Ebene ergibt dieselbe
transparente Fläche je nach Untergrund einen anderen Kontrast. Beim `<select>`
kam dazu, dass das aufgeklappte Menü vom System gezeichnet wird — es erbt die
Textfarbe, malt den Hintergrund aber selbst, was im dunklen Theme fast weißen
Text auf hellem Menü ergab. Transparenz bleibt deshalb den rein dekorativen
Containern vorbehalten: `.panel`, `.map`, `.notice`.

**Zurück-Link anpassen:** Oben links führt ein Knopf zurück zur Hauptseite. Das
Ziel steht in `index.html` und zeigt standardmäßig auf `/`:

```html
<a class="back" href="/" aria-label="Zurück zur Hauptseite">
```

Liegt das Tool in einem Unterordner einer größeren Seite, trag dort die
gewünschte Adresse ein.

**Tab-Icon anpassen:** In `index.html` stehen drei `<link rel="icon">`-Zeilen
mit absoluten Pfaden auf die Icons der Hauptseite:

```html
<link rel="icon" type="image/svg+xml" href="/assets/pictures/MMR_v2.svg?v=2">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/pictures/MMR_v2.png?v=2">
<link rel="apple-touch-icon" href="/assets/pictures/MMR_v2.png?v=2">
```

Absolut, damit sie auch aus einem Unterordner heraus stimmen — die Dateien
liegen ja bei der Hauptseite, nicht beim Tool. Läuft das Tool auf einer eigenen
Domain, leg die Icons dort ab und trag den passenden Pfad ein. Das PNG ist
Absicht: SVG-Favicons zeichnen nicht alle Browser, unter Windows blieb der Tab
sonst leer.

**Als Teilseite einbetten:** Übernimm den Inhalt von `<div class="wrap">` in deine
Seite und binde `style.css` sowie `<script type="module" src="assets/js/app.js">`
ein. Wichtig: Das Skript braucht `type="module"`.

---

## Wie die Bewertung funktioniert

### Wie ein Zug heißt

Im Fernverkehr ist die **Zugnummer** der Name: ein ICE 593 fährt heute so und
morgen anders, und genau so steht es an der Anzeigetafel. Im Nahverkehr ist es
umgekehrt — dort steht die **Linie** angeschrieben, und die Zugnummer ist eine
interne Betriebsnummer, die auf keiner Tafel auftaucht.

Die App zeigte lange die Nummer, auch im Nahverkehr: aus einer S 11 wurde
„S 20318". Der Grund lag im HAFAS-Feld, das gelesen wurde. HAFAS liefert für
dieselbe Fahrt:

```
name  = "S 33 (Zug-Nr. 20326)"     nameS = "S 33"
prodCtx.num  = "20326"             prodCtx.line = "33"
```

Genommen wurde bisher der *längere* der beiden Namen — eine Regel, die im
Fernverkehr richtig ist (dort steht die Nummer mal nur im einen, mal nur im
anderen) und im Nahverkehr genau danebengreift. Jetzt gilt:

- `prodCtx.line` gesetzt → **die Linie** benennt den Zug (`S 33`, `RE 48`).
  Trägt die Linie die Gattung schon in sich (`RE3`), wird sie nicht doppelt
  davorgesetzt.
- `prodCtx.line` leer → Gattung plus Zugnummer (`ICE 593`).
- Der Zusatz „(Zug-Nr. …)" fliegt aus dem Produktnamen; er ist eine
  Anzeigehilfe von HAFAS und gehört in keine Beschriftung.

Ein Nebeneffekt, der Arbeit gemacht hat: `sameTrain()` verglich zwei Meldungen
über ihre **Beschriftung**. Das ging, solange die Nummer darin stand — mit der
Linie nicht mehr, denn auf der S 33 sind zu jeder Zeit mehrere Züge unterwegs.
Verglichen wird jetzt die Zugnummer, und nur wo keine vorliegt, der
Produktname.

#### „DPN RB37 · Unbekannte Gattung"

Was in den Fahrplandaten als Gattung steht, ist bei allem, was **nicht die DB
selbst fährt**, ein Sammelkürzel des Betreibers. Die HLB-Regionalbahn
Frankfurt–Gießen kommt bei der ÖBB als `DPN` („Nahreisezug") und bei bahn.de als
`DRB` herein; am Bahnsteig steht `RB 37`. `TRAIN_TYPES` kennt diese Kürzel
naturgemäss nicht, und so landeten sämtliche Privatbahnen bei „Unbekannte
Gattung" — mit allem, was daran hängt: Komfortwert 4 statt 3, kein
Deutschlandticket, und in der Liste stand „DPN RB37".

Die eigentliche Gattung steckt in der **Linie**. `typeOf()` geht deshalb in vier
Stufen vor: die Gattung selbst, dann der Buchstabenkopf der Linie (`RB37` → RB,
`S8` → S), dann das Betreiberkürzel (`DPN`/`DRB`/`HLB`/… → Nahverkehr,
`DPF` → Fernverkehr), zuletzt der ausgeschriebene Produktname („Nahreisezug",
„Intercity-Express"). Erst wenn alle vier nichts hergeben, ist die Gattung
wirklich unbekannt — und dann steht wenigstens das rohe Kürzel da statt eines
Fragezeichens.

Normalisiert wird **in `trainLabel()` selbst**, nicht an den Aufrufstellen: die
Beschriftung entsteht in der Trefferliste, in der Live-Verfolgung und an den
Zügen auf der Karte, und „DPN RE99" stand vorher an allen dreien.

Serverseitig gehört dieselbe Liste in `Fares::LOCAL_CATEGORIES`, sonst fällt
jeder dieser Züge aus dem **Deutschlandticket** heraus, obwohl es dort gilt.

Der zweite Teil desselben Problems steckt in der DB-Antwort: `linienNummer` ist
im Nahverkehr fast immer leer, die Linie steht nur im `mittelText` (`"RB37"`).
Im Fernverkehr ist derselbe `mittelText` dagegen die Zugnummer mit Gattung davor
(`"ICE 2374"`) — deshalb übernimmt `DbVendo::lineOf()` ihn nur, wenn er die
Zugnummer *nicht* enthält.

### Sortierung der Trefferliste

Über der Liste steht ein Menü mit drei Möglichkeiten.

**Voreingestellt ist „Abfahrt — chronologisch".** Das ist die Reihenfolge, in
der die Züge fahren: man sucht sich die Abfahrt, die zeitlich passt, und
vergleicht erst dann. Die Empfehlung mischt Preis und Dauer zu einer Punktzahl
— als Voreinstellung verbirgt sie, wonach eigentlich sortiert wurde.

| Auswahl | Reihenfolge |
|---|---|
| **Empfehlung** (Normal) / **Strecke & Komfort** (Nerd) | das Bewertungsmodell des jeweiligen Modus, siehe unten |
| **Preis** | günstigste zuerst; bei Gleichstand die frühere Abfahrt. Verbindungen **ohne** Preisangabe stehen am Ende — eine fehlende Angabe ist kein Nullpreis |
| **Abfahrt** | chronologisch. Die Suche liefert die Verbindungen ab der gewählten Zeit, nach unten wird es also später |

Eine ausdrückliche Sortierung **schlägt beide Bewertungsmodelle**: wer „nach
Preis" wählt, will eine Preisliste sehen, keine Empfehlung — und im Nerd-Modus
auch keine Gruppierung nach Routenvarianten. Die Variantenüberschriften
entfallen dann.

Umsortiert wird sofort, ohne neue Netzabfrage — es ist dieselbe Trefferliste in
anderer Reihenfolge, auch über nachgeladene Seiten hinweg. Dabei springt die
**Auswahl zurück an den Listenanfang**: die Karte über der Liste zeigt immer die
ausgewählte Verbindung, und bliebe sie an der alten hängen, änderte sich im
halben Bild nichts, obwohl die Liste längst neu sortiert ist. Bei Modus- und
Reglerwechseln bleibt die Auswahl dagegen an ihrer Verbindung kleben — dort hat
man sich für eine entschieden und schraubt nur an der Reihenfolge drumherum
(`rerank({ keepSelection })`).

### Normal-Modus

Preis, Dauer und Umstiege werden je auf 0–1 normiert und gewichtet
(40 % / 40 % / 20 %). Kleinste Punktzahl gewinnt.

### Nerd-Modus

Kein Preismodell, sondern eine Frage des Weges. Die Treffer werden zu
**Routenvarianten** gruppiert, und innerhalb einer Variante gilt:

1. wenigste Umstiege
2. bei Gleichstand die kürzere Fahrt
3. bei Gleichstand der angenehmere Zug

Die **Reisezeit wird bewusst nicht verrechnet**. Sie entscheidet erst, wenn alles
andere gleich ist — es gibt keinen Wechselkurs zwischen einer Stunde Fahrzeit und
einem Umstieg. Der Preis steht an der Karte, geht hier aber nicht in die Wertung
ein. In welcher Reihenfolge die Varianten stehen, bestimmt deine Bewertung unter
**Lieblingsstrecken** und **Lieblingszüge**.

### Strecken erkennen

`assets/js/data/routes.js` beschreibt gut dreißig Korridore — Gotthard-Basistunnel,
Gotthard-Bergstrecke, SFS Köln–Rhein/Main, NBS Wendlingen–Ulm, Allgäubahn, Gäubahn,
Westbahn, Arlberg, Semmering und so weiter, je mit Streckenhöchstgeschwindigkeit.
Erkannt werden sie an den **Namen der Zwischenhalte**: die Wegpunkte einer Strecke
müssen in konsistenter Reihenfolge im Zuglauf vorkommen.

Zwei Schwellen verhindern Fehlzuordnungen, weil sich Korridore Endpunkte teilen:

- **Deckung ≥ 60 %** der Wegpunkte. Sonst würde jeder Zug München–Augsburg auch
  als „München–Augsburg–Nürnberg" gelten.
- **höchstens ein ausgelassener Wegpunkt am Stück**. Wer Kempten und Immenstadt
  überspringt, fährt nicht über das Allgäu, sondern über Memmingen.

Beanspruchen mehrere Korridore denselben Abschnitt, gewinnt der mit den meisten
wiedergefundenen Wegpunkten. Eine feste Deckungsschwelle taugt dafür **nicht**:
schnelle Züge halten an den Zwischenpunkten gerade nicht — ein ICE über die SFS
Nürnberg–Ingolstadt lässt Allersberg und Kinding aus und wäre ausgerechnet als
der Zug durchgefallen, für den die Strecke gebaut wurde. Ein einzelner
gemeinsamer Halt gilt dagegen als Übergang, nicht als Widerspruch: Bern–Olten
und Bern–Brig treffen sich in Bern und gelten beide als befahren.

Die Fälle, an denen frühere Fassungen gescheitert sind, stehen als Test in
`bin/test_routes.mjs`:

```bash
node bin/test_routes.mjs
```

Der Knopf **„Schnelle Strecken bevorzugen"** setzt alle Regler auf einmal aus der
Streckenhöchstgeschwindigkeit — 300 km/h gibt +3, eine Bergstrecke mit 90 km/h −2.

**Was die Liste nicht abdeckt**, wird geschätzt: aus Luftlinie und Fahrzeit
ergibt sich ein Durchschnittstempo, und der Regler *„Unbenannte Strecken nach
Tempo"* bestimmt, wie stark das zählt. Diese Einträge sind gestrichelt und mit
`ø` gekennzeichnet, weil die Zahl etwas anderes bedeutet als bei den gepflegten
Strecken — eine Reisegeschwindigkeit inklusive Halten, keine
Streckenhöchstgeschwindigkeit.

### Live-Verfolgung

Jede Verbindung hat einen Knopf **„Live verfolgen"**. Das Panel unter der Karte
frischt alle 30 Sekunden die Echtzeitlage aller Abschnitte auf: Verspätung,
Ist-Zeiten je Halt, Gleiswechsel, Meldungen. Für München kommen die
Störungsmeldungen der MVG dazu, gefiltert auf die tatsächlich benutzten Linien.

Grundlage ist die HAFAS-Journey-ID (`leg.jid`), die jeder Zugabschnitt mitbringt.
Verbindungen, deren Fahrplan von der DB statt der ÖBB stammt, haben keine. Die
Verfolgung war dort früher **komplett abgeschaltet** — kein Knopf, keine
Anzeige —, obwohl die DB Ist-Zeiten schon in der Suchantwort mitliefert. Jetzt
zeigt sie, was bekannt ist, und frischt auf, was sich auffrischen lässt; dass
der Stand aus der Suche stammt, steht dabei.

Aus derselben Ecke kamen drei Fehler, die das Feld unbrauchbar machten:

- **`sameTrain` war nie importiert.** `trainPosition()` benutzt es, um die
  eigene Fahrt unter den gemeldeten Live-Zügen wiederzufinden. Ohne den Import
  warf jede Positionsbestimmung einen `ReferenceError` — und weil `pushToMap()`
  im `finally`-Zweig von `refresh()` steckt, riss das die ganze Auffrischung mit
  sich. Und zwar genau dann, wenn man **tatsächlich im Zug saß**: vorher und
  nachher liefert `currentEntry()` `null` und die Zeile wird gar nicht erreicht.
- **Ausstattung stand da, wo Störungen hingehören.** HAFAS mischt unter `msgL`
  auch die Zugattribute (Typ `A`), und so las man in der Live-Verfolgung jedes
  Regionalzuges „Klimaanlage", „Fahrradmitnahme begrenzt möglich",
  „Fahrzeuggebundene Einstiegshilfe" — und sonst nichts. Die werden jetzt
  ausgefiltert; HIM-Meldungen bleiben unangetastet.
- **Münchner Busmeldungen an einem ICE.** Die MVG-Störungen werden über die
  Linienbezeichnung zugeordnet, und im Tram- und Busnetz heißen Linien schlicht
  „19", „58", „722". Genau so heißt aber auch die Ersatz-Linienkennung, die
  HAFAS im Fernverkehr aus der Zugnummer bildet — unter einem *ICE 722*
  München–Frankfurt hingen deshalb drei Meldungen über eine verlegte
  Bushaltestelle am Kennedyplatz. Abgeglichen wird jetzt nur noch, was auch
  wirklich S-Bahn, U-Bahn, Tram oder Bus ist.

Und eine vierte Kleinigkeit, die wie ein Totalausfall aussah: **das Feld sitzt
unter der Karte**, der Knopf steht auf einer Verbindungskarte weiter unten. Bei
der fünften Verbindung liegt zwischen beiden eine Bildschirmhöhe, und ein Klick
schien nichts zu tun. Jetzt springt die Seite hin — im nächsten Frame, denn das
Neuzeichnen der Trefferliste ändert vorher die Seitenhöhe.

#### Auch U-Bahn, Tram und Bus

Die Verfolgung lud den Zuglauf nur über die HAFAS-`jid` — und die fehlt
genau dort, wo man sie in München bräuchte: U-Bahn, Tram und Bus kennt
HAFAS nicht, ihr Fahrplan kommt von der DB oder der MVG. Dort stand deshalb
immer „keine Echtzeitdaten". Jetzt gibt es drei Quellen
(`LiveTracker.sourceOf()`):

| Quelle | wofür | wie |
|---|---|---|
| **HAFAS** (`jid`) | Fernverkehr, Regionalzug, S-Bahn | wie bisher |
| **DB** (`dbJourneyId`) | alles mit DB-Fahrplan, also auch U-Bahn und Tram | `/web/api/reiseloesung/fahrt` — der Zuglauf-Endpunkt von bahn.de, Soll- und Ist-Zeit je Halt, rund 0,2 s |
| **MVG** | Abschnitte aus der MVG-Suche (Stadtfahrt, Zubringer) | die Abfahrtstafel an Ein- und Ausstieg: die eigene Bahn über Linie und Planminute, am Ausstieg über dieselbe Fahrtnummer (`tripCode`) |
| **opendata.ch** | Schweizer Abschnitte, wenn HAFAS nur den Fahrplan hat | die Prognose der SBB auf der Abfahrts- und Ankunftstafel von transport.opendata.ch, gefunden über Gattung, Planminute und Richtung (die Tafel nennt bei Fernzügen die Linie, nicht die Zugnummer). Ohne Schlüssel — aber der Dienst drosselt (`HTTP 429`); dann bleibt es beim Fahrplan |

Die MVG meldet dabei nur Ein- und Ausstieg; die Halte dazwischen kommen aus
der Verbindung und werden um die gemeldete Verspätung verschoben. So bleiben
Halteliste und geschätzte Position auf der Karte vollständig. Echtzeit gibt
die MVG erst kurz vor der Abfahrt heraus — eine U-Bahn in zehn Minuten hat
noch keine.

Dazu zwei Verbesserungen, die jeden Zug betreffen:

- **Die Münchner S-Bahn kennt HAFAS oft nur nach Fahrplan.** Hat die DB für
  denselben Zug Ist-Zeiten, gelten jetzt die (`dbJourneyId` kommt beim
  Abgleich mit der DB an jeden Abschnitt).
- **Die Verspätung am Abschnitt kommt aus dem nachgeladenen Zuglauf**, nicht
  mehr aus den Ist-Zeiten der Suche. Die standen nach einer halben Stunde
  Fahrt noch auf dem alten Stand — eine U2 mit +1 min stand als „pünktlich"
  da.

Nachgeprüft: Sendlinger Tor → Hauptbahnhof (U2, DB-Fahrplan) zeigte die
Ist-Zeiten je Halt; Odeonsplatz → Goetheplatz (U6, MVG) −1 min am Einstieg
und am Ausstieg, wiedergefunden über die Fahrtnummer.

#### Fahrgastrechte

Sobald die Echtzeit eine Verspätung am Ziel erwarten lässt, steht unter der
Warnung, was einem zusteht — grün, weil es in einer schlechten Lage die gute
Nachricht ist. Nachgelesen bei DB und SBB:

| erwartete Verspätung am Ziel | was gilt |
|---|---|
| ab 20 min, innerdeutsch | **Zugbindung aufgehoben** (DB): mit Sparpreis darf man einen anderen Zug nehmen |
| ab 60 min, international | Zugbindung aufgehoben (DB) |
| ab 60 min | **25 %** des Fahrpreises (EU und Schweiz) |
| ab 120 min | **50 %** |

Beträge unter 4 € (DB) bzw. 5 CHF (SBB) werden nicht ausgezahlt; das steht
dabei, ebenso die Links zum Antrag bei DB, ÖBB oder SBB, je nachdem welche
Länder die Reise berührt. Gemessen wird gegen die **ursprünglich gebuchte**
Ankunft — nach einem Umdisponieren ist die Verbindung eine andere, der
Anspruch hängt aber an der ersten. Mit Benachrichtigungen kommt beim
Überschreiten von 60 und 120 Minuten je eine Meldung.

Nachgeprüft an einem echten Fall: ICE 693 Frankfurt → München, +23 min am
Ziel — der Kasten meldete die aufgehobene Zugbindung.

#### Fahrt teilen

„Teilen" in der Verfolgung legt die Fahrt auf dem eigenen Server ab
(`POST ?action=share`) und liefert einen Link `?live=<Kennung>`. Wer ihn
öffnet, sieht dieselbe Verfolgung — „Geteilt" statt „Live" —, und dessen
Browser holt sich die Echtzeit selbst. Auf dem Telefon öffnet sich das
Teilen-Menü mit einem Satz wie „Unterwegs nach München Hbf mit ICE 693,
Ankunft 23:38 (+23 min)", sonst landet beides in der Zwischenablage.

- Gespeichert wird **nur die Verbindung** (Züge, Halte, Zeiten, Verlauf) —
  kein Standort, keine Kennung des Teilenden, keine Preise. Höchstens 400 kB.
- Der Link hält bis **sechs Stunden nach der Ankunft**, höchstens drei Tage.
  Ist die Fahrt schon angekommen, sagt die App das, statt eine leere
  Verfolgung zu öffnen.
- Eine geteilte Fahrt **überschreibt nicht** die eigene gespeicherte
  Verfolgung des Empfängers.
- Das Anlegen kostet im Rate-Limit 10 Punkte — jeder Aufruf schreibt eine
  Datei.

#### Die gelben Kästen sind kurz

Die Meldungen in der Verfolgung waren zu lang. Die MVG schickt zu jeder
Störung einen Fließtext mit — Ursache, betroffene Halte, Ersatzverkehr,
Umleitungen, oft mehrere Absätze —, und der stand vollständig da; auf dem
Telefon schob eine einzige Meldung die Zugabschnitte aus dem Bild. Jetzt:

- MVG-Meldungen zeigen nur die Überschrift, höchstens zwei Zeilen; „mehr"
  klappt den Text auf. Ab der dritten sind sie gebündelt.
- Meldungen am Zug sind auf zwei Zeilen gekürzt (vorher vier), ein Tipp
  zeigt den ganzen Satz.
- Von den Alternativen stehen zwei sofort da, der Rest ist einen Tipp
  entfernt — vier Vorschläge füllten vorher den Bildschirm, bevor man sah,
  welcher Zug betroffen ist.

#### Jeder Abschnitt erscheint, sobald er da ist

Die Verfolgung wartete auf **alle** Antworten und zeichnete danach einmal.
Die HAFAS-Abfrage je Zuglauf ist aber unterschiedlich schnell — nachgemessen
0,3 s für den einen Abschnitt und **6,8 s** für den anderen. Man sah also
sieben Sekunden lang „lädt …", obwohl die Hälfte längst dastand.

Schlimmer noch: dahinter hingen **seriell** die Alternativensuche (eine
vollständige Verbindungssuche) und die MVG-Meldungen — und erst danach wurde
gezeichnet. Die Verspätung, wegen der man überhaupt hinschaut, wartete auf
zwei Dinge, die sie gar nicht braucht.

Jetzt zeichnet jeder Abschnitt für sich, sobald seine Antwort da ist, und das
Beiwerk läuft nebeneinander und reicht nach. Gemessen am selben Umstieg:

| | Feld gefüllt | erster Zug | zweiter Zug |
|---|---|---|---|
| vorher | — | — | erst nach allem, ~7 s |
| jetzt | **21 ms** | **463 ms** | 5,9 s |

#### Ein ausgefallener Zug ist kein knapper Umstieg

Die Anschlusswache rechnete ausschließlich, ob die Lücke zwischen Ankunft und
Abfahrt noch reicht. Bei einem Zug, der **gar nicht fährt**, ist diese Lücke
aber tadellos — und die Verfolgung meldete seelenruhig „alles gut". Genau das
ist im Betrieb passiert: der Zug fiel aus, die Information kam nicht an, und
Alternativen wurden nie geladen.

Jetzt wird zuerst nach Ausfällen gesucht, und erst danach nach knappen
Umstiegen. Drei Stellen sagen es, und keine ist allein verlässlich:

- `leg.cancelled` aus der Suche — die DB setzt es,
- `data.cancelled` aus dem nachgeladenen Zuglauf,
- der **Einstiegshalt** im Zuglauf: ein Zug kann fahren und trotzdem den
  eigenen Bahnhof auslassen. Das ist der Fall, den man am ehesten übersieht.

Der Alarm heißt dann „Zug fällt aus", und die Alternativen ab dem
Einstiegsbahnhof werden geladen wie bei einem verpassten Anschluss — mit
demselben „übernehmen"-Knopf.

**Auch die Trefferliste zeigt es jetzt.** Ein ausgefallener Zug stand dort
vorher gar nicht drin: die Verbindung sah aus wie jede andere. Das Abzeichen
steht ganz vorn in der Zeile, noch vor Verspätung und knappem Umstieg — die
sind dann ohnehin gegenstandslos.

#### Vier Sorten Rauschen, die als „Meldung" durchgingen

Unter jedem Abschnitt hingen alle Meldungen des Zuglaufs, ungefiltert und in
voller Länge. Nachgesehen, was HAFAS dort tatsächlich liefert:

| Typ | Beispiel | Urteil |
|---|---|---|
| `type='A'` | „Klimaanlage", „Rollstuhlstellplatz", „Fahrradmitnahme begrenzt möglich" | **Ausstattung**, keine Meldung |
| `code='ZN'` | „Loreley", „Wilder Kaiser", „ICE International" | **Zugname**, als Meldung sinnlos |
| HIM-Volltext | mehrere Absätze, jede betroffene Linie einzeln | **zu viel** — die fette Kopfzeile reicht |
| Meldungen von woanders | „Aufzug in Salzburg defekt" auf München–Freiburg | **nicht auf der eigenen Strecke** |

Die ersten beiden fliegen raus. Vom HIM bleibt **nur die Kopfzeile** — das,
was in der Bahn-App fett dasteht; der Fliesstext darunter zählt jede betroffene
Linie und jede S-Bahn einzeln auf und hilft niemandem, der im Zug sitzt. Fehlt
die Kopfzeile, tritt der erste tragende Satz des Textes an ihre Stelle, gekürzt
auf 130 Zeichen — `summarise()` wirft dabei die Formelware weg („Wir bitten um
Verständnis", „Bitte beachten Sie die Aushänge"):

```
[284 Zeichen] Wegen Bauarbeiten kommt es im Streckenabschnitt zwischen Köln
              Messe/Deutz und Köln Hbf zu Fahrplanänderungen. Bitte beachten
              Sie die Aushänge … Wir bitten um Verständnis … Die
              Fahrradmitnahme ist in den Ersatzzügen nicht möglich.
      ↓
[109 Zeichen] Wegen Bauarbeiten kommt es im Streckenabschnitt zwischen Köln
              Messe/Deutz und Köln Hbf zu Fahrplanänderungen.
```

**Der vierte Punkt war der ärgerlichste.** Ein Zuglauf reicht weiter als die
eigene Fahrt. Auf **München–Freiburg** standen unter dem ICE 118:

```
Bahnsteig 91/92 in Villach Hbf nicht barrierefrei
Technische Störung des Personenlift in Salzburg Hbf - Aufgang Schallmoos
```

Beides richtig, beides mehrere hundert Kilometer entfernt — derselbe ICE kommt
aus Graz und war dort vorher entlanggefahren. Der Zuglauf hat 34 Halte, gefahren
werden davon sieben.

HAFAS verortet jede Meldung in `fLocX`/`tLocX`. Das sind allerdings Indizes in
die **Ortsliste**, nicht in die Halteliste — der Umweg geht über den Namen. Jede
Meldung trägt seither ihren Geltungsbereich mit, und die Anzeige schneidet ihn
gegen das eigene Teilstück. Aus den drei Meldungen wurde eine
(„Umgekehrte Reihung", die für den ganzen Lauf gilt).

Im **Zug-Panel an der Karte** wird dagegen bewusst nichts geschnitten: dort
steht der ganze Lauf, also gehören auch dessen Meldungen dazu. Statt zu filtern
steht der Geltungsbereich als zweite Zeile unter der Meldung
(„Krimml Bahnhof – Mittersill Bahnhof") und ist anklickbar — der Klick holt die
betroffenen Halte in der Liste ins Bild und hebt sie kurz hervor. Sonst sucht
man den defekten Aufzug am falschen Bahnhof.

Gedeckelt wird deshalb erst **nach** dem Schneiden: würde der Server schon bei
drei abschneiden, fiele womöglich die relevante Meldung zugunsten einer aus
Villach weg. Dazu, unverändert: was in dieser Verfolgung schon einmal stand,
kommt kein zweites Mal; die erste steht da, der Rest wartet zugeklappt.

Die Verfolgung **überlebt Neuladen und neue Suchen**: die Verbindung liegt unter
`train-maxxing:tracked` im localStorage und wird beim Start wieder aufgenommen,
solange die Fahrt noch läuft. Taucht sie in der aktuellen Trefferliste nicht auf
— nach einer anderen Suche oder nach dem Umdisponieren — steht oben eine Zeile
„Du verfolgst …", damit sie nicht unsichtbar weiterläuft.

**Alternativen sind auswählbar, nicht nur Information.** Schon in der
Trefferliste steht unter jedem Umstieg von 1–4 Minuten, was die nächsten
Verbindungen ab dem Umsteigebahnhof wären — jede davon per „übernehmen"
anzunehmen. Die Verbindung wird dann an Ort und Stelle durch die
umdisponierte Variante ersetzt und als solche gekennzeichnet; die Abschnitte
davor bleiben stehen. Dieselbe Mechanik (`spliceJourney`) benutzt die
Live-Verfolgung, wenn ein Anschluss unterwegs platzt.

**Anschlusswache.** Aus den Ist-Zeiten wird je Umstieg gerechnet, ob er noch zu
schaffen ist: der Zubringer kommt um X an, der Anschluss fährt um Y ab. Liegt Y
vor X, ist der Anschluss weg — auch wenn im Fahrplan zwanzig Minuten standen.
Unter zwei Minuten Rest gilt als gefährdet.

Dann lädt die App die nächsten Verbindungen ab dem Umsteigebahnhof und bietet sie
zur Auswahl an. Ein Klick auf **„übernehmen"** ersetzt die Verbindung ab dem
geplatzten Umstieg — die bereits gefahrenen Abschnitte bleiben stehen, man sitzt
ja im Zug — und die Verfolgung läuft mit der neuen Route weiter.

Alarm gibt es nur bei echten Echtzeitdaten. Ein knapper *Fahrplan*-Umstieg steht
schon an der Verbindungskarte und würde hier nur doppelt warnen.

**Auf der Karte** wird die verfolgte Verbindung grün hervorgehoben — Verlauf,
Start und Ziel. Die Zugposition selbst ist **rot**: alle Live-Züge des
Ausschnitts sind grün, und der eigene ging darin unter, obwohl er der einzige
ist, den man wirklich sucht.

Der Zug ist dabei **ein einziger Punkt** — derselbe kleine Punkt wie jeder
andere Live-Zug, nur rot statt grün. Einen zweiten, größeren Marker gibt es
nicht; er stand nur dem eigentlichen Punkt im Weg.

Er wird **immer** gezeichnet, solange verfolgt wird. Das ist nicht
selbstverständlich, denn die Positionsantwort ist auf 40 Züge im Ausschnitt
gedeckelt — zoomt man heraus, fällt der eigene Zug regelmäßig heraus. Steht er
nicht in der Antwort, ergänzt ihn `RouteMap.trainsOnMap()` aus der zuletzt
bekannten Position, mitsamt Tooltip und Antippbarkeit. Sonst wäre ausgerechnet
der Zug unsichtbar, um den es geht.

War die Position aus dem Fahrplan hochgerechnet statt gemeldet, bleibt der
Punkt **hohl** und der Tooltip sagt es dazu. Für die Hochrechnung wird der
Restfahrplan um die bekannte Verspätung verschoben; sonst läge ein verspäteter
Zug außerhalb jedes Zeitfensters und wäre gar nicht auffindbar.

Hochgerechnet wird zwischen zwei **Halten**, also auf der Luftlinie — und die
schneidet jeden Bogen ab. Der Punkt saß dadurch sichtbar neben der Linie, auf
der er fahren sollte, im Extremfall zig Kilometer. `snapToLine()` zieht ihn
deshalb auf den gezeichneten Streckenverlauf des Abschnitts: Lotfußpunkt auf
das nächste Segment, auf das Segment begrenzt. Gemeldete Positionen bleiben
unangetastet — die liegen ohnehin auf dem Gleis.

Dass die Ergänzung beim Zeichnen passiert und nicht in der Verfolgung, ist
wichtig: die Live-Züge wechseln bei jedem Schwenk und Zoom, die Verfolgung
rechnet nur alle 30 s. Aus veralteten Daten entschieden, blieb der Punkt grün
oder verschwand beim Herauszoomen.

**Wiedererkannt** wird der Zug über `sameTrain()` — Bezeichnung („ICE 516"),
ersatzweise die bloße Nummer, wenn der Positionsmeldung die Gattung fehlt.
Die `jid` taugt dafür nur bedingt: HAFAS baut sie pro Anfrage neu auf, die
Kennung aus der Verbindungssuche und die aus der Positionsmeldung sind deshalb
in aller Regel verschieden.

**Mitfahren (GPS)** schaltet `watchPosition` dazu: die Position landet auf der
Karte, und die Route wird zugeordnet — „Zwischen Augsburg Hbf und Günzburg —
25,1 km hinter Augsburg Hbf. Noch 20 Halte." Gesucht wird dabei der *Abschnitt*
mit der kleinsten Entfernungssumme zu beiden Enden, nicht der nächstgelegene
Halt allein: zwischen zwei Halten kann der vor einem liegende näher sein als der
hinter einem. Die Position verlässt den Browser nicht.

### Ergebnisliste und Umstiege

Angezeigt werden **sechs Verbindungen**; der Knopf darunter klappt erst die
restlichen geladenen auf und holt danach die nächste Seite. Das ist nötig, weil
HAFAS je Anfrage bei rund sechs Treffern deckelt — weitere Abfahrten gibt es nur
über den Blätter-Kontext der vorigen Antwort.

**In beide Richtungen.** Die Uhrzeit im Formular ist ein Wunsch, kein Fahrplan:
wer 08:00 einträgt, nimmt oft gern den 07:41 — nur suchte HAFAS ab der genannten
Zeit ausschließlich nach vorne, und der 07:41 stand nirgends. Über der Liste
sitzt deshalb ein zweiter Knopf für **frühere Verbindungen**. Er benutzt
denselben Mechanismus rückwärts (`outCtxScrB` statt `outCtxScrF`) und hängt die
Treffer an denselben Datensatz an, statt die Suche mit anderer Uhrzeit zu
wiederholen und alles Gefundene wegzuwerfen. Sortiert wird nach Abfahrt, die
neuen Verbindungen stehen also vorne — und die Zahl der sichtbaren Karten wächst
entsprechend mit, sonst hätte man geladen und zugleich etwas verloren.

Die Umsteigezeit steht standardmäßig auf **kürzestmöglich**. Damit tauchen auch
Verbindungen mit vier Minuten Umstieg auf — und für genau die (1 bis 4 Minuten)
lädt die App den **nächstspäteren Anschluss** nach und schreibt ihn unter die
Warnung: „Verpasst? 13:36 → 14:53 · SBB 19732 · S 18955 · +30 min später am Ziel."
Die Frage bei einem Vier-Minuten-Umstieg ist nicht, ob man ihn schafft, sondern
was passiert, wenn nicht.

An **jedem** Umstieg steht außerdem der Lageplan des Umsteigebahnhofs — siehe
oben. Er hing früher an denselben vier Minuten und verschwand damit dort, wo man
ihn genauso braucht: auch bei zwanzig Minuten will man wissen, ob man quer durch
den Bahnhof muss.

### Zugkomfort und die Sache mit dem ICE 4

**Die Fahrplandaten enthalten keine Baureihe.** Du bekommst Gattung und Zugnummer
(`ICE 118`, `RJX 262`), aber nirgends steht, ob das ein ICE 4 (BR 412) oder ein
ICE 3neo (BR 408) ist.

Im Nerd-Modus bewertest du deshalb unter **Lieblingszüge** die Fahrzeuge selbst —
ICE 4, ICE 3neo, Giruno, railjet und so weiter, jeweils von −5 (meiden) bis +5
(bevorzugen). Die Bewertung greift, sobald das Fahrzeug bekannt ist. Dafür gibt
es vier Wege, in dieser Reihenfolge:

1. **Die Wagenreihung liefert die Baureihe** (BR 412 → ICE 4). Nur deutscher
   Fernverkehr, nur am Reisetag, und nur wenn in `config.php` aktiviert — und
   zurzeit gar nicht, siehe unten.
2. **Derselbe Zug fuhr zuletzt mit dieser Baureihe.** Was die Wagenreihung je
   geliefert hat, merkt sich `Fleet.php` unter der Zugnummer.
3. **Auf dieser Strecke verkehrt nur dieses Fahrzeug.** Zürich–München ist ein
   ETR 610, die Gattung ECE gibt es nur für den Giruno — siehe `FLEET_RULES`.
4. **Die Gattung lässt nur ein Fahrzeug zu.** railjet, Nightjet, WESTbahn und
   TGV sind damit immer eindeutig — im UI mit „immer erkennbar" markiert.

Ist das Fahrzeug unbekannt, greift die Gattungsbewertung aus
`assets/js/data/trains.js` — das Tool rät nicht. An jeder Verbindung steht, ob
das Modell erkannt wurde und woher.

Die frühere Variante mit Zugnummernbereichen ist entfallen: Nummernkreise sind
nicht stabil genug, um daraus verlässlich auf eine Baureihe zu schließen.

### Woher das Fahrzeug sonst noch kommt

Die Wagenreihung ist die harte Quelle — und sie fällt oft aus. Sie gilt nur für
deutschen Fernverkehr, nur am Reisetag, und sie hängt an einem privaten Dienst.
**Zum Stand 4. September 2026 antwortet dieser Dienst nicht mehr** (siehe unten).
Ohne ihn blieb es bei der Gattung, obwohl auf manchen Strecken gar nichts
anderes fahren *kann*. Zwei Ergänzungen schließen die Lücke:

**1. Strecken- und Gattungsregeln (`FLEET_RULES` in `data/trains.js`).** Wo der
Umlauf eindeutig ist, steht er als Datenzeile da:

```js
{ model: 'astoro', categories: ['EC'], between: [/z(ü|ue)rich/i, /m(ü|ue)nchen/i],
  note: 'Zürich–München fährt seit der Elektrifizierung über Lindau mit ETR 610.' },
```

Gesucht wird in den **Halten**, nicht nur in Start und Ziel: ein EC
München–Zürich, den man erst ab Memmingen benutzt, ist derselbe Zug. Ohne
`between` genügt die Gattung allein. Eine Regel gehört nur dorthin, wenn dort
tatsächlich nur ein Fahrzeugtyp verkehrt; geraten wird nicht.

**Dieselbe Fahrt heisst je nach Quelle anders.** Der EC/ECE München–Zürich
läuft bei der DB als **ECE**, bei ÖBB und SBB als **EC**. Wer nur eine der
beiden Gattungen einträgt, bekommt das Fahrzeug je nach Fahrplanquelle mal
angezeigt und mal nicht — die Regel führt deshalb beide.

**Und die Muster nennen mehr als die Endpunkte.** Ein Abschnitt
Memmingen–Lindau ist derselbe Zug, aber weder „München" noch „Zürich" kommt
darin vor; nur die Richtung nennt einen der beiden. Mit „Zürich *oder* St.
Gallen" auf der einen und „München *oder* Lindau *oder* Memmingen …" auf der
anderen Seite passt jedes Teilstück. Was damit nicht geht: ein Abschnitt, der
ganz auf deutscher Seite liegt *und* Richtung München fährt — dort steht in den
Daten nichts Schweizerisches. Lieber diese Lücke als ein geratenes Fahrzeug:
sonst würde der EC München–Innsbruck mitgefangen, und genau das prüft ein Test.

**Die Reihenfolge zählt: die erste passende Regel gewinnt.** Deshalb stehen die
streckenscharfen Regeln oben und die pauschalen unten. Andersherum ist es schon
schiefgegangen: die Gattungsregel „ECE ⇒ Giruno" stand zuerst und fing damit den
**ECE Zürich–München** ein, der in Wirklichkeit durchgehend mit dem ETR 610
(Astoro) fährt. Die Streckenregel für Zürich–München deckt jetzt `EC` *und*
`ECE` ab und steht davor.

**Der Fall, der die Regeln nötig macht:** der IC Stuttgart–Zürich über die
Gäubahn. Die Wagenreihung liefert dort **nichts** — nachgeprüft am IC 187 und
am IC 2383: die Antwort kommt, nur ohne Baureihe, weil kein DB-Fahrzeug in
RIS steht. Genau deshalb gibt es `FLEET_RULES`. Eingetragen ist der IC 2;
vorher fuhr dort der Stadler KISS, und wenn es wieder wechselt, ist es diese
eine Zeile.

Die Regel greift auch auf Teilstücken (`between: [/stuttgart/i,
/(zürich|singen|schaffhausen)/i]`), denn viele dieser Züge enden schon in
Singen — und sie greift *nicht* auf dem IC Stuttgart–Nürnberg, was der Test
mitprüft.

**2. Gelernte Baureihen (`api/lib/Fleet.php`).** Jede Baureihe, die die
Wagenreihung je geliefert hat, wird unter ihrer Zugnummer gemerkt. Beim nächsten
Mal — morgen, nächste Woche, oder für den vierten Abschnitt, für den das
Abfrage-Budget von drei Zügen je Verbindung nicht mehr reichte — steht sie ohne
eine einzige weitere Anfrage bereit. Derselbe Gedanke wie bei der
Pünktlichkeitsstatistik: die App wird mit der Nutzung besser.

Umläufe sind stabil, aber nicht in Stein gemeißelt. Deshalb zählt nur die
jüngste Beobachtung, sie verfällt nach 90 Tagen, und das Ergebnis wird als
*gelernt* gekennzeichnet.

**An jeder Verbindung steht, woher wir es wissen** — vier Wege, vier
Verlässlichkeiten:

| Grad | Bedeutung |
|---|---|
| `series` | nachgesehen: die Wagenreihung meldet die Baureihe |
| `learned` | erinnert: derselbe Zug fuhr zuletzt mit dieser Baureihe |
| `route` | geschlossen: auf dieser Strecke verkehrt nur dieses Fahrzeug |
| `sole` | geschlossen: diese Gattung verkehrt nur mit diesem Fahrzeug |

Nur `series` wird ohne Vorbehalt angezeigt; die übrigen drei sind als Schluss
gekennzeichnet und nennen im Tooltip den Grund.

### Baureihe: gelöst über bahn.expert — verloren und wiedergefunden

Der Dienst war eine Zeitlang stumm, und zwar auf die unangenehmste Art: Der
alte Pfad `/rpc/…` antwortet mit `HTTP 500 {"error":"Only HTML requests are
supported here"}`, mit Browser-User-Agent mit `404`. Der Provider fällt bei
jedem Fehler stillschweigend zurück — genau deshalb blieb unbemerkt, dass
**überhaupt keine Baureihe mehr angezeigt wurde**.

Gefunden wurde der neue Pfad, indem eine Zugdetailseite von bahn.expert im
Browser geöffnet und ihr Netzwerkverkehr gelesen wurde: dieselbe
superjson-Nutzlast, nur unter **`/api/trpc/`** statt `/rpc/`. Am Ende eine
Zeile in `config.php`.

Zwei Lehren, beide eingebaut:

- **`check.php` prüft die Quelle jetzt ausdrücklich mit.** Ein Provider, der
  leise degradiert, braucht eine laute Prüfung — sonst merkt es niemand.
- **Der Pfad wandert wieder.** bahn.expert ist ein privates Projekt. Wer die
  Angabe verlässlich braucht, wechselt auf **RIS::Transports** im DB API
  Marketplace — dieselben Daten unter Vertrag und mit Schlüssel.

Und er wanderte wieder (September 2026, `/api/trpc` → wieder `HTTP 500`).
Seitdem kommt die Wagenreihung direkt von bahn.de — siehe „Der direkte
DB-Weg: jetzt offen".

#### Die Abfragen laufen gleichzeitig

Die Wagenreihung braucht **eine Anfrage je Zug**. Sechs Trefferkarten mit je
zwei Zügen sind zwölf Round-Trips, und nacheinander abgearbeitet kostete das
gemessen:

| Suche Frankfurt–Hamburg, kalt | Dauer |
|---|---|
| ohne Wagenreihung | 8,2 s |
| mit, nacheinander, 3 Züge je *Verbindung* | 27,6 s |
| **mit, gleichzeitig, 12 Züge je *Suche*** | **5,3 s** |

Also: erst alle offenen Abfragen einsammeln, nach Zug entdoppeln (in sechs
Verbindungen fahren oft dieselben Züge), was im Cache liegt gleich bedienen,
den Rest per `curl_multi` parallel holen (`Http::getJsonAll`). Aus zwölf
Round-Trips wird einer — und die Abdeckung steigt dabei, weil der Deckel
jetzt je Suche gilt statt je Verbindung.

Dazu die Reihenfolge: **`Fleet` füllt vor der Wagenreihung**, nicht danach.
Was schon gelernt und keine zwei Wochen alt ist, wird gar nicht erst
abgefragt.

Wie die Abfrage selbst funktioniert:

Die Baureihe kommt jetzt aus der Wagenreihung — `ICE 4 (BR412)`, inklusive
Wagenzahl je Klasse. Bezogen über **bahn.expert**, das dieselben Daten
(Quelle `DB-risTransports`) über eine erreichbare Schnittstelle anbietet.

Zwei Fallstricke, falls du daran arbeitest:

- Der Parameter `input` muss **doppelt JSON-kodiert** sein: ein JSON-String,
  der das Array enthält. Sonst antwortet der Dienst mit
  `"[object Object]" is not valid JSON`.
- Die Antwort ist superjson: Element 0 ist die Wurzel, jeder Wert darin ein
  Index in dasselbe Array. `CoachSequence.php` navigiert gezielt statt das
  Format allgemein aufzulösen.

Es gilt weiterhin: nur deutscher Fernverkehr, nur am Reisetag.

**bahn.expert ist ein privat betriebenes Projekt, kein offizieller Dienst.**
Deshalb ist das Tool zurückhaltend: Ergebnisse werden 30 Minuten gecacht,
`max_lookups` deckelt die Abfragen je *Suche* auf zwölf, was einmal geholt
wurde merkt sich `Fleet.php` dauerhaft, und jeder Fehler führt
stillschweigend dazu, dass die Baureihe eben fehlt.

Wer das Tool dauerhaft betreibt, sollte auf den **DB API Marketplace**
wechseln: Das Modul `RIS::Transports` liefert dieselben Daten offiziell, unter
Vertrag und mit API-Key. Dann tauscht du in `CoachSequence.php` nur `url()`
und `parse()` aus.

### Der direkte DB-Weg: jetzt offen

Lange stand hier, der Wagenreihungs-Endpunkt der DB antworte auf jede von
außen gebaute Anfrage mit **HTTP 422**. Im September 2026 fiel bahn.expert
erneut aus — `/api/trpc` antwortete wie zuvor `/rpc` mit `HTTP 500 "Only
HTML requests are supported here"`, und die Baureihe fehlte wieder überall.
Beim zweiten Anlauf ging der direkte Weg:

```
GET https://www.bahn.de/web/api/reisebegleitung/wagenreihung/vehicle-sequence
    ?administrationId=80&category=ICE&date=2026-09-24
    &evaNumber=8000261&number=1211&time=2026-09-24T06:03:00.000Z
```

- **`time` in UTC mit Millisekunden**, `date` als Reisetag in Ortszeit.
- **Browser-TLS-Profil** wie die übrige DB-Anbindung, und zusätzlich der
  Kopf **`Accept-Language`** — ohne ihn kommt `403 OPS_BLOCKED`, mit ihm
  dieselbe Anfrage mit 200. Nachgemessen, beide Varianten nebeneinander.
- `time` darf **ein paar Minuten danebenliegen** (±8 min gingen) — für den
  ankommenden Zug am Umsteigebahnhof genügt deshalb seine Ankunftszeit.
- 8 von 9 Stichproben lieferten eine Reihung, der neunte `404` (für diesen
  Zug gerade keine).

Die Antwort ist reicher als die von bahn.expert: der Bahnsteig mit seinen
**Sektoren in Metern**, und jeder Wagen mit Nummer, Klasse, Sektor, Lage am
Bahnsteig und Bauart. Die Baureihe ergibt sich aus der Bauart
(`CoachSequence::seriesFromVehicles()`), in zwei Schreibweisen, beide
nachgesehen:

| Bauart | Baureihe |
|---|---|
| `I4010` Triebkopf | ICE 1 (401) |
| `I4080` … `I4088` | ICE 3neo (408) |
| `I4110` … `I4118` | ICE T (411) |
| `I0812`, `I1412`, `I9812` … | ICE 4 (412/812) — Wagen vorn, Baureihe hinten |
| `R89xx` mit Lok | ICE L |
| `B11`, `WR6` (Schweizer Wagen im EC) | keine |

bahn.expert bleibt als Quelle wählbar (`wagenreihung.source` in
`config.php`). Für Fahrten in der Zukunft gibt es weiterhin keine
Wagenreihung — dafür sind die „immer erkennbaren" Modelle (railjet,
Nightjet, WESTbahn, TGV) und die Gattungsbewertung da.

### Karte

Eine echte Slippy-Map mit Kartenhintergrund: ziehen zum Verschieben, scrollen
oder Pinch zum Zoomen, dazu Knöpfe für Zoom und „ganze Route zeigen". Sie ist
**immer sichtbar** — ohne Suche zeigt sie den Überblick, die Routen kommen
dazu, sobald Start und Ziel stehen. Alle gefundenen Routen liegen übereinander,
die ausgewählte ist hervorgehoben. Klick auf eine Linie wählt die Verbindung,
Klick auf eine Verbindung hebt die Linie hervor — beides synchron.

**Eine Auswahl zoomt auf ihre Route.** Der Ausschnitt über *allen* Treffern
taugt für den Überblick, aber nicht für die eine Verbindung, die man sich gerade
ansieht: Zürich–Wien und Zürich–Wien über München liegen darin fast
übereinander. Gezoomt wird aber nur, wenn es etwas bringt — passt die Route
schon vollständig ins Bild und füllt es zu mindestens einem Drittel, bleibt der
Ausschnitt stehen. Sonst spränge er bei jedem Klick in der Liste, auch wenn er
längst passt. Eine **neue Suche** setzt den Ausschnitt dagegen immer neu: sonst
zeigte die Karte nach Zürich–Wien weiter den Alpenraum, während die Liste
Berlin–Hamburg führt. Beim Blättern bleibt er, wo er ist.

**Zur Bedienung mit der Maus:** Der Pointer wird erst nach mehr als sechs Pixel
Bewegung eingefangen. Vorher bleibt ein Klick ein Klick, auch wenn die Maus
dabei leicht wackelt — sonst verschluckt `setPointerCapture` die Auswahl von
Routen und Zügen. Und das beim Drücken getroffene Element wird gemerkt, weil
`event.target` nach einem Capture auf den Viewport zeigt statt auf die Linie.

Gebaut ohne Leaflet: ein Kachel-Layer aus `<img>`-Elementen, darüber ein SVG mit
Routen, Halten und Zugpositionen. Das spart ein mitzulieferndes Paket und hält
die Kachelquelle an einer Stelle (`TILES` in `assets/js/map.js`).

**Zur Kachelquelle:** OpenStreetMap direkt, ohne Schlüssel.

Vorher stand hier CARTO. Deren Basemap-CDN verlangt inzwischen einen
API-Schlüssel — und verweigert die Auskunft nicht etwa mit einem Fehlercode,
sondern liefert weiterhin **HTTP 200 mit einem Bild, auf dem „API key
required" steht**. Für den Browser ist das eine gültige Kachel, `onerror`
schlägt nie an, und die Karte besteht aus lauter Fehlermeldungen, ohne dass die
App etwas davon merkt. Genau so sah es aus.

OSM braucht keinen Schlüssel. Die Nutzungsbedingungen verlangen die
Namensnennung — sie steht unten rechts im Bild und darf nicht entfernt werden —
und keine Massenabfragen; eine Handvoll Kacheln je Seitenaufruf erfüllt das.

**Die Karte ist schwarzweiß.** Der Hintergrund ist Hintergrund; Farbe gehört
den Routen, den Zügen und den Baustellen darüber. Die OSM-Standardkacheln sind
bunt — grüne Wälder, gelbe Straßen, blaue Flüsse —, und darüber gingen die
farbigen Linien unter.

**Dunkles Layout ohne zweite Quelle:** OSM hat keine dunklen Kacheln. Derselbe
Filter erledigt das mit — `invert(1)` dreht Hell und Dunkel um. Einen
`hue-rotate` braucht es nicht: nach dem Entsättigen ist nichts Farbiges mehr
da, das sich verdrehen könnte.

**Die Werte sind an der alten Quelle geeicht**, und das war nötig. Ein erster
Versuch mit bloßem Entsättigen sah schlechter aus als CARTO zuvor. Der Grund
ließ sich messen: über eine Stadt- und eine Landkachel gemittelt liegt CARTO
„positron" bei einer Helligkeit von 0,92 und „dark matter" bei **0,05** — der
erste Versuch landete im dunklen Layout bei **0,22**, einem flauen Mittelgrau
statt einer dunklen Karte.

Der Fehler steckte im `contrast` unter 1: das zieht alles zur Mitte und hellt
die dunklen Flächen auf. Richtig ist das Gegenteil — Kontrast leicht **über**
1, und die Helligkeit danach herunterskalieren:

| | Filter | gemessen |
|---|---|---|
| hell | `grayscale(1) contrast(0.5) brightness(1.42)` | 0,91 (Ziel 0,92) |
| dunkel | `grayscale(1) invert(1) contrast(1.2) brightness(0.34)` | 0,051 (Ziel 0,052) |

Gemessen wird immer an derselben Kachel (Zürich, Zoomstufe 13) — über Stadt-
und Landkachel gemittelt fällt der Wert niedriger aus, und dann vergleicht man
Äpfel mit Birnen.

Die Reihenfolge zählt: `brightness` steht **nach** `invert` und skaliert die
umgedrehten Werte nach unten. Davor hätte es die Karte aufgehellt.

Der Filter liegt nur auf den Kacheln; das SVG mit Routen und Zügen darüber
bleibt unangetastet.

Damit sieht ein fremder Server die IP-Adressen deiner Besucher — das ist der
Preis für den Hintergrund. Wer das nicht will, setzt `TILES.url` auf `null`;
dann rendert die Karte nur die Routen, ganz ohne externe Requests.

**Beschriftungen überlappen nicht.** Für jeden Halt werden acht Positionen rund
um den Punkt durchprobiert; passt keine kollisionsfrei ins Bild, bleibt der Name
weg — ein fehlender Name ist besser als zwei übereinandergedruckte. Ab Zoomstufe
9 werden auch Zwischenhalte beschriftet.

### Wo fährt der Zug gerade?

Über der Karte lässt sich **„Züge live anzeigen"** einschalten. Dann erscheinen
alle Züge, die im sichtbaren Ausschnitt gerade unterwegs sind, als pulsierende
Punkte.

**Ein Klick auf einen Zug öffnet seinen kompletten Lauf** unter der Karte: alle
Halte mit Plan- und Ist-Zeit, Gleisen und der Verspätung je Halt. Oben steht die
größte Verspätung als Kennzeichen — grün „pünktlich", gelb ab einer Minute, rot
ab fünf oder bei Ausfall. Weicht die Ist-Zeit ab, wird die Planzeit
durchgestrichen und die tatsächliche daneben gezeigt. Störungsmeldungen des
Betreibers stehen darüber.

Die Positionen kommen von HAFAS (`JourneyGeoPos`) und werden aus Fahrplan und
Echtzeitlage **berechnet**, nicht per GPS geortet. Sie sind eine gute Näherung,
keine Ortung auf den Meter. Die Verspätungen dagegen sind echte Echtzeitdaten.
Beim Verschieben und Zoomen wird nachgeladen (gedrosselt, 30 Sekunden Cache);
ein zu großer Ausschnitt liefert bewusst nichts.

### Auslastung, knappe Umstiege, Bestpreis, Historie

**Auslastung** meldet die DB je Abschnitt und Klasse (Stufe 1 gering bis
4 ausgebucht). Sie steht als Kennzeichen an der Verbindung und je Abschnitt im
Detail — die Klasse richtet sich nach deiner Auswahl.

**Knappe Umstiege** rechnet das Tool selbst aus der Lücke zwischen Ankunft und
Weiterfahrt, Fußwege eingerechnet. Unter 5 Minuten gilt als riskant (rot), unter
10 als knapp (gelb). Bei drei bis fünf Umstiegen ist das meist der Punkt, an dem
eine Verbindung in der Praxis platzt.

**Mindestumsteigezeit** lässt sich im Suchformular einstellen (Standard 5 min,
„egal" bis 30 min). Der Wert wird an **beide** Quellen durchgereicht — HAFAS
kennt `minChgTime`, die DB `minUmstiegszeit`. Das ist deutlich besser als
nachträglich zu filtern: Die Quellen suchen dann passende Verbindungen, statt
dass knappe einfach wegfallen. Nachgemessen für Josef-Wirth-Weg → Garching
Forschungszentrum: ohne Vorgabe Umstiege von 3–4 Minuten, mit `5` dann 8–9, mit
`12` dann 13–14. Ein Nachfilter bleibt als Sicherheitsnetz; bliebe dadurch
nichts übrig, werden lieber die knappen Verbindungen gezeigt als eine leere
Liste.

**Fußwege** werden jetzt zuverlässig erkannt. Vorher tauchten sie als
„Unbekannt" auf, weil die DB im Nahverkehr das Feld `typ` schlicht weglässt und
der Abschnitt dadurch als Fahrzeug ohne Gattung durchging. Erkannt wird
stattdessen am Verkehrsmittel selbst: kein Gattungskürzel, keine Liniennummer,
Name „Fußweg". Bei der ÖBB gilt umgekehrt, dass alles außer einer Fahrt (`JNY`)
ein Weg zu Fuß ist — das deckt auch seltenere Abschnittstypen ab.

Unterschieden wird dabei, ob der Halt wechselt: „Umstieg am selben Halt" ist
etwas anderes als „Zu Fuß: Studentenstadt → Situlistraße". Letzteres bekommt
eine eigene Kennzeichnung, weil so ein Fußweg oft der Grund ist, warum eine
Verbindung schneller oder entspannter ist.

**Was nicht geht:** Von sich aus schlägt keine der beiden Quellen einen Fußweg
zu einer *anderen* Haltestelle vor, um dort besser umzusteigen. Für Josef-Wirth-Weg
→ Garching liefern beide ausschließlich die Route über Studentenstadt, nie über
Situlistraße. Wer so eine Variante will, kann sie im Nerd-Modus über
**„Über eine bestimmte Stadt"** erzwingen — dort Situlistraße eintragen.

**Bestpreis über den Tag:** Unter den Hinweisen stehen sechs Zeitfenster mit dem
jeweils günstigsten Angebot. Ein Klick übernimmt die Uhrzeit und sucht neu.
Gemessen für Zürich–München: 33,99 € abends gegen 41,99 € nachts.

**Pünktlichkeitshistorie** kombiniert drei Quellen, damit auch beim ersten
Aufruf eines Zuges eine ehrliche Zahl auf dem Bildschirm steht.

Sie steht jetzt als **Abzeichen auf der Verbindungskarte** („63 % pünktlich"),
nicht mehr nur im aufgeklappten Detailbereich — also genau dort, wo man beim
Vergleich zweier Verbindungen hinsieht. Gezeigt wird der **schwächste**
Abschnitt, nicht der Durchschnitt: eine Verbindung ist so pünktlich wie ihr
unpünktlichster Zug, und bei einem Umstieg entscheidet ohnehin der. Ob die
Zahl aus eigenen Messungen stammt oder noch aus der Baseline, sagt der
Tooltip.

Die drei Quellen:

1. **Eigene Messungen.** Bei jedem Zuglauf mit Echtzeitdaten wird die
   beobachtete Verspätung festgehalten — höchstens ein Wert je Zug und Tag,
   gleitendes Fenster über 60 Beobachtungen bzw. 120 Tage. Zusätzlich wird ein
   **7-Tage-Fenster** ausgewiesen: „so ist es aktuell", nicht nur ein
   Langzeitschnitt.
2. **Baseline aus den Betreiber-Jahresstatistiken** (DB Konzernbericht, ÖBB
   Geschäftsbericht, SBB Jahresbericht). Damit gibt es auch beim allerersten
   Aufruf einen belastbaren Startwert — als solcher gekennzeichnet und im
   Blend über einen Bayes-Prior (Gewicht 5) mit den eigenen Messungen
   verrechnet. Schon ~10 eigene Werte übersteuern die Baseline sichtbar.
3. **Schweizer Ist-Daten V2** (Open-Data-Plattform Mobilität Schweiz). Für
   Züge, die durch die Schweiz fahren, kann die tatsächliche Verspätung aus
   der täglich veröffentlichten CSV berechnet werden — siehe Cron-Skript unten.

Jede Zahl trägt eine **Quellenkennung**: „aus eigenen Messungen", „Näherung aus
Betreiber-Jahresstatistik (noch keine eigenen Messungen)" oder „eigene Messungen
ergänzt um Betreiber-Statistik". Als pünktlich gilt unter 6 Minuten, wie im
Bahnverkehr üblich.

Gezeigt wird sie an zwei Stellen: an der Verbindung (dort der **schwächste**
Abschnitt, denn eine Verbindung ist so pünktlich wie ihr unpünktlichster Zug)
und im **Zug-Panel an der Karte**. Die zweite Stelle liegt nahe, weil genau
dieser Aufruf selbst einen Messwert beisteuert — die Statistik wächst mit dem
Hinsehen.

Gespeichert wird als JSON je Zug unter `api/cache/punctuality/` — kein
Datenbankserver nötig.

#### Schweizer Ist-Daten importieren (optional, Cron)

`bin/import_ch_istdaten.php` lädt die täglichen Ist-Daten-CSVs von
`data.opentransportdata.swiss/dataset/ist-daten-v2`, ermittelt je Zugfahrt die
Ankunftsverspätung am Endhalt und schreibt das Ergebnis in denselben
JSON-Store, den auch die Live-Sammlung nutzt. Für die App ist danach kein
Unterschied sichtbar — Ist-Daten-Samples verhalten sich wie eigene Messungen.

```bash
# Trockenlauf für gestern mit 200 000 Zeilen (ca. 10 s, ~5 000 Fahrten):
php bin/import_ch_istdaten.php --days=1 --limit=200000 --verbose

# Cron-Empfehlung: täglich morgens die letzten zwei Tage nachziehen
0 4 * * * cd /pfad/zu/train-maxxing && php bin/import_ch_istdaten.php --days=2 >> logs/import.log 2>&1
```

Optionen:

| Flag | Bedeutung |
|---|---|
| `--days=N` | Anzahl Tage rückwärts (Standard 7, max 60) |
| `--date=YYYY-MM-DD` | Nur diesen einen Tag holen |
| `--limit=N` | Nur die ersten N CSV-Zeilen (für schnelle Tests) |
| `--force` | Bereits importierte Tage erneut ziehen |
| `--verbose` | Fortschrittsmeldungen alle 100 000 Zeilen |

**Was der Importer nicht kann:** Deutschland und Österreich veröffentlichen
keine vergleichbaren Ist-Daten-Feeds. Für DE- und AT-Züge bleibt es bei
Baseline + eigener Sammlung. Die CH-Daten helfen aber auch dort mit, wenn die
Verbindung durch die Schweiz führt (Zürich–München erfasst den ICE zwischen
Zürich und Basel).

**Ressourcenbedarf:** Eine volle Tages-CSV ist rund 300–500 MB, die
Verarbeitung streamt zeilenweise und braucht wenige zehn MB RAM. Pro Tag
werden ~30 000–50 000 CH-Zugfahrten aggregiert; die Punctuality-JSONs bleiben
insgesamt im niedrigen zweistelligen MB-Bereich.

**Idempotenz:** Der Importer merkt sich erledigte Tage in
`api/cache/punctuality/.imports/YYYY-MM-DD.done`; `Punctuality::record()` selbst
lässt zusätzlich nur einen Wert je Zug und Tag zu. Ein doppelter Cron-Aufruf
richtet also keinen Schaden an.

#### Deutsche Ist-Daten aus bahnvorhersage.de/open-data (manuell)

Die Datenbasis existiert und deckt den Zeitraum ab September 2021 fast
vollständig ab — sie wird aber **nicht automatisch abgerufen**, weil:

- Die Downloads laufen über die [Mobilithek](https://mobilithek.info/) und
  erfordern einen Account (Anmeldung erforderlich, Freischaltung des Datensatzes
  auf Anfrage).
- Jährliche `.tar`-Archive mit täglichen **Parquet**-Dateien; ein Jahr sind
  mehrere Gigabyte.
- Parquet in reinem PHP zu parsen erfordert eine Zusatzabhängigkeit, die den
  Charakter „einfach hochladen und läuft" bricht.

Wer die Baseline für deutsche Züge mit echten Daten verfeinern möchte, kann die
Parquet-Dateien mit einem einmaligen Python-Skript in unser JSON-Format
umrechnen. Das relevante Schema (siehe
[Bahn-Vorhersage-Doku](https://bahnvorhersage.de/open-data/parsed-train-delays)):

- `is_final == true` — nur den letzten Prognosewert je Halt nehmen
- `is_arrival == true` — Ankunftsseite verwenden (analog zum CH-Importer)
- `delay` (Sekunden) — auf Minuten umrechnen, dann in `api/cache/punctuality/de/`
  ablegen mit dem Key `<category>_<trainNumber>.json` und dem Schema aus
  `public/api/lib/Punctuality.php`

Solange kein solcher Import läuft, greift für DE-Züge die Baseline aus dem
DB-Konzernbericht plus die eigenen Live-Messungen — die App zeigt korrekt an,
welche Quelle die Zahl gerade trägt.

### Münchner Nahverkehr über die MVG-API

Für München gibt es **zwei Lücken**, die die MVG-Web-API schließt:

1. **Reine U-Bahn-Halte** (Odeonsplatz, Sendlinger Tor …) haben keine
   EVA-Nummer und tauchen in der HAFAS-Suche oft gar nicht auf. Die Ortssuche
   fragt deshalb zusätzlich `https://www.mvg.de/api/bgw-pt/v3/locations`
   und mischt Treffer mit MVG-Präfix `mvg:` in die Liste. HAFAS-Treffer
   mit identischem Namen absorbieren die MVG-Ergänzung; nur reine
   MVG-Halte bleiben eigenständig. Sie tragen noch das Flag
   `noJourneys=true` (HAFAS akzeptiert nur EVA-Nummern), suchbar sind sie
   inzwischen trotzdem — über die MVG selbst, siehe
   [Stadtfahrten](#stadtfahrten-und-zubringer-in-münchen).

2. **Aktuelle Störungsmeldungen** (`?action=disruptions`) — Baustellen,
   Ausfälle, Umleitungen für U-Bahn, Tram, Bus, S-Bahn. Das Frontend blendet
   sie als kollabierbaren Ticker unter den Suchergebnissen ein, wenn welche
   vorliegen. Zwei Minuten Cache serverseitig, alle 120 Sekunden erneutes
   Nachladen im Browser.

Die MVG-API läuft ohne Auth und ist ausdrücklich für die MVG-Web-App gedacht;
wir identifizieren uns per `User-Agent` (konfigurierbar in `config.php`).
Ausschalten geht per `providers.mvg.enabled = false` — dann verschwindet der
Ticker und die Ortssuche fällt auf HAFAS-only zurück.

3. **Ersatzwege bei einem Ausfall** (`?action=localroute`). Hier stand lange,
   die MVG-API habe keine Verbindungssuche — gesucht worden war nach
   `/trips`. Sie heisst `/routes`, und sie ist in München unersetzlich:
   **die Fahrplanquelle der ÖBB kennt die Münchner U-Bahn nicht.** Odeonsplatz
   führt dort nur Produktklasse 2, Marienplatz nur die S-Bahn. Fällt die
   S-Bahn-Stammstrecke aus, konnte die App deshalb nur weitere S-Bahnen
   anbieten — die ebenfalls ausfallen — und nie die U-Bahn, mit der man
   tatsächlich zum Hauptbahnhof kommt.

   Weil die MVG keine EVA-Nummern versteht, wird zwischen **Punkten** gesucht:
   beide Enden des ausgefallenen Abschnitts werden über `/stations/nearby`
   auf die nächste MVG-Haltestelle abgebildet. Dabei gilt **Bahnhalt vor
   Bushalt**: nach Entfernung allein gewann am Ostbahnhof die Bushaltestelle
   „Friedenstraße" (106 m, von der MVG obendrein als `BAHN,BUS` geführt) vor
   dem Bahnhof (134 m), und die MVG plante acht Minuten Fussweg zur S-Bahn und
   drei zurück zur Tram ein — jede Ersatzverbindung verpasste dadurch den
   Anschlusszug. S- und U-Bahn gehen jetzt vor, blosses `BAHN` danach, alles
   andere zuletzt.

Die MVG-API läuft ohne Auth und ist ausdrücklich für die MVG-Web-App gedacht;
wir identifizieren uns per `User-Agent` (konfigurierbar in `config.php`).
Ausschalten geht per `providers.mvg.enabled = false` — dann verschwindet der
Ticker, die Ortssuche fällt auf HAFAS-only zurück, und bei Ausfällen gibt es
nur noch die Ersatzverbindungen über HAFAS.

### Ersatz, wenn ein Zug ausfällt

Vorher stand an einem ausgefallenen Zug „Dieser Zug fällt aus" — und dann
nichts. Im Betrieb hiess das: S-Bahn-Stammstrecke gesperrt, die App wusste es,
und man stand ohne Vorschlag da. Drei Lücken lagen hintereinander:

1. **Nachgeladen wurde nur bei knappen Umstiegen.** Die Ersatzsuche in der
   Trefferliste startete ausschliesslich bei 1–4 Minuten Umsteigezeit — ein
   ausgefallener Zug ist aber nicht knapp, er ist weg.
2. **Ersatz, der selbst ausfällt.** Die Suche schloss nur die *eine*
   Zugnummer aus. Bei einer gesperrten Strecke fällt die nächste S-Bahn auf
   derselben Linie genauso aus — und kam als „Alternative" zurück. Jetzt
   fliegt jede Verbindung mit einem ausgefallenen Abschnitt heraus.
3. **Die U-Bahn fehlte in der Quelle** — siehe oben.

Jetzt kommen an jedem ausgefallenen Zug zwei Sorten Ersatz, zusammengeführt
und nach Ankunft sortiert:

| Art | Quelle | was sie leistet |
|---|---|---|
| **Überbrückung** | MVG | vom Einstieg des ausgefallenen Zuges bis zu seinem Ausstieg — und danach die Reise **wie geplant**. Der gebuchte ICE ab München Hbf fährt ja trotzdem. Nur angeboten, wenn sie den nächsten Zug mit mindestens drei Minuten Puffer erreicht; höchstens zwei, weil sie sich oft nur um zwei Minuten Abfahrt unterscheiden |
| **Neue Verbindung** | HAFAS | vom Einstieg bis zum Ziel, überall, und auch dort, wo die Brücke den Anschluss nicht mehr schafft |

Jede ist mit einem Klick übernehmbar, und die Übernahme ist rückgängig zu
machen. Dasselbe gilt in der Live-Verfolgung: meldet sie „Zug fällt aus",
stehen darunter dieselben beiden Sorten Ersatz.

Nachgestellt mit einer als ausgefallen markierten S2 München Ost → Hbf vor
einem ICE nach Frankfurt: zwei Brücken, die den ICE 724 noch erreichen
(Ankunft 12:02 wie geplant), dazu Neuverbindungen mit +7 und +38 Minuten.
Übernommen enthält die Verbindung keinen ausgefallenen Abschnitt mehr.

**Wie ein Zug in den Vorschlägen heisst**, bestimmt jetzt dieselbe Regel wie
überall sonst (`trainLabel`). Die Beschriftung des Servers schrieb „DB S5" —
HAFAS führt die Münchner S-Bahn unter der Gattung „DB" — und für MVG-Linien
wegen `trainNumber ?? line` mit leerem String nur „U" statt „U5".

### Echtzeit-Routing

HAFAS sucht normalerweise mit dem **Fahrplan** und rechnet die Echtzeitlage
erst hinterher dazu. Eine Verbindung, deren Anschluss eine Verspätung längst
gekappt hat, stand deshalb unauffällig in der Liste — HAFAS markierte sie
zwar (`isNotRdbl`), gelesen wurde das Feld aber nie.

Zwei Dinge sind jetzt anders:

- **Suchen für die nächsten Stunden laufen mit `rtMode: REALTIME`.** Dann
  routet HAFAS mit der Echtzeitlage: nicht mehr erreichbare Verbindungen
  fallen weg, ausgefallene Züge werden umfahren. Nachgemessen am ÖBB-Server:
  der Schalter gehört in `cfg`, nicht in `req` (dort „Parser error"), und
  von den Werten anderer HAFAS-Server gehen `SERVER_DEFAULT`, `REALTIME` und
  `FULL`, `HYBRID` nicht. München Ost → Frankfurt gegen 22:30: im
  Fahrplanmodus acht Treffer, darunter eine S6 mit geplatztem Anschluss an
  den ICE 618 — mit Echtzeit sechs, ohne sie. Gilt für Abfahrten von einer
  Stunde zurück bis drei Stunden voraus (`isNearNow()`); weiter reicht keine
  Prognose. Findet die Echtzeitsuche gar nichts, zeigt der Fahrplan
  wenigstens, was fahren sollte.
- **Die Ersatzsuche (`nextconnection`) läuft immer mit Echtzeit** — sie
  sucht den Weg jetzt, um einen Ausfall herum.

Dazu: **Ein Zug, der am eigenen Halt nicht hält, fällt für diese Reise aus.**
Gezählt wurde nur `isCncl` (der ganze Zug fällt aus). Bei einer gesperrten
Stammstrecke fährt die S-Bahn aber — sie endet nur vorzeitig, und am
Marienplatz steht `aCncl`/`dCncl` am Halt. Das zählt jetzt mit, und was
HAFAS als nicht erreichbar meldet, trägt in der Liste das Abzeichen „laut
Echtzeit nicht erreichbar".

### Stadtfahrten und Zubringer in München

Die Ortssuche fand „Odeonsplatz" schon lange — die Suche lehnte ihn dann ab:
„Halt ohne Fahrplan". Die Fahrplanquelle der ÖBB kennt die Münchner U-Bahn
nicht. Jetzt übernimmt die MVG, in zwei Fällen (`lib/CityTrips.php`):

| Fall | Beispiel | wer sucht |
|---|---|---|
| **Stadtfahrt** — beide Enden im MVG-Netz | Odeonsplatz → Karlsplatz | die MVG; sind beide Enden zugleich HAFAS-Bahnhöfe (Hbf → Ostbahnhof), auch HAFAS, und die Treffer werden zusammengeführt |
| **Zubringer** — ein Ende ist ein reiner MVG-Halt, das andere woanders | Odeonsplatz → Frankfurt | HAFAS ab bzw. bis München Hbf, die MVG das Stück in der Stadt |

Beim Zubringer wird **rückwärts** gerechnet: je Fernverbindung fragt die App
die MVG „wann muss ich am Odeonsplatz los, um am Hauptbahnhof sechs Minuten
vor dem ICE zu sein?" (`routingDateTimeIsArrival=true`). Alle diese Fragen
laufen gleichzeitig (`Mvg::routesMany`), eine Suche kostet so rund eine
zusätzliche Sekunde. Auf der ersten Seite verschiebt sich die Uhrzeit der
Fernsuche um die Fahrt in der Stadt — wer um 8:00 am Odeonsplatz losfährt,
erreicht keinen ICE um 8:01. Die sechs Minuten Umstieg sind Absicht: vom
U-Bahnsteig unter dem Hauptbahnhof zu Gleis 11–26 sind es zwei Rolltreppen
und die Querhalle.

Was die Verbindungen mitbringen:

- **Kein Preis, sondern die Tarifzone** („MVV · Zone M"). Die MVG nennt die
  Zonen, einen Betrag nicht, und schätzen wäre hier Raten. Beim Zubringer
  steht unter dem Preis des Fernzugs „+ MVV Zone M" — im Flexpreis der DB
  ist das City-Ticket enthalten, mit Deutschlandticket fährt man ohnehin.
- **Deutschlandticket**: gilt im ganzen MVV und steht deshalb an jedem
  MVG-Abschnitt.
- **Echtzeit und Streckenverlauf** aus der MVG-Antwort — die Karte zeigt die
  U-Bahn auf ihrer Strecke, nicht als Luftlinie.
- **Gestörte Aufzüge und Rolltreppen** an Ein- und Ausstieg (die MVG meldet
  sie je Halt), wie bei den Meldungen der DB.

**Blättern** geht auch ohne Blätterkontext der MVG: der Zeitpunkt selbst wird
zum Kontext (`mvg|2026-09-24|09:15`), und die Verbindungen tragen eine aus
Abfahrt, Ankunft und Linien gebildete Kennung — die `uniqueId` der MVG
bezeichnet eine Antwort, nicht eine Fahrt, und beim Weiterblättern stünde
dieselbe U-Bahn sonst zweimal da.

Ob ein HAFAS-Bahnhof im MVG-Netz liegt, entscheidet erst ein grober Rahmen
(Ammersee bis Erding, Freising bis Wolfratshausen), dann `nearestStation()`.
Das Ergebnis wird eine Woche gemerkt — Haltestellen ziehen nicht um, und
Zürich–Wien soll keine MVG-Abfrage kosten.

### Abfahrtstafel

Der zweite Tab oben. Wer am Bahnsteig steht oder gestrandet ist, fragt
nicht „wie komme ich nach X", sondern „was fährt hier als Nächstes".

- **Quelle ist HAFAS** (`StationBoard`, 0,2 s) für jeden Bahnhof in CH, DE
  und AT — Abfahrten und Ankünfte, mit Ist-Zeit, Gleis, Gleiswechsel und
  Ausfall.
- **In München kommt die MVG dazu**, und zwar mit **allen** ihren Halten des
  Bahnhofs: am Hauptbahnhof sind das vier (S-Bahn, „Hauptbahnhof (U, Tram)",
  „Süd", „Nord"). HAFAS kennt die U-Bahn nicht und hat für die Münchner
  S-Bahn oft nur den Fahrplan; die MVG hat für beides Echtzeit. Eine S-Bahn,
  die beide nennen, steht einmal da — mit der `jid` von HAFAS und der
  Ist-Zeit der MVG. Die Linie wird dafür ohne Leerzeichen verglichen: „RB 16"
  (MVG) und „RB16" (HAFAS) sind derselbe Zug.
- **Ein Tipp auf eine Zeile** zeigt den Zuglauf ab hier (bei Ankünften: bis
  hier). Die S-Bahn hält in „München Hbf (tief)" — gesucht wurde „München
  Hbf"; auch das wird erkannt.
- Filter nach Fernverkehr, Regional, S-Bahn, U-Bahn, Tram und Bus — nur die,
  die an diesem Bahnhof auch vorkommen.
- Frischt sich jede Minute auf, solange die Tafel sichtbar ist und „jetzt"
  zeigt.

Die Ansicht steht in der Adresse (`#abfahrten`) und übersteht so ein
Neuladen.

### Tarife, Ausstattung und Aufzüge von der DB

Die DB liefert mehr, als die Trefferliste bisher zeigte. Nachgemessen, was in
den Antworten steckt:

**Alle Tarife einer Verbindung** (`?action=offers`). Die Suche nennt nur den
günstigsten Preis — meist einen Super Sparpreis mit Zugbindung, ohne dass das
dasteht. Mit dem `ctxRecon` der Verbindung liefert
`/web/api/angebote/recon` alle Angebote: Super Sparpreis, Sparpreis und
Flexpreis in beiden Klassen, jeweils mit Zugbindung, Storno und City-Ticket,
dazu den Preis einer Sitzplatzreservierung. München → Frankfurt, 2. Klasse:
79,99 / 88,99 / 118,80 €, Reservierung 5,50 €. Die Antwort ist über 100 kB
groß, deshalb lädt die App sie erst, wenn man „Tarife und Bedingungen"
aufklappt.

Ein Fallstrick: **der Rabatt-Hinweis bedeutet zweierlei.** Mit BahnCard im
Profil steht er an den eigenen Preisen. Ohne BahnCard hängt er an
zusätzlichen Angeboten, die erst mit einer Probe-BahnCard gelten — in
derselben Liste stehen dann 79,99 € und „59,99 € (20 € Ersparnis durch
BahnCard)". Diese stehen deshalb getrennt darunter: „Mit BahnCard ab …".

**Zugausstattung** aus den `zugattribute`: Bordrestaurant bzw. -bistro, WLAN,
Steckdosen, Fahrradmitnahme, Rollstuhlplatz, Komfort-Check-in. Von rund
vierzig Schlüsseln bleiben die, die die Reise betreffen; was sie
**verhindern** kann, steht farbig vorn — Reservierungspflicht, nur 2. Klasse,
„DB-Fahrscheine gelten nicht", Deutschlandticket gilt nicht, Ersatzverkehr.
Werbung wie „Intercity 2: Info unter www.bahn.de/ic2" fliegt raus.

**Gestörte Aufzüge am Ein- und Ausstieg.** Die DB hängt an jeden Abschnitt
alle Meldungen seiner Halte (`himMeldungen`), darunter solche wie „Hamburg
Hbf: Aufgrund einer Aufzugserneuerung Gleis 13/14 steht dieser … nicht zur
Verfügung". Behalten wird, was die Barrierefreiheit betrifft **und** mit dem
Namen des eigenen Ein- oder Ausstiegs beginnt — der Aufzug in Celle, an dem
man sitzen bleibt, gehört nicht dazu. Die Meldung steht unter dem Halt, auf
zwei Zeilen gekürzt, ein Tipp zeigt sie ganz.

Nicht verwendet: `samePlatform` an den Abschnitten. Es stand in Stichproben
auch an Abschnitten ohne Umstieg davor; ohne klare Bedeutung lieber nicht.

### Benachrichtigungen unterwegs

Die Live-Verfolgung hat einen Knopf **„Benachrichtigen"**. Danach meldet sie
von selbst:

- **Ausfall** und **geplatzten oder knappen Anschluss** — dieselbe Warnung wie
  im Panel,
- **Verspätung ab fünf Minuten**, danach nur in Fünferstufen. Jede Minute zu
  melden wäre Lärm: bei einer langsam wachsenden Verspätung klingelte das
  Telefon im Halbminutentakt.
- **Gleiswechsel** an einem noch bevorstehenden Einstieg.

Was beim Einschalten schon so ist, wird nicht gemeldet — das steht im Panel,
das man gerade ansieht. Die Verspätung kommt dabei aus dem **nachgeladenen
Zuglauf**, nicht aus den Ist-Zeiten der Suche; die sind nach einer halben
Stunde Fahrt veraltet.

Gezeigt wird die Benachrichtigung über den Service Worker (Chrome auf
Android kennt nur diesen Weg), ein Tipp darauf bringt zur App zurück.

**Grenzen, ehrlich:** Das ist kein Push-Dienst mit eigenem Server. Die Seite
muss offen sein — im Hintergrund-Tab frischt sie weiter auf, so gut der
Browser lässt (Chrome drosselt Zeitgeber dort auf einmal pro Minute), aber
ein Telefon mit dunklem Bildschirm friert die Seite irgendwann ganz ein. Auf
dem iPhone gibt es Benachrichtigungen nur, wenn die App über „Zum
Home-Bildschirm" installiert ist (iOS 16.4+). Und nur über HTTPS.

### Favoriten

Ein **Stern** neben „Von" und „Nach" merkt den gewählten Bahnhof, ebenso der
Stern in der Vorschlagsliste. Favoriten stehen dann als Knöpfe unter den
Feldern: ein Tipp setzt den Ort ins zuletzt benutzte Feld, ohne angefasstes
Feld erst in „Von", dann in „Nach" — zwei Tipps ergeben eine Strecke. Tippt
man in ein leeres Feld, stehen Favoriten und die zuletzt benutzten Orte
schon in der Vorschlagsliste. Dieselben Favoriten gelten in der
Abfahrtstafel. Alles liegt im Browser, nichts geht an den Server.

### Offline

Die App ist installierbar (`manifest.webmanifest`) und läuft ohne Netz
weiter (`sw.js`):

- Die Seite lädt, die verfolgte Verbindung steht da (sie liegt ohnehin im
  `localStorage`), und alles, was zuletzt vom eigenen Backend kam —
  Suchergebnisse, Zugläufe, Abfahrtstafeln, Bahnhofspläne, Tarife —, kommt
  aus dem Zwischenspeicher. Oben steht dann „Offline — gezeigt wird der
  zuletzt geladene Stand", an der Suche „offline, gespeicherter Stand", und
  die Live-Verfolgung nennt die Uhrzeit des letzten echten Standes statt
  einer frischen.
- **Netz zuerst, immer**, auch für HTML, CSS und JS. Ein Service Worker, der
  aus dem Cache ausliefert, würde jedes Update verschlucken — genau das
  Problem, das `.htaccess` mit `no-cache` gelöst hat. Der Cache springt nur
  ein, wenn das Netz nicht antwortet.
- **Kartenkacheln werden nicht gespeichert.** Sie kommen als `<img>` ohne
  CORS, also als „opake" Antworten, und die rechnet Chrome mit je rund 7 MB
  auf das Speicherkontingent an. Die Route liegt als SVG über der Karte und
  ist auch ohne Kacheln zu sehen.
- Höchstens 150 Backend-Antworten, die ältesten fliegen raus. Positionen der
  Live-Züge werden nie gespeichert: eine Stunde alt sind sie nicht alt,
  sondern falsch.

Nachgeprüft, indem der Server abgeschaltet wurde: Seite, Suche und Hinweis
kamen aus dem Zwischenspeicher. Ändert sich `sw.js`, die Konstante
`VERSION` darin hochzählen, dann räumt der Service Worker die alten Caches
weg.

### Datum und Uhrzeit auf dem Telefon

iOS und Android zeichnen `input[type="date"]` und `input[type="time"]` als
**Systemsteuerelement** und geben ihm die Breite seines *Inhalts*.
`width: 100%`, `max-width` und `min-width: 0` prallen daran ab: das Element ist
schlicht nicht bereit, schmaler zu werden als der Text darin, und schiebt sich
über das Nachbarfeld und über den Rand des Panels.

Es hilft nur `appearance: none`. Was dabei verloren geht, wird einzeln
zurückgeholt — genau das war der Einwand beim ersten Versuch:

| verloren | zurückgeholt mit |
|---|---|
| Wert saß oben links statt mittig | `display: flex` + `align-items: center` |
| Kalender- bzw. Uhrsymbol fehlte | eingebettetes SVG als `background-image` |
| Feld wirkte leer | `::-webkit-date-and-time-value { text-align: left }` |

Ein Detail, das eine Weile gekostet hat: `background-position: right 0.6rem
center` sind **drei** Werte, und die Dreiwert-Schreibweise ist ungültig — der
Browser wirft die Zeile ersatzlos weg und setzt das Symbol nach oben links.
Gültig sind ein, zwei oder vier Werte, hier also `right 0.6rem top 50%`.

**Die Regel gilt für alle Breiten, nicht nur fürs Telefon.** Zuerst stand sie
in der Telefon-Abfrage — und prompt kam dieselbe Meldung fürs iPad: dort sind
die Spalten 161 px breit, ein natives Datumsfeld will 165, und schon
überlappen Datum, Uhrzeit und Klasse. Dieselbe Ursache, nur eine
Bildschirmgrösse weiter. Auf dem Schreibtisch schadet sie nichts, dort ist
ohnehin Platz.

Zwei Dinge kamen fürs Tablet dazu: **16 px Schriftgrösse bis 1024 px** (sonst
zoomt iOS beim Antippen eines Feldes hinein — die Regel stand ebenfalls nur in
der Telefon-Abfrage), und eine etwas größere Grundbreite je Spalte
(`minmax(170px, 1fr)` statt 150). Lieber eine Spalte weniger als vier zu enge.

Nachgemessen bei 768 px (drei Spalten à 219 px), 375 px und 320 px, jeweils
auch mit auf 22 px hochgesetzter Grundschrift: kein Überlauf, die Felder
schrumpfen mit. Unter 360 px bekommt das Datum eine eigene Zeile — 360 und
nicht 380, weil die verbreiteten Telefongrössen bei 375 und 390 px liegen und
dort beide Felder bequem nebeneinander passen.

### Rückfahrt

Jede Verbindung hat neben „Live verfolgen" einen Knopf **„Rückfahrt"**: Start
und Ziel getauscht, gesucht ab einer Stunde nach der Ankunft, auf fünf
Minuten aufgerundet. Getauscht werden die Orte der Suche, nicht die der
Verbindung — die endet vielleicht in „München Hbf (tief)" oder an einem
MVG-Halt. Unter der Suche steht, womit gerechnet wurde; die Uhrzeit oben ist
ein Tipp entfernt.

### Teilen

Der Knopf **„Suche teilen"** legt die komplette Suche in der Adresszeile ab —
Orte, Datum, Zeit, Abos, Verkehrsmittel, Modus. Auf dem Telefon öffnet sich das
native Teilen-Menü, sonst landet der Link in der Zwischenablage. Wer ihn öffnet,
bekommt die Suche automatisch ausgeführt.

Ist unter der Karte gerade ein **Zuglauf** offen, hängt er als `&zug=<jid>` mit
dran und geht beim Empfänger von selbst wieder auf — parallel zur Suche, denn
er braucht nur seine Kennung. Die hält allerdings nicht ewig: HAFAS baut die
`jid` je Antwort neu auf, verlässlich ist sie für den Reisetag. Länger will man
so einen Link ohnehin nicht verschicken.

### Verkehrsmittel filtern

Unter **Verkehrsmittel** lassen sich Gruppen abwählen — am häufigsten wohl Bus
und Schienenersatzverkehr. Der Filter greift schon bei der Suche, nicht erst in
der Anzeige, und gilt für Fahrplan und Preisabfrage gleichermaßen.

Die zugrunde liegenden HAFAS-Produktklassen sind nicht geraten, sondern
nachgemessen (`api/lib/Products.php`):

| Bit | Wert | Gattungen |
|---|---|---|
| 0 | 1 | ICE, RJ, RJX |
| 1 | 2 | Schienenersatzverkehr |
| 2 | 4 | EC, IC, IR |
| 3 | 8 | NJ, EN, FLX |
| 4 | 16 | RE, RB, R, REX |
| 5 | 32 | S-Bahn |
| 6 | 64 | Bus |
| 8 | 256 | U-Bahn |
| 9 | 512 | Tram |
| 12 | 4096 | WESTbahn |

### Über eine bestimmte Stadt

Im Nerd-Modus lässt sich ein Zwischenhalt erzwingen — etwa um Zürich–Wien über
München statt über den Arlberg zu führen. Verifiziert: ohne Vorgabe liefert die
Suche die Arlberg-Route, mit Vorgabe „München" ausschließlich Verbindungen über
München.

### Ortssuche

Zwei Quellen werden zusammengeführt, weil keine allein reicht: Die ÖBB-Suche ist
auf Österreich geeicht — „Marienplatz" liefert dort Graz, Viehofen und
Hafnerbach, aber kein München. Die DB-Suche kennt den deutschen Nahverkehr bis
zur einzelnen U-Bahn-Station, ist dafür bei kleinen Halten in AT und CH dünner.

Sortiert wird in drei Stufen:

1. **Namensrelevanz** — exakt, als ganzes Wort, enthalten. Das dominiert alles
   andere. Ohne diese Stufe gewinnt „Schendlingen (Bregenz)" gegen „Sendlinger
   Tor, München", weil HAFAS unscharf sucht und Schendlingen ein
   Fernverkehrshalt ist. Treffer ohne jeden Namensbezug fliegen raus.
2. **Bedeutung des Ortes** — gewichtet nach Verkehrsangebot. U-Bahn und S-Bahn
   zählen hoch, weil es sie nur in Großstädten gibt; das ist der beste
   verfügbare Ersatz für „Größe der Stadt", die keine der APIs mitliefert.
3. **Rang der Quelle**, bei Gleichstand mit Vorrang für die DB.

### Wenn die ÖBB die Station nicht kennt

Die Fahrplansuche läuft normalerweise über die ÖBB. Die kennt deutsche
EVA-Nummern problemlos (geprüft mit `8004135`, München Marienplatz), aber
**keine lokalen Kennungen**. Nahverkehrshalte wie „Sendlinger Tor, München"
tragen Nummern wie `625176` — dort antwortet HAFAS mit
`location missing or invalid`.

Deshalb gibt es einen Fallback: Findet die ÖBB nichts, übernimmt die DB auch den
Fahrplan. Geprüft für Sendlinger Tor → München Hbf: ÖBB null Treffer, DB fünf
Verbindungen (U7, U2). Alle Halte tragen Koordinaten, die Karte kann sie also
zeichnen — nur der genaue Streckenverlauf fehlt, weil die DB keine Polylines
liefert. Das steht dann als Hinweis über den Ergebnissen.

Damit lassen sich auch reine U-Bahn- und Tram-Halte als Start oder Ziel
verwenden.

### Ticketshops

An jeder Verbindung stehen die Shops der berührten Länder, das Startland zuerst:
Zürich–München ergibt SBB und DB, Wien–München ÖBB und DB.

**Der ÖBB-Link** kommt fertig aus der Fahrplanantwort und ist zuverlässig
vorbelegt.

**Der DB-Link** war mit bloßen Ortsnamen kaputt — die Buchungsstrecke meldete
„Keine Verbindungen gefunden" und ignorierte das Datum. Er enthält jetzt die
vollständigen Location-IDs samt Koordinaten (`soid`, `zoid`, `soei`, `zoei`).
Abschließend verifizieren ließ sich das nicht: bahn.de sperrte den Testbrowser
nach wenigen Aufrufen mit Fehler 751 aus. Deshalb ist er wie der SBB-Link mit
`*` markiert — bitte einmal gegenprüfen.

**Der SBB-Link** führt auf die Fahrplansuche. Der alte Deeplink
`fahrplan.xhtml` liefert durchgehend HTTP 400, und ob die Nachfolgeseite die
Parameter übernimmt, lässt sich serverseitig nicht feststellen.

### Abos und Preisschätzung

Die Reise wird in Länderanteile zerlegt — über die **Zwischenhalte**, die jeweils
einen Ländercode tragen. Eine Fahrt Wien–München ergibt so korrekt ~317 km AT plus
~145 km DE statt einer pauschalen Halbierung. Darauf werden die Abo-Regeln
angewendet:

| Abo | Wirkung | Besonderheit |
|---|---|---|
| Halbtax | 50 % auf den CH-Anteil | |
| GA | CH-Anteil frei | |
| GA Night | CH-Anteil frei | nur 19–5 Uhr, 2. Klasse |
| BahnCard 25 / 50 / 100 | 25 % / 50 % / ganz auf den DE-Anteil | Echtpreis von der DB |
| Deutschlandticket | DE-Anteil frei | **nur Nahverkehr**, nicht ICE/IC/EC |
| VORTEILScard | 45 % auf den AT-Anteil | |
| KlimaTicket | AT-Anteil frei | |

*seven25* ist kein eigener Eintrag — es ist dasselbe Produkt wie GA Night, nur
der Tarif für unter 25-Jährige.

**Zeitfenster gelten je Teilstück, nicht je Verbindung.** Maßgeblich ist die
Uhrzeit, zu der der Zug das jeweilige Teilstück tatsächlich befährt — dafür
werden die Zeiten der Zwischenhalte ausgewertet (fehlen sie, werden sie über die
Distanz interpoliert). Der ECE München–Zürich ab 17:03 ist damit korrekt vom GA
Night gedeckt, weil er den Schweizer Abschnitt erst nach 19:00 erreicht. Liegt
ein Teilstück auf der Fenstergrenze, wirkt der Rabatt anteilig: 18:45–19:15
ergibt den halben Nachlass.

Zwei Sonderfälle sind hinterlegt: Die BahnCard 50 gibt auf Sparpreise nur 25 %.
Und beim Deutschlandticket verlässt sich das Tool nicht auf die Gattung allein —
die DB markiert in ihrer Antwort selbst, auf welchen Teilstrecken es gilt
(inklusive Hinweisen wie „Singen(Hohentwiel) – Stuttgart Hbf"). Diese Angabe hat
Vorrang vor der eigenen Heuristik.

**Doppelrabatte sind ausgeschlossen:** Enthält der Echtpreis bereits eine
BahnCard, geht sie nicht noch einmal in die Hochrechnung ein — nur die Abos, die
die DB nicht kennt.

#### Die Preiskurve — degressiv und nachgemessen

Ein Bahntarif wird mit der Entfernung billiger. Gemessen an echten
DB-Angeboten: **31 ct/km bei 40 km, 11 ct/km bei 808 km.** Ein fester
Kilometersatz kann das nicht abbilden — er passt entweder kurze oder lange
Strecken, nie beide. Genau das stand hier lange: 0,24 €/km für Deutschland,
und Freiburg–Berlin kam damit auf 118 € statt 90 €.

Jetzt gilt je Land eine Kurve der Form **`preis = a · km^b`**, kalibriert an
**85 echten Angeboten zu 19 Relationen zwischen 37 und 808 km** (gemessen am
4. September 2026, zwei Wochen Vorlauf):

| Land | Kurve | mittlerer Fehler vorher | jetzt |
|---|---|---|---|
| Deutschland | `1,0508 · km^0,6766` | 22 % | **18 %** |
| Schweiz | `0,4964 · km^0,8746` | 28 % | **8 %** |
| Österreich | `0,9500 · km^0,6900` | — | nicht gemessen, siehe unten |

Über alle Relationen: der mittlere Fehler der Untergrenze fällt von **23 % auf
17 %**, und das gezeigte Band trifft in **16 von 19** Fällen einen tatsächlich
angebotenen Preis.

**Warum der deutsche Rest nicht wegzurechnen ist:** der Sparpreis hängt an der
Auslastung, nicht nur an der Entfernung. Stuttgart–Karlsruhe gab es am Messtag
für 6,99 € *und* für 36,20 € — dieselbe Strecke, derselbe Tag. In der Schweiz
ist der Preis dagegen eine reine Funktion der Entfernung, und entsprechend
genau trifft die Kurve dort.

**Die Kurve gilt je Land auf die Gesamtstrecke, nicht je Teilstück.** `a · km^b`
ist nicht additiv: eine Fahrt von 600 km in zwanzig Halte-Abschnitte zerlegt und
je Abschnitt bepreist ergäbe ein Vielfaches des richtigen Preises — der Sinn der
Degression ist ja gerade, dass der einundfünfzigste Kilometer weniger kostet als
der erste. Die Abos wirken deshalb als **Anteil**: je Land wird ausgerechnet,
welcher Teil der Strecke wie stark rabattiert ist, und der Landespreis
entsprechend gekürzt.

**Im Nahverkehr gibt es keine Spanne.** Nachgemessen an sieben deutschen
Nahverkehrsrelationen: jede lieferte fünf Angebote, und alle fünf hatten
denselben Betrag. Weder Sparpreis noch Flexpreis-Aufschlag — was am Automaten
steht, ist der Preis. Eine Spanne von 45 % vorzugaukeln wäre dort falsch.
Was dafür streut, ist die Region: München–Augsburg kostet 21,30 €,
Hannover–Braunschweig bei gleicher Entfernung 15,00 €. Das sind Verbund-, keine
Entfernungstarife. Eine eigene Nahverkehrskurve wurde geprüft und wieder
verworfen — sie war nur drei Prozentpunkte besser (22 statt 25 % Fehler) und
hätte eine Genauigkeit vorgetäuscht, die es nicht gibt.

**Österreich ist nicht gemessen** und das ist keine Nachlässigkeit: Die DB
verkauft innerhalb Österreichs nicht — jede Anfrage kommt ohne Preis zurück —,
und das HAFAS der ÖBB liefert zwar ein `trfRes`-Feld, aber ohne Betrag
(nachgeprüft: `{"statusCode":"OK"}`, sonst nichts). Die hinterlegten Werte sind
die deutsche Kurve, etwas günstiger gestellt. Wer sie besser kennt:
`RATE_CURVE` in `api/lib/Fares.php`, `a` skaliert den Preis, `b` die Degression.

#### Die Entfernung kommt aus der Polylinie

Vorher: Luftlinie von Halt zu Halt, mal 1,25 als Bogenzuschlag. Das ist
messbar zu ungenau — München–Berlin kam damit auf **753 statt 623 km**, und der
geschätzte Preis war entsprechend zu hoch.

HAFAS liefert aber den **tatsächlichen Streckenverlauf** mit (`getPolyline`).
Nachgemessen an sechs Relationen gegen die amtliche Tarifentfernung:

| Verfahren | Spanne | Mittel |
|---|---|---|
| Polylinie | 0,94 – 1,01 × | 0,975 |
| Haltekette × 1,25 | 1,00 – 1,12 × | 1,065 |

Die Polylinie ist also **gut doppelt so genau**; ein Korrekturfaktor von 1,025
zentriert sie. Die Haltekette bleibt die Rückfallebene, wo keine Polylinie da
ist (DB-Fahrpläne liefern keine).

Zweistufig ist es, weil beide Quellen etwas beisteuern: die **Halte** tragen
Ländercode und Uhrzeit — ohne sie keine Aufteilung auf Länder und keine
Zeitfenster fürs GA Night —, die **Polylinie** die Länge. Also werden die
Luftlinien zwischen den Halten so skaliert, dass ihre Summe der Polylinie
entspricht.

Ungenau bleibt es bei Abschnitten, die eine Grenze **ohne Zwischenhalt** queren —
die werden hälftig geteilt, obwohl die Grenze selten in der Mitte liegt. Bei
Fahrten aus der Schweiz fällt das kaum ins Gewicht, weil Basel, Buchs SG und
Chiasso fast immer Halte sind.

Geschätzte Preise sind **nie verbindlich**. Maßgeblich ist der Ticketshop, und der
Buchungslink hängt an jeder Verbindung.

---

## Aufbau

```
public/
├── index.html                    Oberfläche
├── .htaccess                     Browser-Cache: immer revalidieren
├── sw.js                         Service Worker: offline mit letztem Stand
├── manifest.webmanifest          Installierbar als App
├── check.php                     Selbsttest für den Webspace
├── assets/
│   ├── css/style.css             Alle Farben als CSS-Variablen
│   └── js/
│       ├── app.js                Zustand, Formular, Moduswechsel, Auswahl
│       ├── api.js                Aufrufe ans eigene Backend
│       ├── scoring.js            Bewertungsmodelle
│       ├── render.js             Ergebnisdarstellung
│       ├── map.js                SVG-Routenkarte inkl. Label-Platzierung
│       ├── live.js               Live-Verfolgung, Anschlusswache, Benachrichtigungen
│       ├── board.js              Abfahrtstafel
│       ├── autocomplete.js       Vorschlagsliste mit Favoriten
│       ├── favorites.js          Lieblingsorte und Verlauf
│       └── data/trains.js        Gattungen, Fahrzeugmodelle, Komfortwerte
└── api/
    ├── index.php                 Router, führt Fahrplan und Preise zusammen
    ├── config.php                Einzige Datei, die du anfassen musst
    └── lib/
        ├── Http.php              cURL-Wrapper inkl. Browser-TLS-Profil
        ├── Cache.php             Dateicache, degradiert still
        ├── Fares.php             Abo- und Preislogik
        ├── Products.php          Verkehrsmittel-Gruppen und Bitmasken
        ├── Locations.php         Ortssuche aus beiden Quellen
        ├── Punctuality.php       Selbst gesammelte Pünktlichkeitsstatistik
        ├── Fleet.php             Gelernte Baureihen je Zugnummer
        ├── Health.php            Wie es den fremden Diensten zuletzt ging
        ├── Shops.php             Buchungs-Deeplinks je Land
        ├── CityTrips.php         Stadtfahrten und Zubringer in München
        └── Providers/
            ├── OebbHafas.php     Fahrplan, Zuggattungen, Ländercodes, Geometrie, Tafel
            ├── DbVendo.php       Echtpreise, alle Tarife, Auslastung, Ausstattung
            ├── Mvg.php           Münchner Nahverkehr: Orte, Verbindungen, Abfahrten
            ├── SwissOpenData.php Schweizer Prognosen (transport.opendata.ch)
            └── CoachSequence.php Wagenreihung und Baureihe (bahn.de)
```

### API-Endpunkte

Alles per GET auf `api/`:

| Aufruf | Zweck |
|---|---|
| `?action=health` | Welche Quellen sind erreichbar? |
| `?action=catalogue` | Abo-Liste fürs Frontend |
| `?action=locations&q=Bern` | Stationssuche |
| `?action=journeys&from=…&to=…&date=…&time=…` | Verbindungen inklusive Preis (`&scroll=…` blättert; der Kontext trägt seine Richtung selbst — `scroll` aus der Antwort führt zu späteren, `scrollBack` zu früheren Abfahrten) |
| `?action=livetrains&bbox=süd,west,nord,ost` | Züge, die dort gerade fahren |
| `?action=traindetails&jid=…` | Zuglauf mit Halten und Verspätung — statt `jid` auch `db=<journeyId>` (Zuglauf bei der DB) oder `mvgFrom`, `mvgTo`, `line`, `dep`, `arr` (Echtzeit eines MVG-Abschnitts) oder `chFrom`, `chTo`, `cat`, `dir`, `dep`, `arr` (Schweizer Prognose über opendata.ch) |
| `?action=bestprices&from=…&to=…&date=…` | Günstigste Zeitfenster am Tag |
| `?action=nextconnection&from=…&to=…&date=…&time=…` | Nächster Anschluss nach einem knappen Umstieg oder Ausfall (mit Echtzeit) |
| `?action=localroute&fromLat=…&fromLon=…&toLat=…&toLon=…&date=…&time=…` | Ersatzweg im MVV über die MVG |
| `?action=offers&ctx=…&class=…&discounts=…` | Alle DB-Tarife einer Verbindung (`ctx` = `dbRecon` der Verbindung) |
| `?action=departures&station=…&type=dep\|arr&date=…&time=…` | Abfahrts- bzw. Ankunftstafel; `lat`/`lon` helfen, in München die MVG-Halte zu finden |
| `?action=sequence&eva=…&cat=…&num=…&time=…` | Wagenreihung eines Zuges an einem Bahnhof: Sektoren, Wagen, Klassen |
| `POST ?action=share` | Verfolgte Fahrt zum Teilen ablegen (JSON `{"journey": …}`), liefert `id` |
| `?action=shared&id=…` | Geteilte Fahrt abholen |
| `?action=fxrate` | EZB-Tageskurse, für den Gegenwert in Franken |
| `?action=platforms&lat=…&lon=…&from=…&to=…` | Bahnsteige, Treppen, Rolltreppen und Aufzüge eines Bahnhofs aus OpenStreetMap (`from`/`to` = die beiden Gleise des Umstiegs) |
| `?action=works` | Bauarbeiten im Netz, mit Abschnitt und Zeitraum |
| `?action=disruptions` | Aktive Störungsmeldungen der MVG München |

`journeys` versteht zusätzlich `discounts` (kommagetrennt), `products`
(kommagetrennt, leer = alle), `class` (1/2), `results`, `arrival=1`, `via`
(EVA-Nummern, kommagetrennt), `minchange` (Mindestumsteigezeit in Minuten,
1–60) und `fromLat`/`fromLon`/`toLat`/`toLon` (damit werden Stadtfahrten in
München erkannt).

---

## Tests

```bash
node bin/test_routes.mjs       # Streckenerkennung
node bin/test_units.mjs        # Gattungen, Beschriftung, Fahrzeuge, Ausfälle, Benachrichtigungen
php bin/test_providers.php     # Übersetzung der HAFAS-, DB- und MVG-Antworten
```

Alle drei brauchen nichts ausser Node bzw. PHP — kein Paket, kein Netz,
keine Datenbank. Sie laufen in unter einer Sekunde — und deshalb bei jedem
Push und Pull Request auf GitHub (`.github/workflows/tests.yml`, dazu
`php -l` und `node --check` über alle Dateien). Ändert ein Dienst sein
Format oder fehlt irgendwo ein Import, steht es damit am Commit, nicht erst
im Zug.

**`test_providers.php` prüft gegen echte, gekürzte Antworten** der Dienste
(`bin/fixtures/`, aufgezeichnet 2026-09): ob eine MVG-U-Bahn „U5" heißt und
nicht „U", ob ein Halt ohne Halt als Ausfall ankommt, ob der Super Sparpreis
nicht als Flexpreis dasteht und die BahnCard-Rabattpreise nicht als eigene
Tarife. Genau diese Stellen brechen, wenn ein Dienst sein Format ändert — und
sie brechen nicht laut, sondern als falscher Text. Ändert sich ein Format,
eine neue Antwort aufzeichnen, kürzen und die Fixture ersetzen.

**Warum genau diese Funktionen:** Sie entscheiden, was in der Trefferliste
steht, und sie hängen an Daten von fünf fremden Diensten, die ihre Formate
ohne Ankündigung ändern. Jeder Fall in `test_units.mjs` stand einmal falsch
in der App:

| Fall | Fehler dahinter |
|---|---|
| `typeOf` bei DPN/DRB | Betreiberkürzel statt Gattung → „Unbekannte Gattung" |
| `modelOf` beim ECE Zürich–München | Regelreihenfolge verdreht → falsches Fahrzeug |
| `trainLabel` im Nahverkehr | Zugnummer statt Linie → „S 20318" |
| `findCancellation` | Ausfall gar nicht bemerkt, keine Alternativen |
| `trainPosition` | `sameTrain` und `snapToLine` benutzt, nie importiert |

Der letzte ist der Grund, warum dort auch `LiveTracker` vorkommt, obwohl der
keine reine Funktion ist: **ein fehlender Import fällt beim Laden des Moduls
nicht auf**, sondern erst beim Aufruf — und diese Zeile lief nur, wenn man
tatsächlich im Zug sass. `node --check` sieht so etwas nie.

Und er ist **zweimal** passiert: erst `sameTrain`, dann `snapToLine`. Der Test
für den ersten lief am zweiten vorbei, weil `trainPosition()` zwei Zweige hat —
gemeldete Position und Hochrechnung — und der Test nur den ersten erreichte.
Deshalb gibt es jetzt zusätzlich eine **statische Prüfung**: was ein
Nachbarmodul exportiert und in einer Datei als nacktes Wort vorkommt, muss dort
auch importiert sein. Damit ist der Fehlertyp zu, ohne dass jeder Zweig einen
eigenen Test braucht.

Beide Tests sind gegen die echten Fehler gegengeprüft: dreht man die
Regelreihenfolge zurück oder entfernt den Import wieder, schlagen sie fehl.
Ein Test, der den Fehler nicht fängt, ist wertlos.

## Wenn etwas nicht mehr geht

`check.php` beantwortet zwei verschiedene Fragen, und die zweite ist die
wichtigere.

**Antwortet der Dienst jetzt?** Ein Aufruf je Quelle, live.

**Wie lief es in den letzten 24 Stunden?** Das ist der eigentliche Punkt.
Jeder Provider fällt bei jedem Fehler stillschweigend zurück — richtig so,
eine kaputte Wagenreihung darf die Suche nicht mitreißen —, aber es hat
einen Preis: bahn.expert hat seine Schnittstelle verschoben, und es ist
**wochenlang niemandem aufgefallen**. Die Baureihe fehlte einfach.

`Health.php` zählt deshalb jeden Aufruf nach draußen mit, nach Dienst und
Stunde, und `check.php` zeigt es:

```
Verlauf: wagenreihung    11 Aufrufe, davon 9 fehlgeschlagen (82 %)
                         - zuletzt: HTTP 500
```

Ab einem Viertel Fehlschlägen steht dort eine Warnung, ab der Hälfte ein
Fehler. Aus „seit Wochen kaputt" wird „in zehn Sekunden sichtbar".

Der Haken sitzt in `Http::request()` und ordnet den Dienst über den **Host**
der URL zu. Das ist Absicht: an den Aufrufstellen zu haken hätte genau die
Provider verpasst, um die es geht — Overpass baut sich seinen HTTP-Client
selbst, und der nächste Provider tut es wieder.

### Die API liefert immer JSON — auch wenn sie stirbt

Eine einzige Zeile, die PHP direkt ausgibt, steht **mitten** in der Antwort,
und `json_decode()` im Browser scheitert an einer Datei, die inhaltlich völlig
in Ordnung wäre. Genau das ist schon passiert: eine Deprecation-Warnung von
`curl_close()` machte auf PHP 8.5 jeden einzelnen Aufruf unbrauchbar. Deshalb
steht am Anfang von `api/index.php`:

- `display_errors = 0` und `log_errors = 1` — gemeldet wird weiterhin alles,
  nur eben ins Fehlerlog statt in die Antwort.
- `set_time_limit(120)`. Das Upstream-Timeout liegt bei 25 Sekunden, und
  mehrere Handler fragen zwei Quellen **nacheinander** (Fahrplan bei der ÖBB,
  Preise bei der DB). Die verbreitete Voreinstellung `max_execution_time=30`
  riss dem Skript mitten im zweiten Aufruf den Boden weg. Nicht `0`: ein
  hängender Socket blockierte damit dauerhaft einen Worker.
- Ein `register_shutdown_function` als **Notausgang**. Das `try/catch` um den
  Router fängt Exceptions, aber kein überschrittenes Zeitlimit, keinen
  erschöpften Speicher und keinen Parse-Fehler. Steht bei Programmende ein
  Fatal im Fehlerspeicher, ist die Antwort garantiert unfertig — sie wird
  verworfen und durch ein gültiges `{"ok":false,…}` mit HTTP 500 ersetzt.
  Ein Flag „schon geantwortet?" braucht es nicht: `ok()` und `fail()` beenden
  das Skript, ein Fatal danach kann es also nicht geben.

## Cache vorwärmen (optional, Cron)

```bash
php bin/warm_cache.php https://deine-domain.tld/
```

Zwei Antworten sind kalt sehr langsam und danach sehr lange gültig — ein
schlechtes Verhältnis, wenn es immer dieselbe Person trifft:

| | kalt | gültig |
|---|---|---|
| Baustellen | ~28 s | 1 Stunde |
| Bahnhofsplan | 10–40 s | 7 Tage |

Nachts vorgewärmt kostet beides nichts mehr, und es ist der freundlichere
Umgang mit Overpass: eine ruhige Anfrage um vier statt einer im
Berufsverkehr. Als Cron:

```
17 4 * * *  php /pfad/zu/bin/warm_cache.php https://deine-domain.tld/ >/dev/null 2>&1
```

Das Skript ruft die **eigene API über HTTP** auf, nicht die Bibliotheken
direkt — die Zwischenspeicherung sitzt in den Handlern von `index.php`, und
ein direkter Aufruf würde andere Cache-Schlüssel schreiben als die App später
liest. Die Bahnhofsliste steht oben in der Datei; wer andere Knoten braucht,
ändert sie.

## Anpassen

**Preisrichtwerte** (falls die Schätzungen systematisch danebenliegen):
`api/lib/Fares.php`, Konstanten `RATE_PER_KM`, `BASE_FEE`, `SAVER_FACTOR`.

**Weiteres Abo hinzufügen:** in `Fares.php` einen Eintrag in `DISCOUNTS` ergänzen
(Land, Faktor, Label). Das Frontend zieht die Liste automatisch über
`?action=catalogue` — im UI musst du nichts anfassen. Soll das Abo auch an die DB
durchgereicht werden, zusätzlich in `DbVendo::DISCOUNT_MAP` eintragen.

**Zugkomfort:** `assets/js/data/trains.js` — `TRAIN_TYPES` für die Gattungen,
`TRAIN_MODELS` für die Fahrzeuge. Ein neues Modell braucht `label`, die
`categories`, unter denen es fährt, und — falls die Wagenreihung es melden soll —
die `series` (Baureihennummern). `sole: true` bedeutet: diese Gattung fährt
praktisch nur dieses Fahrzeug, die Zuordnung ist dann auch ohne Wagenreihung
eindeutig.

**Fahrzeug aus der Strecke:** ebenfalls `data/trains.js`, `FLEET_RULES`. Eine
Zeile je Strecke, auf der der Umlauf feststeht — `model` (eine ID aus
`TRAIN_MODELS`), `categories`, optional `between` (zwei Muster, die beide unter
den Halten vorkommen müssen) und `note` als Begründung fürs Tooltip. Das ist die
Stelle, an der sich Streckenwissen am billigsten einbringen lässt.

**Preisrichtwerte, genauer:** `api/lib/Fares.php`, `RATE_CURVE`. Je Land
`a` und `b` der Kurve `preis = a · km^b` sowie die Faktoren `spar`, `flex` und
der Mindestpreis `min`. Die deutschen und schweizerischen Werte sind an echten
Angeboten kalibriert, die österreichischen nicht — dort ist am meisten zu
gewinnen.

**Verkehrsmittel-Gruppen:** `api/lib/Products.php`. Dort stehen Bitmaske und
DB-Gattungsnamen nebeneinander; das Frontend zieht die Liste automatisch.

**Cache-Zeiten:** `api/config.php` → `cache_ttl`. Standard: Orte 1 Tag,
Verbindungen 5 Minuten.

**Rate-Limit:** ebenfalls in `config.php`. Gerechnet wird in **Punkten**, nicht
in Anfragen: eine Verbindungssuche kostet 5, ein Zuglauf 2, die gecachte
Abo-Liste gar nichts (`RATE_COST` in `api/index.php`). Standard 150 Punkte pro
Minute und IP.

Vorher zählte jede Anfrage gleich, und damit sperrte sich die App selbst aus:
die Live-Verfolgung holt alle 30 Sekunden zwei Zugläufe, jede Kartenbewegung
löst eine Positionsabfrage aus — das Kontingent war weg, bevor eine einzige
Suche gelaufen war, und die nächste Suche bekam `429`. Nachgemessen: vierzig
Aufrufe der Abo-Liste kosten jetzt nichts, einunddreissig Suchen greifen.

### DB-Enum-Werte verifizieren

Sollte die DB die Bezeichner für Ermäßigungen ändern, brechen die Echtpreise mit
Abo. So kommst du an die aktuellen Werte: auf `bahn.de` eine Suche mit deinem Abo
starten, in den Entwicklertools den Netzwerk-Tab öffnen, den POST auf
`angebote/fahrplan` suchen und im Request-Body unter `reisende[].ermaessigungen`
nachsehen. Diese Werte in `DbVendo::DISCOUNT_MAP` eintragen.

---

## Rechtliches und Fairness

Das Tool nutzt dieselben Schnittstellen, die auch die Websites und Apps der
Betreiber verwenden. Es sind keine offiziell dokumentierten öffentlichen APIs.
Für den privaten Gebrauch ist das üblich und verbreitet — aber:

- Die Schnittstellen können sich **jederzeit ohne Ankündigung ändern**. Wenn etwas
  nicht mehr geht, ist meistens das die Ursache.
- Cache und Rate-Limit sind absichtlich konservativ eingestellt. Dreh sie nicht
  hoch, es sei denn, du weißt was du tust.
- Für kommerziellen Einsatz brauchst du echte Verträge mit den Betreibern.
- Gebucht wird immer im offiziellen Shop. Das Tool verkauft nichts und
  speichert keine personenbezogenen Daten — Einstellungen liegen ausschließlich
  im localStorage deines Browsers.

---

## Bekannte Grenzen

- **Schweizer und österreichische Abos sind immer geschätzt.** Die DB kennt nur
  BahnCards. Für Halbtax, GA oder KlimaTicket ist der verbindliche Preis der im
  SBB- bzw. ÖBB-Shop.
- **Keine Echtpreise auf reinen CH/AT-Relationen** wie Zürich–Wien, weil die DB
  sie nicht vertreibt.
- **Baureihen** nur über die Wagenreihung (deutscher Fernverkehr, Reisetag),
  über gelernte Beobachtungen oder über Strecken- und Gattungsregeln. Für
  SBB- und ÖBB-Fahrzeuge liefert die Wagenreihung nichts — dort helfen nur
  die Regeln in `FLEET_RULES`.
- **Preise in Österreich sind ungeprüft.** Keine der beiden Quellen liefert dort
  einen Betrag; die Kurve ist von der deutschen abgeleitet.
- **Nachtzüge** sind im Preisvergleich benachteiligt, weil die gesparte
  Hotelnacht nicht eingerechnet wird.
- **Grenzabschnitte ohne Zwischenhalt** werden hälftig aufgeteilt. Bei Fahrten
  aus der Schweiz fällt das kaum ins Gewicht, weil Basel, Buchs SG und Chiasso
  fast immer Halte sind.
- **Reservierungen und Zuschläge** sind in den Schätzungen nicht enthalten.
- **Der Umstiegsplan zeigt keinen Laufweg.** Er sagt, wo die beiden Bahnsteige
  liegen — nicht, wie man dazwischen läuft. Der Weg wurde einmal aus OSM
  gerechnet und war zu oft falsch; siehe oben.
- **Der TLS-Trick kann jederzeit brechen.** Ändert Akamai die Erkennung, kommt
  wieder `OPS_BLOCKED` und das Tool fällt auf Schätzpreise zurück.
- **Benachrichtigungen brauchen eine offene Seite.** Kein Push-Server; ein
  Telefon mit dunklem Bildschirm friert die Seite irgendwann ein. Auf dem
  iPhone nur als installierte App.
- **Stadtfahrten gibt es nur in München.** Echtzeit für MVG-Abschnitte kommt
  aus der Abfahrtstafel an Ein- und Ausstieg, nicht aus einem Zuglauf — die
  Halte dazwischen sind um die gemeldete Verspätung verschoben, nicht
  einzeln gemessen.
- **Die Umstiegskarte zeigt keinen Laufweg**, nur Bahnsteige, Treppen,
  Rolltreppen und Aufzüge je Ebene. Exakte Wege wie in der SBB-App gibt es
  nur mit freigeschaltetem Zugang zur Journey-Maps-API und nur in der
  Schweiz.
- **Schweizer Echtzeit** kommt nur als Rückfallebene über
  transport.opendata.ch, und der Dienst drosselt (`HTTP 429`). Die Zuordnung
  zum Zug geht über Gattung, Minute und Richtung, nicht über die Zugnummer.
- **Wagenreihung** nur für deutschen Fernverkehr am Reisetag.
- **Die MVG-Abfahrten reichen nur ab jetzt** bis einen Tag voraus; für eine
  Tafel nächste Woche gibt es in München nur HAFAS, also keine U-Bahn.
