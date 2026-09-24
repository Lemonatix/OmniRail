/**
 * Die reinen Funktionen prüfen — Gattungen, Beschriftung, Fahrzeuge.
 *
 *   node bin/test_units.mjs
 *
 * WOZU: Diese Funktionen entscheiden, was in der Trefferliste steht, und sie
 * hängen an Daten von fünf fremden Diensten, die ihre Formate ohne Ankündigung
 * ändern. Jeder Fall hier stand einmal falsch in der App:
 *
 *   "DPN RB37 · Unbekannte Gattung"   — Betreiberkürzel statt Gattung
 *   "Giruno" auf dem ECE Zürich–München — Regelreihenfolge verdreht
 *   "Gleis Gleis 24"                   — OSM schreibt das Wort mit hinein
 *   Gleis 10 in Pasing verschwunden    — Bussteignummer als Bahnhofsnummer
 *   ReferenceError beim Mitfahren      — sameTrain benutzt, nie importiert
 *
 * Der letzte ist der Grund, warum hier auch LiveTracker vorkommt, obwohl der
 * keine reine Funktion ist: ein fehlender Import fällt beim Laden des Moduls
 * NICHT auf, sondern erst beim Aufruf. Nur ein Aufruf fängt ihn.
 */

import { typeOf, modelOf, FLEET_RULES, TRAIN_MODELS } from '../public/assets/js/data/trains.js';
import { trainLabel, sameTrain } from '../public/assets/js/map.js';

// LiveTracker legt im Konstruktor einen Listener an, und api.js baut beim
// Laden eine URL aus `document.baseURI`. Mehr Browser braucht es für diese
// Tests nicht.
globalThis.document ??= {
  baseURI: 'http://localhost/',
  addEventListener() {},
  createElement: () => ({ append() {}, classList: { add() {} }, setAttribute() {} }),
};
const { LiveTracker } = await import('../public/assets/js/live.js');

let fehlgeschlagen = 0;
let gesamt = 0;

function pruefe(name, ist, soll) {
  gesamt++;
  const ok = JSON.stringify(ist) === JSON.stringify(soll);
  if (ok) {
    console.log('  ok   ' + name);
  } else {
    fehlgeschlagen++;
    console.log('  FAIL ' + name);
    console.log('         erwartet: ' + JSON.stringify(soll));
    console.log('         bekommen: ' + JSON.stringify(ist));
  }
}

const zug = (o) => ({ mode: 'train', ...o });

// ---------------------------------------------------------------------
console.log('\ntypeOf — Gattung aus uneinheitlichen Kürzeln');

pruefe('ICE bleibt ICE', typeOf(zug({ category: 'ICE' })).label, 'ICE');
pruefe('DPN + Linie RB37 → RB', typeOf(zug({ category: 'DPN', line: 'RB37' })).label, 'RB');
pruefe('DRB + Linie RE99 → RE', typeOf(zug({ category: 'DRB', line: 'RE99' })).label, 'RE');
pruefe('DPN ohne Linie → RB (Betreiberkürzel)', typeOf(zug({ category: 'DPN' })).label, 'RB');
pruefe('DPF → Fernverkehr', typeOf(zug({ category: 'DPF' })).label, 'IC');
pruefe('Produktname "Nahreisezug" → RB',
  typeOf(zug({ category: '', categoryName: 'Nahreisezug' })).label, 'RB');
pruefe('S8 bleibt S-Bahn', typeOf(zug({ category: 'S', line: 'S8' })).label, 'S');
pruefe('STR → Tram', typeOf(zug({ category: 'STR' })).label, 'Tram');
pruefe('wirklich unbekannt bleibt unbekannt',
  typeOf(zug({ category: 'XYZ' })).long, 'Unbekannte Gattung');
pruefe('Fußweg ist kein Zug', typeOf({ mode: 'walk' }).label, '?');
pruefe('Deutschlandticket-Frage: RB ist Nahverkehr',
  typeOf(zug({ category: 'DPN', line: 'RB37' })).longDistance, false);

// ---------------------------------------------------------------------
console.log('\ntrainLabel — was am Bahnsteig steht');

pruefe('Nahverkehr zeigt die Linie',
  trainLabel({ category: 'DPN', line: 'RB37', trainNumber: '24628' }), 'RB37');
pruefe('Fernverkehr zeigt die Zugnummer',
  trainLabel({ category: 'ICE', line: '2374', trainNumber: '2374' }), 'ICE 2374');
pruefe('Gattung wird nicht doppelt davorgesetzt',
  trainLabel({ category: 'RE', line: 'RE3' }), 'RE3');
pruefe('ohne Gattung kein Fragezeichen',
  trainLabel({ category: '', line: '', trainNumber: '', name: 'Sonderzug' }), 'Sonderzug');
pruefe('gar nichts bekannt', trainLabel({}), 'Zug');

// ---------------------------------------------------------------------
console.log('\nsameTrain — dieselbe Fahrt wiedererkennen');

pruefe('gleiche Zugnummer, gleiche Gattung',
  sameTrain({ category: 'ICE', trainNumber: '599' }, { category: 'ICE', trainNumber: '599' }), true);
pruefe('gleiche Linie ist NICHT derselbe Zug',
  sameTrain({ category: 'S', line: 'S33', trainNumber: '20326' },
    { category: 'S', line: 'S33', trainNumber: '20344' }), false);
pruefe('verschiedene Gattung schließt aus',
  sameTrain({ category: 'ICE', trainNumber: '5' }, { category: 'RE', trainNumber: '5' }), false);

// ---------------------------------------------------------------------
console.log('\nmodelOf — welches Fahrzeug, und wie sicher');

const strecke = (kategorie, halte, extra = {}) => zug({
  category: kategorie,
  from: { name: halte[0] },
  to: { name: halte[halte.length - 1] },
  stops: halte.map((name) => ({ name })),
  ...extra,
});

pruefe('Baureihe aus der Wagenreihung schlägt alles',
  modelOf(zug({ category: 'ICE', series: '412' })).model.label, 'ICE 4');
pruefe('… und heißt "series"',
  modelOf(zug({ category: 'ICE', series: '412' })).certainty, 'series');
pruefe('gelernte Baureihe wird als solche gekennzeichnet',
  modelOf(zug({ category: 'ICE', series: '408', seriesLearned: 12 })).certainty, 'learned');
pruefe('IC 2 Twindexx und KISS sind zwei Fahrzeuge',
  [modelOf(zug({ category: 'IC', series: '2462' })).model.id,
    modelOf(zug({ category: 'IC', series: '4110' })).model.id], ['ic2', 'ic2kiss']);

pruefe('ECE Zürich–München ist der Astoro, nicht der Giruno',
  modelOf(strecke('ECE', ['Zürich HB', 'St. Gallen', 'München Hbf'])).model.id, 'astoro');
pruefe('… auch in der Gegenrichtung',
  modelOf(strecke('ECE', ['München Hbf', 'Memmingen', 'Zürich HB'])).model.id, 'astoro');
pruefe('… auch als EC',
  modelOf(strecke('EC', ['Zürich HB', 'München Hbf'])).model.id, 'astoro');
pruefe('… und auf dem Teilstück ab Memmingen',
  modelOf(strecke('ECE', ['Memmingen', 'München Hbf'], { direction: 'Zürich HB' })).model.id, 'astoro');
pruefe('… und auf einem Teilstück, das nur die Richtung nennt',
  modelOf(zug({
    category: 'EC', from: { name: 'Memmingen' }, to: { name: 'Lindau-Reutin' },
    direction: 'Zürich HB', stops: [{ name: 'Memmingen' }, { name: 'Lindau-Reutin' }],
  })).model.id, 'astoro');
pruefe('aber der EC München–Innsbruck wird NICHT mitgefangen',
  modelOf(zug({
    category: 'EC', from: { name: 'München Hbf' }, to: { name: 'Innsbruck Hbf' },
    direction: 'Bologna', stops: [{ name: 'München Hbf' }, { name: 'Kufstein' }],
  })).model, null);
pruefe('ECE sonst bleibt der Giruno',
  modelOf(strecke('ECE', ['Frankfurt(Main)Hbf', 'Basel SBB', 'Milano Centrale'])).model.id, 'giruno');
pruefe('Gäubahn Stuttgart–Zürich ist der IC 2',
  modelOf(strecke('IC', ['Stuttgart Hbf', 'Singen(Hohentwiel)', 'Zürich HB'])).model.id, 'ic2');
pruefe('… aber nicht jeder IC ab Stuttgart',
  modelOf(strecke('IC', ['Stuttgart Hbf', 'Nürnberg Hbf'])).model, null);
pruefe('RJX ist immer der railjet',
  modelOf(zug({ category: 'RJX' })).certainty, 'sole');
pruefe('wo nichts sicher ist, wird nicht geraten',
  modelOf(strecke('EC', ['Hamburg Hbf', 'Praha hl.n.'])).certainty, 'none');

pruefe('jede Regel zeigt auf ein Modell, das es gibt',
  FLEET_RULES.filter((r) => !TRAIN_MODELS.some((m) => m.id === r.model)).map((r) => r.model), []);
pruefe('pauschale Regeln stehen hinter den streckenscharfen',
  FLEET_RULES.findIndex((r) => !r.between) > FLEET_RULES.findLastIndex((r) => r.between), true);

// ---------------------------------------------------------------------
console.log('\nLiveTracker — Ausfälle und Anschlüsse');

function tracker(legs, extra = {}) {
  const t = new LiveTracker({ hidden: true, replaceChildren() {}, append() {} }, null);
  t.journey = { legs: legs.map((e) => e.leg), arrival: '2099-01-01T12:00:00Z' };
  t.legs = legs;
  Object.assign(t, extra);
  return t;
}

const fahrt = (o) => ({ leg: zug({ from: { name: 'A', id: '1' }, to: { name: 'B', id: '2' }, ...o }), data: null });

pruefe('planmäßige Fahrt löst keinen Alarm aus',
  tracker([fahrt({ departure: '2099-01-01T10:00:00Z', arrival: '2099-01-01T11:00:00Z' })]).findCancellation(),
  null);

pruefe('ausgefallener Abschnitt wird gemeldet',
  tracker([fahrt({ cancelled: true, trainNumber: '7', departure: '2099-01-01T10:00:00Z', arrival: '2099-01-01T11:00:00Z' })])
    .findCancellation()?.status,
  'cancelled');

pruefe('ausgelassener Einstiegshalt zählt auch als Ausfall',
  LiveTracker.isCancelled({
    leg: { from: { id: '9' } },
    data: { stops: [{ id: '9', cancelled: true }] },
  }), true);

pruefe('bereits gefahrene Abschnitte lösen nichts mehr aus',
  tracker([fahrt({ cancelled: true, departure: '2000-01-01T10:00:00Z', arrival: '2000-01-01T11:00:00Z' })])
    .findCancellation(),
  null);

pruefe('Ist-Zeit des Abschnitts wird benutzt, wenn kein Zuglauf da ist',
  LiveTracker.legTime(fahrt({ arrival: '2099-01-01T11:00:00Z', arrivalReal: '2099-01-01T11:07:00Z' }), 'arrival').live,
  true);

// DER FALL, DER DIE GANZE VERFOLGUNG GERISSEN HAT: trainPosition() benutzt
// sameTrain(). Fehlt der Import, wirft erst dieser Aufruf — nicht das Laden.
pruefe('trainPosition läuft durch (fängt fehlende Importe)', (() => {
  const jetzt = new Date();
  const vorhin = new Date(jetzt.getTime() - 600_000).toISOString();
  const gleich = new Date(jetzt.getTime() + 600_000).toISOString();
  const t = tracker([{
    leg: zug({
      trainNumber: '599', category: 'ICE',
      departure: vorhin, arrival: gleich,
      from: { name: 'A' }, to: { name: 'B' },
      stops: [
        { name: 'A', lat: 48.0, lon: 11.0, departure: vorhin },
        { name: 'B', lat: 48.5, lon: 11.5, arrival: gleich },
      ],
    }),
    data: null,
  }]);
  t.map = { liveTrains: [{ category: 'ICE', trainNumber: '599', lat: 48.2, lon: 11.2 }] };
  const p = t.trainPosition();
  return p !== null && p.estimated === false;
})(), true);

// … und derselbe Fall noch einmal für den ANDEREN Zweig. Genau daran ist
// der erste Test vorbeigelaufen: er fand eine gemeldete Position und kehrte
// zurück, bevor die Hochrechnung (und damit snapToLine) je drankam. Zwei
// Zweige, zwei Aufrufe.
pruefe('trainPosition rechnet auch ohne gemeldete Position hoch', (() => {
  const jetzt = Date.now();
  const vorhin = new Date(jetzt - 600_000).toISOString();
  const gleich = new Date(jetzt + 600_000).toISOString();
  const t = tracker([{
    leg: zug({
      trainNumber: '599', category: 'ICE',
      departure: vorhin, arrival: gleich,
      from: { name: 'A' }, to: { name: 'B' },
      geometry: [[48.0, 11.0], [48.25, 11.3], [48.5, 11.5]],
      stops: [
        { name: 'A', lat: 48.0, lon: 11.0, departure: vorhin },
        { name: 'B', lat: 48.5, lon: 11.5, arrival: gleich },
      ],
    }),
    data: null,
  }]);
  t.map = { liveTrains: [] };          // nichts gemeldet -> Hochrechnung
  const p = t.trainPosition();
  return p !== null && p.estimated === true && Number.isFinite(p.lat);
})(), true);

// ---------------------------------------------------------------------
console.log('\nErsatz bei Ausfall — Brücke und Zusammenführung');
{
  const { bridgeOption, mergeAlternatives, spliceJourney } = await import('../public/assets/js/scoring.js');

  // Ostbahnhof --S-Bahn (fällt aus)--> Hbf --ICE--> Frankfurt
  const sbahn = {
    mode: 'train', category: 'S', line: '8', trainNumber: '8123', cancelled: true,
    from: { id: '8000262', name: 'München Ost', lat: 48.127, lon: 11.605 },
    to:   { id: '8000261', name: 'München Hbf', lat: 48.140, lon: 11.558 },
    departure: '2026-09-24T08:00:00+02:00', arrival: '2026-09-24T08:10:00+02:00',
  };
  const umstieg = { mode: 'walk', from: sbahn.to, to: sbahn.to,
    departure: '2026-09-24T08:10:00+02:00', arrival: '2026-09-24T08:15:00+02:00' };
  const ice = {
    mode: 'train', category: 'ICE', line: '', trainNumber: '592', cancelled: false,
    from: sbahn.to, to: { id: '8000105', name: 'Frankfurt Hbf' },
    departure: '2026-09-24T08:28:00+02:00', arrival: '2026-09-24T11:40:00+02:00',
  };
  const reise = { id: 'r1', departure: sbahn.departure, arrival: ice.arrival,
    legs: [sbahn, umstieg, ice] };

  const u5 = (ab, an) => ({
    id: 'mvg-1', departure: ab, arrival: an,
    legs: [{ mode: 'train', category: 'U', line: 'U5', trainNumber: '',
      from: sbahn.from, to: { name: 'Hauptbahnhof (U, Tram)' }, departure: ab, arrival: an }],
    trains: ['U5'],
  });

  const passt = bridgeOption(reise, 0, u5('2026-09-24T08:02:00+02:00', '2026-09-24T08:12:00+02:00'));
  pruefe('U-Bahn überbrückt den Ausfall, der ICE bleibt',
    passt && passt.legs.map((l) => l.line || l.trainNumber || l.mode), ['U5', 'walk', '592']);
  pruefe('überbrückte Reise kommt an wie geplant', passt?.arrival, ice.arrival);
  pruefe('Beschriftung nennt Linie und ICE', passt?.trains, ['U5', 'ICE 592']);

  const zuSpaet = bridgeOption(reise, 0, u5('2026-09-24T08:15:00+02:00', '2026-09-24T08:26:00+02:00'));
  pruefe('Brücke, die den ICE nicht mehr erreicht, wird verworfen', zuSpaet, null);

  const allein = { id: 'r2', departure: sbahn.departure, arrival: sbahn.arrival, legs: [sbahn] };
  const nurBruecke = bridgeOption(allein, 0, u5('2026-09-24T08:02:00+02:00', '2026-09-24T08:12:00+02:00'));
  pruefe('ist der Ausfall der letzte Abschnitt, genügt die Brücke', nurBruecke?.arrival,
    '2026-09-24T08:12:00+02:00');
  pruefe('„weiter wie geplant" nur, wenn danach noch etwas wie geplant fährt',
    [passt?.bridged, nurBruecke?.bridged], [true, false]);

  const zusammen = spliceJourney(reise, 0, passt);
  pruefe('übernommen: keine ausgefallene S-Bahn mehr in der Reise',
    zusammen.legs.some((l) => l.cancelled), false);

  const gemischt = mergeAlternatives([
    { departure: '2026-09-24T08:05:00', arrival: '2026-09-24T08:20:00', trains: ['S6'] },
    { departure: '2026-09-24T08:02:00', arrival: '2026-09-24T08:12:00', trains: ['U5'] },
    { departure: '2026-09-24T08:05:00', arrival: '2026-09-24T08:20:00', trains: ['S6'] },
  ], 4);
  pruefe('nach Ankunft sortiert, Doppeltes aus MVG und HAFAS einmal',
    gemischt.map((o) => o.trains[0]), ['U5', 'S6']);
}

// ---------------------------------------------------------------------
console.log('\nBenachrichtigungen — was ist neu, was nur Rauschen?');

{
  const jetzt = Date.now();
  const iso = (min) => new Date(jetzt + min * 60000).toISOString();
  // Ein ICE, der in 20 Minuten in München Hbf abfährt, mit Zuglauf.
  const leg = zug({
    category: 'ICE', trainNumber: '724', line: '724',
    from: { id: '8000261', name: 'München Hbf', platform: '12' },
    to: { id: '8000105', name: 'Frankfurt(Main)Hbf' },
    departure: iso(20), arrival: iso(260),
  });
  const lauf = (verspaetung, gleis) => ({
    delay: verspaetung,
    stops: [
      { id: '8000261', name: 'München Hbf', departure: iso(20), departureReal: iso(20 + verspaetung), platform: gleis },
      { id: '8000105', name: 'Frankfurt(Main)Hbf', arrival: iso(260), arrivalReal: iso(260 + verspaetung) },
    ],
  });
  const tracker = (data) => Object.assign(Object.create(LiveTracker.prototype), {
    legs: [{ leg, jid: 'x', data }], risk: null,
    alerted: new Map(), alertBaseline: true, notify: true,
  });

  pruefe('Verspätung aus dem Zuglauf, nicht aus der alten Suche',
    LiveTracker.liveDelay({ leg: { ...leg, departureReal: iso(21) }, data: lauf(12, '12') }), 12);
  pruefe('Gleiswechsel erkannt',
    LiveTracker.platformChange({ leg, data: lauf(0, '14') }), { planned: '12', now: '14' });
  pruefe('gleiches Gleis ist kein Wechsel',
    LiveTracker.platformChange({ leg, data: lauf(0, '12') }), null);

  const t = tracker(lauf(3, '12'));
  pruefe('drei Minuten sind keine Meldung', t.collectAlerts().length, 0);
  t.legs[0].data = lauf(7, '14');
  const a = t.collectAlerts();
  pruefe('ab fünf Minuten: Verspätung (Stufe 5) und Gleiswechsel',
    a.map((x) => [x.key.split('|')[0], x.level]), [['delay', 5], ['gleis', 1]]);

  // Der erste Stand wird nur notiert; danach meldet nur, was neu ist oder
  // eine höhere Stufe erreicht.
  const gemeldet = [];
  const orig = LiveTracker.showNotification;
  LiveTracker.showNotification = (title) => { gemeldet.push(title); };
  t.checkAlerts();
  pruefe('erster Stand: nichts gemeldet', gemeldet.length, 0);
  t.legs[0].data = lauf(9, '14');
  t.checkAlerts();
  pruefe('9 statt 7 min: dieselbe Stufe, keine neue Meldung', gemeldet.length, 0);
  t.legs[0].data = lauf(11, '14');
  t.checkAlerts();
  pruefe('11 min: nächste Stufe, eine Meldung', gemeldet, ['ICE 724: +11 min']);
  LiveTracker.showNotification = orig;
}

// ---------------------------------------------------------------------
console.log('\nLive-Verfolgung — auch U-Bahn und Tram');

pruefe('Zuglauf-Kennung von HAFAS zuerst',
  LiveTracker.sourceOf(zug({ jid: 'x', dbJourneyId: 'y' })), 'hafas');
pruefe('DB-Fahrplan: der Zuglauf der DB',
  LiveTracker.sourceOf(zug({ dbJourneyId: 'y', line: 'U1' })), 'db');
pruefe('MVG-Verbindung: die Abfahrtstafel der MVG',
  LiveTracker.sourceOf(zug({ line: 'U3', from: { id: 'mvg:de:09162:60' }, to: { id: 'mvg:de:09162:40' } })), 'mvg');
pruefe('ohne jede Kennung: nichts nachzuladen',
  LiveTracker.sourceOf(zug({ line: 'U3', from: { id: '625176' }, to: { id: '8000261' } })), null);
{
  const halte = [
    { name: 'Odeonsplatz', departure: '2026-09-24T09:00:00+02:00' },
    { name: 'Marienplatz', arrival: '2026-09-24T09:02:00+02:00', departure: '2026-09-24T09:02:00+02:00' },
    { name: 'Goetheplatz', arrival: '2026-09-24T09:05:00+02:00' },
  ];
  const lauf = { hasRealtime: true, delay: 2, stops: [
    { departureReal: '2026-09-24T09:02:00+02:00', platform: '2' },
    { arrivalReal: '2026-09-24T09:07:00+02:00' },
  ] };
  const m = LiveTracker.mergeStops(halte, lauf);
  pruefe('MVG: alle Halte bleiben, dazwischen um die Verspätung verschoben',
    [m.length, new Date(m[1].arrivalReal).getTime() - new Date(halte[1].arrival).getTime(), m[0].platform],
    [3, 2 * 60000, '2']);
}

// ---------------------------------------------------------------------
console.log('\nFahrgastrechte — ab wann, wie viel');

{
  const jetzt = Date.now();
  const iso = (min) => new Date(jetzt + min * 60000).toISOString();
  const mit = (verspaetung, laender, original) => {
    const leg = zug({ category: 'ICE', trainNumber: '724', from: { id: '8000261' }, to: { id: '8000105' },
      departure: iso(10), arrival: iso(240) });
    const journey = { arrival: iso(240), countries: laender, legs: [leg], ...(original ? { original } : {}) };
    return Object.assign(Object.create(LiveTracker.prototype), {
      journey, risk: null,
      legs: [{ leg, data: { stops: [{ id: '8000105', arrival: iso(240), arrivalReal: iso(240 + verspaetung) }] } }],
    });
  };
  pruefe('15 min national: noch nichts', mit(15, ['de']).rights(), null);
  pruefe('25 min national: Zugbindung aufgehoben, noch keine Entschädigung',
    [mit(25, ['de']).rights()?.trainChoice, mit(25, ['de']).rights()?.share], [true, 0]);
  pruefe('25 min international: noch nichts (erst ab 60)', mit(25, ['de', 'at']).rights(), null);
  pruefe('70 min: 25 %', mit(70, ['de', 'ch']).rights()?.share, 25);
  pruefe('130 min: 50 %', mit(130, ['ch']).rights()?.share, 50);
  pruefe('Schweiz allein: keine DB-Zugbindung', mit(130, ['ch']).rights()?.trainChoice, false);
  // Umdisponiert: gezählt wird gegen die gebuchte Ankunft der ersten Wahl.
  pruefe('nach Umdisponieren zählt die ursprünglich gebuchte Ankunft',
    mit(0, ['de'], { arrival: iso(170) }).destinationDelay(), 70);
}

{
  const lauf = { stops: [
    { id: '8503000', departure: '2026-09-24T09:02:00+02:00' },
    { id: '8503016', arrival: '2026-09-24T09:12:00+02:00', departure: '2026-09-24T09:13:00+02:00' },
    { id: '8500010', arrival: '2026-09-24T10:00:00+02:00' },
  ] };
  const leg = zug({ from: { id: '8503000' }, to: { id: '8500010' } });
  const mitPrognose = LiveTracker.withPrognosis(lauf, leg,
    { delay: 4, departureReal: '2026-09-24T09:06:00+02:00', platformFrom: '14' });
  pruefe('Schweizer Prognose: Einstieg, Zwischenhalt verschoben, Echtzeit an',
    [mitPrognose.stops[0].platform, new Date(mitPrognose.stops[1].arrivalReal).getTime()
      - new Date('2026-09-24T09:12:00+02:00').getTime(), mitPrognose.hasRealtime],
    ['14', 4 * 60000, true]);
}

// ---------------------------------------------------------------------
console.log('\nWagenreihung — Sektoren kurz');

{
  const { sectorRange } = await import('../public/assets/js/render.js');
  const alle = ['A', 'B', 'C', 'D', 'E', 'F', 'G'].map((name) => ({ name }));
  pruefe('zusammenhängend', sectorRange(['C', 'A', 'B', 'B'], alle), 'A–C');
  pruefe('mit Lücke', sectorRange(['A', 'D', 'E'], alle), 'A, D–E');
  pruefe('leer', sectorRange([], alle), '');
}

// ---------------------------------------------------------------------
console.log('\nAbfahrtstafel — Filtergruppen');

{
  const { groupOf } = await import('../public/assets/js/board.js');
  pruefe('ICE → Fernverkehr', groupOf({ category: 'ICE', trainNumber: '722' }), 'fern');
  pruefe('HAFAS-S-Bahn "DB" mit Linie S1 → S-Bahn', groupOf({ category: 'DB', line: 'S1' }), 'S');
  pruefe('MVG-U-Bahn → U-Bahn', groupOf({ category: 'U', line: 'U5' }), 'U');
  pruefe('RB16 → Regional', groupOf({ category: 'RB', line: 'RB16' }), 'regio');
  pruefe('Tram 19 → Tram', groupOf({ category: 'Tram', line: '19' }), 'Tram');
}

// ---------------------------------------------------------------------
console.log('\nImporte — benutzt, aber nicht geholt?');

// ZWEIMAL ist genau dieser Fehler durchgerutscht: `sameTrain` und
// `snapToLine` wurden in live.js benutzt, ohne importiert zu sein. Beides
// fiel beim Laden nicht auf — ESM meckert nur bei einem kaputten Import,
// nicht bei einem fehlenden —, und `node --check` sieht es nie. Der Aufruf
// stand jeweils in einem Zweig, den man nur unterwegs im Zug erreicht.
//
// Statt darauf zu hoffen, dass jeder Zweig einen Test bekommt, prüft das
// hier die Regel selbst: Was ein Nachbarmodul exportiert und hier als
// nacktes Wort vorkommt, muss auch importiert sein.
{
  const { readFileSync, readdirSync } = await import('node:fs');
  const wurzel = new URL('../public/assets/js/', import.meta.url);
  const dateien = [
    ...readdirSync(wurzel).filter((f) => f.endsWith('.js')).map((f) => f),
    ...readdirSync(new URL('data/', wurzel)).filter((f) => f.endsWith('.js')).map((f) => 'data/' + f),
  ];

  const quelle = Object.fromEntries(dateien.map((f) =>
    [f, readFileSync(new URL(f, wurzel), 'utf8')]));

  // Was exportiert jedes Modul?
  const exporte = {};
  for (const [f, txt] of Object.entries(quelle)) {
    exporte[f] = [...txt.matchAll(/^export\s+(?:async\s+)?(?:function|const|class)\s+([A-Za-z_$][\w$]*)/gm)]
      .map((m) => m[1]);
  }

  const fehlend = [];
  for (const [f, txt] of Object.entries(quelle)) {
    // Kommentare raus, sonst zählt Prosa als Benutzung.
    const code = txt.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(?<!:)\/\/.*$/gm, '');
    const importiert = new Set(
      [...code.matchAll(/import\s*\{([^}]*)\}/g)]
        .flatMap((m) => m[1].split(',').map((x) => x.trim().split(/\s+as\s+/).pop()))
    );

    for (const [andere, namen] of Object.entries(exporte)) {
      if (andere === f) continue;
      for (const name of namen) {
        if (importiert.has(name)) continue;
        // Als nacktes Wort benutzt (nicht nach einem Punkt)?
        if (!new RegExp(`(?<![.\\w$])${name}\\s*\\(`).test(code)) continue;
        // Lokal selbst definiert? Dann ist es ein anderes Ding.
        if (new RegExp(`(?:function|const|let|var|class)\\s+${name}\\b`).test(code)) continue;
        fehlend.push(`${f}: ${name} (aus ${andere})`);
      }
    }
  }
  pruefe('jedes benutzte Modul-Export ist auch importiert', fehlend, []);
}

// ---------------------------------------------------------------------
console.log(`\n${gesamt - fehlgeschlagen} von ${gesamt} bestanden.`);
process.exit(fehlgeschlagen === 0 ? 0 : 1);
