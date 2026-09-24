/**
 * Darstellung der Ergebnisliste.
 *
 * Bewusst ohne Framework und ohne innerHTML mit Fremddaten: Stationsnamen und
 * Zugbezeichnungen kommen von externen APIs, deshalb wird alles über
 * textContent gesetzt.
 */

import { typeOf } from './data/trains.js';
import { trainLabel, RouteMap } from './map.js';
import { formatDuration, formatTime, formatPrice, priceOrigin, counterValue, fxInfo } from './scoring.js';

/**
 * Höchste gemeldete Auslastung einer Verbindung, für die gewählte Klasse.
 * Stufen der DB: 1 gering, 2 mittel, 3 hoch, 4 ausgebucht.
 */
const OCCUPANCY_LABELS = {
  1: 'gering ausgelastet',
  2: 'mittel ausgelastet',
  3: 'stark ausgelastet',
  4: 'ausgebucht',
};

function occupancyOf(journey, travelClass) {
  let level = 0;
  for (const leg of journey.legs || []) {
    const o = leg.occupancy;
    if (!o) continue;
    const v = travelClass === 1 ? o.first : o.second;
    if (typeof v === 'number' && v > level) level = v;
  }
  return level > 0 ? { level, label: OCCUPANCY_LABELS[level] || `Stufe ${level}` } : null;
}

/**
 * MVV-Tarifzonen lesbar: 0 ist die Zone M, 1 bis 12 die Ringe darum.
 * [0] → "Zone M", [0, 1, 2] → "Zonen M–2".
 */
function zonesLabel(zones) {
  const z = [...new Set((zones || []).map(Number).filter(Number.isFinite))].sort((a, b) => a - b);
  if (z.length === 0) return 'Tarif der MVV';
  const name = (n) => (n === 0 ? 'M' : String(n));
  return z.length === 1 ? `Zone ${name(z[0])}` : `Zonen ${name(z[0])}–${name(z[z.length - 1])}`;
}

const el = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

export function renderResults(container, ranked, marks, state, onSelect, onMore, liveCtl, onEarlier) {
  container.replaceChildren();

  // Eine laufende Verfolgung, die in dieser Liste nicht vorkommt, bekommt
  // eine eigene Zeile oben. Sonst verschwindet sie nach einer neuen Suche
  // oder nach dem Umdisponieren aus dem Blickfeld, obwohl sie weiterläuft.
  const tracked = liveCtl?.tracked?.();
  if (tracked && !ranked.some((e) => liveCtl.isTracking(e.journey))) {
    container.append(renderTrackedBar(tracked, liveCtl));
  }

  if (ranked.length === 0) {
    // Nur nach einer Suche - beim Öffnen einer geteilten Fahrt hat noch
    // niemand gesucht, und "keine Verbindungen" wäre dort falsch.
    if (state.lastPayload) container.append(el('p', 'empty', 'Keine Verbindungen gefunden.'));
    return;
  }

  // Frühere Abfahrten — oben, wo sie hingehören: eine Verbindung eine halbe
  // Stunde vor der gesuchten steht in der Liste vor ihr, nicht dahinter.
  // Die Uhrzeit im Suchformular ist ja nur der Wunsch; ob eine Viertelstunde
  // früher besser passt, sieht man erst an den Treffern.
  if (state.scrollBackCtx || state.loadingEarlier) {
    container.append(renderEarlier(state, onEarlier));
  }

  const visible = Math.min(state.visible ?? ranked.length, ranked.length);
  for (let index = 0; index < visible; index++) {
    const entry = ranked[index];
    // Im Nerd-Mode stehen die Verbindungen nach Routenvarianten sortiert.
    // Der Kopf trennt sie sichtbar - sonst wirkt die Liste wie eine
    // Rangfolge, obwohl sie eine Auswahl zwischen Wegen ist.
    if (state.mode === 'nerd' && entry.group?.first) {
      container.append(renderGroupHead(entry.group));
    }
    container.append(renderCard(entry, index, marks, state, onSelect, liveCtl));
  }

  const rest = ranked.length - visible;
  if (rest > 0 || state.scrollCtx || state.loadingMore) {
    container.append(renderMore(ranked, marks, visible, rest, state, onMore));
  }
}

/**
 * Der Knopf am Fuß der Liste.
 *
 * Er tut zwei verschiedene Dinge, und das steht auch dran: solange noch
 * geladene Verbindungen verborgen sind, klappt er nur auf. Danach holt er
 * die nächste Seite bei der ÖBB.
 *
 * Beim Aufklappen nennt er zusätzlich, ob unter den verborgenen Treffern
 * eine ausgezeichnete steckt - sonst müsste man blind klicken, um zu
 * wissen, ob es sich lohnt.
 */
function renderMore(ranked, marks, visible, rest, state, onMore) {
  const btn = el('button', 'more');
  btn.type = 'button';

  if (state.loadingMore) {
    btn.disabled = true;
    btn.append(el('span', 'more__label', 'Lade spätere Verbindungen …'));
    return btn;
  }

  if (rest > 0) {
    btn.append(el('span', 'more__label',
      rest === 1 ? 'Eine weitere Verbindung anzeigen' : `${rest} weitere Verbindungen anzeigen`));

    const hidden = ranked.slice(visible);
    const teasers = [];
    if (marks.cheapest && hidden.includes(marks.cheapest)) teasers.push('die günstigste');
    if (marks.fastest && hidden.includes(marks.fastest)) teasers.push('die schnellste');
    if (marks.comfiest && hidden.includes(marks.comfiest)) teasers.push('die bequemste');
    if (teasers.length > 0) {
      btn.append(el('span', 'more__hint', `darunter ${teasers.join(' und ')}`));
    }
  } else {
    btn.append(el('span', 'more__label', 'Spätere Verbindungen laden'));
    btn.append(el('span', 'more__hint', 'sucht ab der letzten Abfahrt weiter'));
  }

  btn.addEventListener('click', () => onMore && onMore());
  return btn;
}

/** Der Knopf am Kopf der Liste: eine Seite früherer Abfahrten nachladen. */
function renderEarlier(state, onEarlier) {
  const btn = el('button', 'more');
  btn.type = 'button';

  if (state.loadingEarlier) {
    btn.disabled = true;
    btn.append(el('span', 'more__label', 'Lade frühere Verbindungen …'));
    return btn;
  }

  btn.append(el('span', 'more__label', 'Frühere Verbindungen laden'));
  btn.append(el('span', 'more__hint', 'sucht vor der ersten Abfahrt weiter'));
  btn.addEventListener('click', () => onEarlier && onEarlier());
  return btn;
}

/** Hinweiszeile auf eine verfolgte Verbindung, die nicht in der Liste steht. */
function renderTrackedBar(journey, liveCtl) {
  const bar = el('div', 'tracked-bar');
  bar.append(el('span', 'tracked-bar__label', journey.shared ? 'Geteilte Fahrt' : 'Du verfolgst'));

  const trains = (journey.legs || []).filter((l) => l.mode === 'train');
  const to = trains[trains.length - 1]?.to?.name;
  bar.append(el('span', 'tracked-bar__text',
    `${formatTime(journey.departure)} → ${formatTime(journey.arrival)}`
    + (to ? ` · ${to}` : '')));

  if (journey.rerouted) bar.append(el('span', 'tracked-bar__tag', 'umdisponiert'));

  const stop = el('button', 'tracked-bar__stop', 'beenden');
  stop.type = 'button';
  stop.addEventListener('click', () => liveCtl.toggle(journey));
  bar.append(stop);
  return bar;
}

/** Trennzeile vor der ersten Verbindung einer Routenvariante. */
function renderGroupHead(group) {
  const head = el('div', 'group-head');
  head.append(el('span', 'group-head__label', group.label));
  head.append(el('span', 'group-head__count',
    group.size === 1 ? '1 Verbindung' : `${group.size} Verbindungen`));
  return head;
}

function renderCard(entry, index, marks, state, onSelect, liveCtl) {
  const j = entry.journey;
  const card = el('article', 'journey');
  if (index === 0) card.classList.add('journey--best');
  if (index === state.selectedIndex) card.classList.add('is-selected');

  // Auswahl steuert, welche Route auf der Karte hervorgehoben wird.
  card.addEventListener('click', (e) => {
    // Klicks auf Links und das Aufklappen der Details nicht abfangen.
    if (e.target.closest('a, summary')) return;
    if (onSelect) onSelect(index);
  });

  // --- Kopf: Zeiten, Dauer, Preis ---
  const head = el('header', 'journey__head');

  // Zeiten. Liegt eine Ist-Zeit vor und weicht sie ab, steht der Fahrplanwert
  // durchgestrichen daneben - sonst müsste man raten, was gilt.
  const times = el('div', 'journey__times');
  const timePair = (plan, real) => {
    const p = formatTime(plan);
    const r = real ? formatTime(real) : null;
    if (!r || r === p) {
      times.append(el('span', 'journey__time', p));
      return;
    }
    times.append(el('span', 'journey__time journey__time--planned', p));
    times.append(el('span', 'journey__time journey__time--real', r));
  };
  timePair(j.departure, j.departureReal);
  times.append(el('span', 'journey__arrow', '→'));
  timePair(j.arrival, j.arrivalReal);

  const meta = el('div', 'journey__meta');
  meta.append(el('span', 'journey__duration', formatDuration(j.durationMin)));
  meta.append(
    el(
      'span',
      'journey__changes',
      j.changes === 0 ? 'direkt' : `${j.changes} Umstieg${j.changes > 1 ? 'e' : ''}`
    )
  );

  const left = el('div', 'journey__main');
  left.append(times, meta);

  const right = el('div', 'journey__price-box');
  const priceText = formatPrice(j.price);
  if (priceText) {
    const p = el('div', 'journey__price', priceText);
    if (j.price.estimated) p.classList.add('journey__price--est');
    if (j.price.covered) p.classList.add('journey__price--covered');
    right.append(p);

    // Gegenwert in der anderen Währung — bei einer Fahrt München–Zürich ist
    // "wie viel ist das in Franken" die naheliegende Frage.
    const other = counterValue(j.price);
    if (other) {
      const c = el('div', 'journey__price-alt', other);
      const fx = fxInfo();
      c.title = `EZB-Referenzkurs vom ${fx.date || 'aktuellen Tag'} — kein Bankkurs, `
        + 'beim Bezahlen können Aufschläge dazukommen.';
      right.append(c);
    }

    right.append(el('div', 'journey__price-label', priceOrigin(j.price)));

    // Zubringer in München: für das Stück in der Stadt braucht es ein
    // eigenes Ticket - außer man hat den Flexpreis mit City-Ticket.
    if (j.feeder) {
      const z = el('div', 'journey__price-alt', `+ MVV ${zonesLabel(j.feeder.tariffZones)}`);
      z.title = 'Für die Fahrt in der Stadt. Im Flexpreis der DB ist das City-Ticket enthalten, '
        + 'mit Deutschlandticket fährt man ohnehin.';
      right.append(z);
    }
  } else if (j.source === 'mvg') {
    // Stadtfahrt: die MVG nennt die Tarifzonen, keinen Preis.
    right.append(el('div', 'journey__price journey__price--none', 'MVV'));
    right.append(el('div', 'journey__price-label', zonesLabel(j.tariffZones)));
  } else {
    right.append(el('div', 'journey__price journey__price--none', '–'));
    right.append(el('div', 'journey__price-label', 'kein Preis'));
  }

  head.append(left, right);
  card.append(head);

  // --- Badges ---
  const badges = el('div', 'badges');
  // Gibt das Abzeichen zurück, damit der Aufrufer noch einen Titel
  // dranhängen kann. Die meisten brauchen ihn nicht.
  const add = (text, cls) => {
    const n = el('span', `badge ${cls}`, text);
    badges.append(n);
    return n;
  };

  // Selbst zusammengestellt, nicht so im Fahrplan: das gehört kenntlich
  // gemacht, sonst sucht man diese Verbindung im Ticketshop vergeblich.
  // Anklickbar, weil eine Entscheidung unter Zeitdruck zurücknehmbar sein muss.
  if (j.rerouted) {
    if (j.original && liveCtl?.undoAlternative) {
      const undo = el('button', 'badge badge--rerouted badge--undo', 'umdisponiert');
      undo.type = 'button';
      undo.append(el('span', 'badge__undo-hint', 'zurück'));
      undo.title = 'Zurück zur ursprünglichen Verbindung';
      undo.addEventListener('click', (e) => {
        e.stopPropagation();
        liveCtl.undoAlternative(j);
      });
      badges.append(undo);
    } else {
      add('umdisponiert', 'badge--rerouted');
    }
  }

  if (marks.cheapest === entry) add('günstigste', 'badge--price');
  if (marks.fastest === entry) add('schnellste', 'badge--fast');
  if (marks.comfiest === entry) add('bequemste', 'badge--comfort');
  if (marks.fewestChanges === entry && j.changes === 0) add('umstiegsfrei', 'badge--direct');

  if (state.mode === 'nerd') {
    add(`Komfort ${entry.comfort.toFixed(1)}`, 'badge--score');
    // Innerhalb einer Routenvariante ist die interessante Frage nicht der
    // Rang, sondern der Abstand zur schnellsten Option desselben Weges.
    const g = entry.group;
    if (g) {
      if (g.first) add('beste dieser Route', 'badge--variant');
      else if (g.slowerThanBest > 0) add(`+${formatDuration(g.slowerThanBest)}`, 'badge--variant');
    }
  }
  for (const hit of entry.comfortHits) add(hit, 'badge--rule');

  // Fußwege zwischen verschiedenen Halten — oft der Grund, warum eine
  // Verbindung schneller ist als erwartet.
  const walks = (j.legs || []).filter((l) => l.mode === 'walk' && l.changesPlace);
  if (walks.length > 0) {
    add(walks.length === 1 ? 'mit Fußweg' : `${walks.length} Fußwege`, 'badge--walk');
  }

  // AUSFALL ZUERST. Ein ausgefallener Zug stand in der Liste bisher gar
  // nicht drin — die Verbindung sah aus wie jede andere, und man erfuhr es
  // erst am Bahnsteig. Er gehört an die erste Stelle der Zeile, noch vor
  // Verspätung und knappem Umstieg: die sind dann ohnehin gegenstandslos.
  const ausfaelle = (j.legs || []).filter((l) => l.mode === 'train' && l.cancelled);
  if (ausfaelle.length > 0) {
    add(
      ausfaelle.length === 1 ? `${trainLabel(ausfaelle[0])} fällt aus` : `${ausfaelle.length} Züge fallen aus`,
      'badge--cancelled'
    );
  } else if (j.reachable === false) {
    // HAFAS rechnet jede Verbindung gegen die Echtzeitlage nach. Passt sie
    // nicht mehr - meist, weil eine Verspätung den Anschluss gekappt hat -,
    // steht das hier, bevor man sie bucht.
    const b = add('laut Echtzeit nicht erreichbar', 'badge--cancelled');
    b.title = 'Nach der aktuellen Verspätungslage ist mindestens ein Anschluss dieser Verbindung nicht zu schaffen.';
  }

  // Verspätung, sofern die DB Echtzeitdaten geliefert hat.
  if (typeof j.delay === 'number' && j.delay > 0) {
    add(`+${j.delay} min`, j.delay >= 5 ? 'badge--risky' : 'badge--tight');
  } else if (j.delay === 0) {
    add('pünktlich', 'badge--ontime');
  }

  // Knappe Umstiege sind der häufigste Grund, warum eine Verbindung platzt.
  // Mit Echtzeit zählt die tatsächliche Lücke, nicht die im Fahrplan.
  const live = j.minTransferLive;
  if (typeof live === 'number' && live !== j.minTransferMin) {
    add(
      live < 0 ? `Anschluss weg (${live} min)` : `nur noch ${live} min Umstieg`,
      live < 5 ? 'badge--risky' : 'badge--tight'
    );
  } else if (j.transferRisk === 'risky') {
    add(`nur ${j.minTransferMin} min Umstieg`, 'badge--risky');
  } else if (j.transferRisk === 'tight') {
    add(`${j.minTransferMin} min Umstieg`, 'badge--tight');
  }

  // PÜNKTLICHKEIT AUF DIE KARTE. Die Statistik sammelt sich mit jeder
  // Nutzung und stand bisher nur im aufgeklappten Detailbereich — also genau
  // dort, wo man sie beim Vergleich zweier Verbindungen nicht sieht.
  //
  // Gezeigt wird der SCHWÄCHSTE Abschnitt, nicht der Durchschnitt: eine
  // Verbindung ist so pünktlich wie ihr unpünktlichster Zug, und bei einem
  // Umstieg entscheidet ohnehin er.
  const hist = Object.values(j.history || {});
  if (hist.length > 0) {
    const schwächster = hist.reduce((a, b) => (a.rate ?? 1) <= (b.rate ?? 1) ? a : b);
    const quote = Math.round((schwächster.rate ?? 0) * 100);
    const eigen = hist.some((h) => h.source !== 'baseline');
    const b = add(
      `${quote} % pünktlich`,
      quote >= 80 ? 'badge--ontime' : quote >= 60 ? 'badge--tight' : 'badge--risky'
    );
    b.title = eigen
      ? `Aus eigenen Messungen: im Schnitt +${(schwächster.avg ?? 0).toFixed(1).replace('.', ',')} min.`
      : 'Näherung aus der Jahresstatistik des Betreibers — noch keine eigenen Messungen.';
    if (!eigen) b.classList.add('badge--rule');
  }

  // Auslastung: die höchste gemeldete Stufe über alle Abschnitte.
  const occ = occupancyOf(j, state.travelClass);
  if (occ) add(occ.label, `badge--occ${occ.level}`);

  // Die DB markiert selbst, wo das Deutschlandticket gilt.
  const dTicketLegs = (j.legs || []).filter((l) => l.dTicket).length;
  if (dTicketLegs > 0) {
    add(
      dTicketLegs === 1 ? 'D-Ticket auf 1 Abschnitt' : `D-Ticket auf ${dTicketLegs} Abschnitten`,
      'badge--dticket'
    );
  }

  if (badges.childElementCount > 0) card.append(badges);

  // --- Zugkette ---
  const chain = el('div', 'chain');
  for (const leg of j.legs.filter((l) => l.mode === 'train')) {
    const type = typeOf(leg);
    const chip = el('span', 'chip');
    chip.classList.add(type.longDistance ? 'chip--fern' : 'chip--nah');
    if (type.night) chip.classList.add('chip--night');

    // Beschriftung aus einer Hand: im Nahverkehr die Linie, im Fernverkehr
    // die Zugnummer - siehe trainLabel(). Die Gattung normalisiert es selbst.
    chip.textContent = trainLabel(leg);

    // Fahrzeugmodell direkt am Chip, wenn wir es kennen.
    const ce = entry.comfortPerLeg.find((c) => c.leg === leg);
    if (ce?.model) {
      chip.append(el('span', 'chip__series', ce.model.label));
    } else if (leg.series) {
      chip.append(el('span', 'chip__series', leg.series));
    }

    chip.title = [
      type.long,
      ce?.model ? `Fahrzeug: ${ce.model.label}` : null,
      leg.series ? `Baureihe ${leg.series}` : null,
      ce?.model?.note || type.note,
      leg.dTicket || null,
    ].filter(Boolean).join(' — ');
    chain.append(chip);
  }
  card.append(chain);

  // --- Live verfolgen, Rückfahrt ---
  // Stehen vor den Details, weil sie die häufigsten Handlungen sind.
  const aktionen = el('div', 'card-actions');
  if (liveCtl?.trackable(j)) {
    const tracking = liveCtl.isTracking(j);
    const btn = el('button', 'live-btn', tracking ? 'Verfolgung beenden' : 'Live verfolgen');
    btn.type = 'button';
    btn.setAttribute('aria-pressed', String(tracking));
    if (tracking) btn.classList.add('is-on');
    btn.title = 'Verspätungen, Gleise und Meldungen dieser Verbindung — mit GPS-Mitfahrt.';
    btn.addEventListener('click', (e) => {
      e.stopPropagation(); // nicht zugleich die Karte umschalten
      liveCtl.toggle(j);
    });
    aktionen.append(btn);
  }
  // Mit einem Tipp zurück: Start und Ziel getauscht, ab der Ankunft dieser
  // Verbindung. Vorher hieß das: tauschen, Datum prüfen, Uhrzeit ausrechnen.
  if (liveCtl?.returnTrip) {
    const back = el('button', 'live-btn return-btn', 'Rückfahrt');
    back.type = 'button';
    back.title = 'Rückfahrt suchen — ab einer Stunde nach der Ankunft dieser Verbindung.';
    back.addEventListener('click', (e) => {
      e.stopPropagation();
      liveCtl.returnTrip(j);
    });
    aktionen.append(back);
  }
  if (aktionen.childElementCount > 0) card.append(aktionen);

  // --- Detailbereich ---
  //
  // Der Zustand "aufgeklappt" hängt an der Verbindung, nicht am Element: die
  // Liste wird neu gezeichnet, sobald Ersatzverbindungen oder Tarife
  // nachgeladen sind, und klappte dabei alles wieder zu - mitten im Lesen.
  const details = el('details', 'journey__details');
  details.open = Boolean(j._detailsOpen);
  details.addEventListener('toggle', () => { j._detailsOpen = details.open; });
  details.append(el('summary', null, 'Streckenverlauf und Details'));
  details.append(renderLegs(j, entry, state, liveCtl));
  card.append(details);

  // --- Alle Tarife der DB ---
  const fares = renderFares(j, state, liveCtl);
  if (fares) card.append(fares);

  // --- Buchen: Shops der berührten Länder, Startland zuerst ---
  const shops = j.shops || [];
  if (shops.length > 0) {
    const box = el('div', 'shops');
    box.append(el('span', 'shops__label', 'Buchen bei'));

    for (const shop of shops) {
      const a = el('a', 'shops__link', shop.label);
      a.href = shop.url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer nofollow';
      if (!shop.prefilled) {
        a.classList.add('shops__link--manual');
        a.title = 'Öffnet die Suche — Orte und Datum müssen dort ggf. selbst eingetragen werden.';
        a.append(el('span', 'shops__hint', '*'));
      }
      box.append(a);
    }

    if (shops.some((s) => !s.prefilled)) {
      box.append(el('span', 'shops__note', '* nicht garantiert vorausgefüllt'));
    }
    card.append(box);
  }

  return card;
}

/**
 * Alle Tarife einer Verbindung, zum Aufklappen.
 *
 * Die Liste zeigt den günstigsten Preis - meist einen Super Sparpreis mit
 * Zugbindung, ohne dass das dasteht. Hier stehen die übrigen daneben, mit
 * ihren Bedingungen und dem Preis einer Sitzplatzreservierung. Geladen wird
 * erst beim Aufklappen: die DB-Antwort ist groß, und die meisten schauen es
 * sich für die meisten Verbindungen nie an.
 */
function renderFares(journey, state, actions) {
  if (!journey.dbRecon || !actions?.loadOffers) return null;

  const box = el('details', 'fares');
  const sum = el('summary', 'fares__summary');
  sum.append(el('span', 'fares__title', 'Tarife und Bedingungen'));
  sum.append(el('span', 'fares__hint', 'Spar-, Flexpreis, Reservierung'));
  box.append(sum);
  const body = el('div', 'fares__body', 'Lade Tarife …');
  box.append(body);

  let geladen = false;
  const laden = async () => {
    if (geladen) return;
    geladen = true;
    try {
      const res = await actions.loadOffers(journey);
      body.replaceChildren(...faresBody(res, state, journey));
    } catch (err) {
      geladen = false; // beim nächsten Aufklappen neu versuchen
      body.replaceChildren(el('p', 'fares__note',
        `Die DB liefert die Tarife gerade nicht (${err.message}). Zuklappen und wieder aufklappen versucht es erneut.`));
    }
  };

  box.open = Boolean(journey._faresOpen);
  box.addEventListener('toggle', () => {
    journey._faresOpen = box.open;
    if (box.open) laden();
  });
  if (box.open) laden();
  return box;
}

function faresBody(res, state, journey) {
  const out = [];
  const geld = (v, cur) => Number(v).toLocaleString('de-DE', { style: 'currency', currency: cur || 'EUR' });
  const fares = res?.fares || [];

  if (fares.length === 0) {
    out.push(el('p', 'fares__note', 'Für diese Verbindung nennt die DB keine Tarife — '
      + 'sie verkauft sie vermutlich nicht.'));
    return out;
  }

  // Die gewählte Klasse zuerst, die andere als Vergleich darunter.
  const erste = state.travelClass === 1 ? 1 : 2;
  for (const klasse of [erste, erste === 1 ? 2 : 1]) {
    const rows = fares.filter((f) => f.class === klasse);
    if (rows.length === 0) continue;
    out.push(el('p', 'fares__class', `${klasse}. Klasse`));
    const list = el('ul', 'fares__list');
    for (const f of rows) {
      const li = el('li', 'fares__row');
      li.append(el('span', 'fares__name', f.name));
      li.append(el('span', 'fares__price', geld(f.amount, f.currency)));
      const bed = (f.conditions || []).map((c) => c.text).filter(Boolean);
      if (bed.length) li.append(el('span', 'fares__cond', bed.join(' · ')));
      if (f.discountNote) li.append(el('span', 'fares__disc', f.discountNote));
      list.append(li);
    }
    out.push(list);
  }

  const seat = res.seat;
  if (seat) {
    const text = seat.included ? 'Sitzplatzreservierung ist inklusive.'
      : !seat.available ? 'Sitzplatzreservierung: ausgebucht oder nicht möglich.'
      : `Sitzplatz reservieren: ${geld(seat.amount, seat.currency)}`
        + (seat.required ? ' — Reservierungspflicht.' : '.');
    out.push(el('p', 'fares__seat', text));
  }

  // Was eine BahnCard hier brächte - die DB rechnet es selbst vor.
  const mitBc = (res.withBahnCard || []).filter((f) => f.class === erste);
  if (mitBc.length > 0) {
    const ab = Math.min(...mitBc.map((f) => f.amount));
    const probe = (res.bahncard || []).map((b) => `${b.name} ${geld(b.amount, b.currency)}`);
    out.push(el('p', 'fares__bc',
      `Mit BahnCard ab ${geld(ab, mitBc[0].currency)}`
      + (probe.length ? ` (zum Ausprobieren: ${probe.join(', ')})` : '') + '.'));
  }

  // Eigene Abos, die die DB nicht kennt, stecken in diesen Preisen nicht.
  const fremd = journey.price?.source === 'db+abo';
  out.push(el('p', 'fares__source',
    'Preise der DB für die ganze Verbindung, Stand jetzt. Spar- und Super Sparpreise sind '
    + 'kontingentiert und können beim Buchen schon weg sein.'
    + (fremd ? ' Halbtax, GA, Vorteilscard und KlimaTicket sind hier nicht eingerechnet.' : '')));
  return out;
}

/**
 * Eine Hinweiszeile, die auf zwei Zeilen gekürzt ist und sich per Tipp
 * ganz öffnet - für lange Fremdtexte wie Aufzugsmeldungen.
 */
function expandableNote(cls, label, text) {
  const n = el('div', cls);
  if (label) n.append(el('span', `${cls.split(' ')[0]}-label`, label));
  n.append(el('span', `${cls.split(' ')[0]}-text`, text));
  n.title = text;
  n.tabIndex = 0;
  n.setAttribute('role', 'button');
  n.setAttribute('aria-expanded', 'false');
  const umschalten = (e) => {
    e.stopPropagation(); // nicht zugleich die Verbindung auswählen
    const offen = n.classList.toggle('is-open');
    n.setAttribute('aria-expanded', String(offen));
  };
  n.addEventListener('click', umschalten);
  n.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); umschalten(e); }
  });
  return n;
}

/**
 * "Wenn du den Anschluss nicht kriegst": die nächsten Verbindungen ab dem
 * Umsteigebahnhof, jede davon übernehmbar.
 *
 * Wird von app.js nachgeladen, deshalb hier drei Zustände. Anklickbar sind
 * die Vorschläge, weil ein knapper Umstieg zwei Fragen aufwirft: was
 * passiert, wenn ich ihn verpasse — und will ich das Risiko überhaupt
 * eingehen. Die zweite beantwortet man nur, wenn man die Alternative auch
 * nehmen kann, ohne neu zu suchen.
 */
function renderFallback(journey, leg, actions) {
  if (!leg.fallbackState) return null;

  // Beim Ausfall ist der Ersatz die Hauptsache, nicht die Absicherung —
  // deshalb andere Worte und eine eigene, deutlichere Kennzeichnung.
  const ausfall = Boolean(leg.cancelled);

  if (leg.fallbackState === 'loading') {
    return el('div', 'leg__fallback leg__fallback--pending',
      ausfall ? 'Suche Ersatz …' : 'Suche spätere Anschlüsse …');
  }

  const options = leg.fallbacks || [];
  if (options.length === 0) {
    return el('div', 'leg__fallback leg__fallback--none', ausfall
      ? 'Kein Ersatz gefunden. Neu suchen mit späterer Abfahrt hilft vielleicht weiter.'
      : 'Kein späterer Anschluss gefunden — diese Verbindung hängt am Umstieg.');
  }

  const box = el('div', 'leg__fallback' + (ausfall ? ' leg__fallback--ersatz' : ''));
  box.append(el('span', 'leg__fallback-label', ausfall ? 'Ersatz' : 'Stattdessen'));

  const list = el('div', 'leg__fallback-list');
  for (const f of options) {
    const parts = [];
    // Aus den Abschnitten beschriften, mit derselben Regel wie überall sonst
    // (trainLabel). Die Beschriftung des Servers kennt die Gattungsauflösung
    // nicht und schrieb "DB S5" für die Münchner S-Bahn.
    const zuege = (f.legs || []).filter((l) => l.mode === 'train').map(trainLabel);
    const namen = zuege.length ? zuege : (f.trains || []);
    if (namen.length) parts.push(namen.join(' · '));
    if (typeof f.changes === 'number') {
      parts.push(f.changes === 0 ? 'direkt' : `${f.changes} Umstieg${f.changes > 1 ? 'e' : ''}`);
    }

    const btn = el('button', 'leg__alt');
    btn.type = 'button';
    btn.append(el('span', 'leg__alt-times',
      `${formatTime(f.departure)} → ${formatTime(f.arrival)}`));
    btn.append(el('span', 'leg__alt-meta', parts.join(' · ')));
    // Überbrückt nur das ausgefallene Stück, der Rest der Reise bleibt —
    // das ist die gute Nachricht und gehört dazugesagt.
    if (f.bridged) btn.append(el('span', 'leg__alt-note', 'weiter wie geplant'));

    // Wie viel später man ankommt, ist die eigentlich interessante Zahl.
    const lost = lateBy(leg.journeyArrival, f.arrival);
    if (lost != null && lost > 0) {
      btn.append(el('span', 'leg__alt-lost', `+${formatDuration(lost)}`));
    }
    btn.append(el('span', 'leg__alt-take', 'übernehmen'));

    btn.addEventListener('click', (e) => {
      e.stopPropagation();  // nicht zugleich die Karte auswählen
      actions?.takeAlternative?.(journey, leg, f);
    });
    list.append(btn);
  }

  box.append(list);
  return box;
}

// ---------------------------------------------------------------------
// Umstiegsplan
// ---------------------------------------------------------------------

/**
 * "Wo liegt das Anschlussgleis?"
 *
 * Bei einem knappen Umstieg ist das die eigentliche Frage — die Gleisnummer
 * allein sagt nichts darüber, ob man zwanzig Meter weiter oder ans andere
 * Ende der Halle muss.
 *
 * AN JEDEM UMSTIEG, nicht nur an den knappen, und AUCH OHNE GLEISNUMMERN.
 * Vorher galten beide Bedingungen zugleich, und damit fiel der Plan bei den
 * allermeisten Umstiegen aus: knapp ist nur eine Minderheit, und ob der
 * Fahrplan Gleise mitliefert, hängt am Bahnhof und am Betreiber. Sind die
 * Nummern bekannt, sind die beiden Bahnsteige hervorgehoben; sind sie es
 * nicht, zeigt der Plan den Bahnhof mit allen erfassten Gleisen — auch das
 * beantwortet "ein Bahnsteig oder eine halbe Halle".
 *
 * Die Bahnsteiglage kommt aus OpenStreetMap und wird erst geladen, wenn
 * jemand aufklappt.
 */
function renderTransferPlan(journey, leg, actions) {
  const legs = journey.legs || [];
  const at = legs.indexOf(leg);
  const prev = [...legs.slice(0, at)].reverse().find((l) => l.mode === 'train');
  if (!prev) return null;

  // KEIN GLEISPLAN FÜR EINEN BUSBAHNHOF. Der Plan zeigt Bahnsteige aus
  // OpenStreetMap — an einer Bushaltestelle gibt es die nicht, und was der
  // Umkreis stattdessen einfängt, ist der nächstgelegene Bahnhof. Für den
  // Fernbus am „München ZOB (Hackerbrücke)" kamen so die Gleise 5–36 des
  // Hauptbahnhofs heraus, 600 m weiter: ein Plan, der eine ganz andere
  // Station zeigt und nichts davon sagt.
  if ([prev, leg].some((l) => typeOf(l).label === 'Bus')) return null;

  const from = prev.to?.platform || '';
  const to = leg.from?.platform || '';
  const lat = leg.from?.lat;
  const lon = leg.from?.lon;
  if (lat == null || lon == null || !actions?.loadPlatforms) return null;

  const box = el('details', 'xfer');
  const sum = el('summary', 'xfer__summary');
  sum.append(el('span', 'xfer__tracks',
    from && to ? `Gleis ${from} → Gleis ${to}` : leg.from?.name || 'Umsteigebahnhof'));
  sum.append(el('span', 'xfer__hint', 'Lageplan'));
  box.append(sum);

  const body = el('div', 'xfer__body', 'Lade Bahnsteige …');
  box.append(body);

  // Darunter die Wagenreihung beider Züge - eigener Kasten, damit das
  // Nachladen des Plans ihn nicht überschreibt.
  const wagen = el('div', 'wagen');
  box.append(wagen);
  let wagenGeladen = false;
  const ladeWagen = async () => {
    if (wagenGeladen || !actions.loadSequence) return;
    wagenGeladen = true;
    const fern = (l) => /^(ICE|IC|EC|ECE)$/i.test(String(l.category || '').trim()) && l.trainNumber;
    const fragen = [
      ['Ankunft', prev, prev.to?.id, prev.arrival],
      ['Abfahrt', leg, leg.from?.id, leg.departure],
    ].filter(([, l, eva, t]) => fern(l) && eva && t && String(eva).startsWith('80'));
    if (fragen.length === 0) return;
    const antworten = await Promise.all(fragen.map(([, l, eva, t]) => actions.loadSequence({
      eva: String(eva), cat: String(l.category).trim().toUpperCase(), num: String(l.trainNumber), time: t,
    }).catch(() => null)));
    const teile = [];
    fragen.forEach(([rolle, l], i) => {
      const seq = antworten[i]?.sequence;
      if (seq?.vehicles?.length) teile.push(renderSequence(rolle, l, seq));
    });
    if (teile.length === 0) return;
    wagen.replaceChildren(
      el('p', 'wagen__title', 'Wagenreihung'),
      ...teile,
      el('p', 'wagen__source', 'Von der DB, nur für deutschen Fernverkehr am Reisetag. Sektor A ist links.'),
    );
  };

  // WIEDERHOLEN, statt den Fehler stehen zu lassen.
  //
  // Overpass ist ein Gemeinschaftsdienst und stellt Anfragen bei Last in eine
  // Warteschlange; eine einzelne Abfrage geht dabei regelmäßig verloren.
  // Vorher wurde `geladen` gesetzt, BEVOR die Antwort da war — schlug sie
  // fehl, tat erneutes Aufklappen nichts mehr, und der Rat „später noch
  // einmal aufklappen" ging ins Leere. Jetzt gilt ein Versuch erst als
  // erledigt, wenn er etwas geliefert hat, und zwei Wiederholungen mit
  // wachsendem Abstand laufen von selbst.
  const VERSUCHE = 3;
  let geladen = false;
  let inArbeit = false;

  const laden = async () => {
    if (geladen || inArbeit) return;
    inArbeit = true;
    try {
      for (let versuch = 1; versuch <= VERSUCHE; versuch++) {
        const res = await actions.loadPlatforms(lat, lon, String(from), String(to));

        // Wiederholt wird nur, wenn der DIENST gepatzt hat. Eine gültige
        // Antwort ohne Bahnsteige heißt "dieser Bahnhof ist in OSM nicht
        // erfasst" — die wird beim zweiten Fragen nicht anders, und Overpass
        // ist ein Gemeinschaftsdienst.
        const nochmal = !!res?.error;
        if (!nochmal || versuch === VERSUCHE) {
          geladen = !nochmal;
          body.replaceChildren();
          body.append(...transferPlanBody(res, from, to, leg.from?.name));
          return;
        }

        // Zwischenstand, damit nicht minutenlang „Lade Bahnsteige …" steht,
        // ohne dass sich etwas rührt.
        body.replaceChildren(el('p', 'xfer__note',
          `Der OpenStreetMap-Dienst antwortet gerade nicht — Versuch ${versuch + 1} von ${VERSUCHE} …`));
        await new Promise((r) => setTimeout(r, versuch * 4000));

        // Zwischendurch zugeklappt: dann nicht weiter im Hintergrund fragen.
        if (!box.open) return;
      }
    } finally {
      inArbeit = false;
    }
  };

  // Offen bleibt offen, auch wenn die Liste neu gezeichnet wird.
  box.open = Boolean(leg._xferOpen);
  box.addEventListener('toggle', () => {
    leg._xferOpen = box.open;
    if (box.open) { laden(); ladeWagen(); }
  });
  if (box.open) { laden(); ladeWagen(); }

  return box;
}

/**
 * Sektoren als kurze Angabe: ["A","B","C","E"] → "A–C, E".
 *
 * @param {string[]} liste  Sektoren, in beliebiger Reihenfolge, mit Doppelten
 * @param {object[]} alle   die Sektoren des Bahnsteigs, in Lage-Reihenfolge
 */
export function sectorRange(liste, alle) {
  const reihe = (alle || []).map((s) => s.name);
  const idx = [...new Set(liste.filter(Boolean))]
    .map((n) => reihe.indexOf(n)).filter((i) => i >= 0).sort((a, b) => a - b);
  if (idx.length === 0) return '';
  const teile = [];
  let von = idx[0];
  let bis = idx[0];
  for (const i of [...idx.slice(1), Infinity]) {
    if (i === bis + 1) { bis = i; continue; }
    teile.push(von === bis ? reihe[von] : `${reihe[von]}–${reihe[bis]}`);
    von = i;
    bis = i;
  }
  return teile.join(', ');
}

/**
 * Ein Zug am Bahnsteig: Sektoren oben, Wagen darunter, maßstäblich.
 *
 * Darunter in Worten, was man beim Umsteigen wissen will: wo die 1. Klasse
 * hält, wo das Bordrestaurant, wo Fahrräder mitdürfen - und ob der Zug
 * GETEILT wird. Ein Flügelzug mit zwei Zielen ist die Falle, in die man
 * sonst tappt: vorn nach Wien, hinten nach Innsbruck.
 */
function renderSequence(rolle, leg, seq) {
  const box = el('div', 'wagen__zug');
  box.append(el('p', 'wagen__head',
    `${rolle} ${trainLabel(leg)}${seq.platform ? ` · Gleis ${seq.platform}` : ''}`));

  const len = seq.length || Math.max(...seq.vehicles.map((v) => v.end));
  const pct = (m) => `${Math.max(0, Math.min(100, (m / len) * 100)).toFixed(2)}%`;
  const bar = el('div', 'wagen__bar');
  for (const s of seq.sectors || []) {
    const d = el('span', 'wagen__sector', s.name);
    d.style.left = pct(s.start);
    d.style.width = pct(s.end - s.start);
    bar.append(d);
  }
  for (const v of seq.vehicles) {
    const cls = ['wagen__car'];
    if (v.loco) cls.push('is-loco');
    else if (v.first && !v.second) cls.push('is-first');
    else if (v.first) cls.push('is-mixed');
    if (v.dining) cls.push('is-dining');
    if (v.closed) cls.push('is-closed');
    const c = el('span', cls.join(' '), v.loco ? '' : v.n);
    c.style.left = pct(v.start);
    c.style.width = pct(v.end - v.start);
    c.title = v.loco ? 'Triebkopf / Lok'
      : [`Wagen ${v.n}`, v.first && v.second ? '1./2. Klasse' : v.first ? '1. Klasse' : '2. Klasse',
        v.dining ? 'Bordrestaurant' : null, v.bike ? 'Fahrradstellplätze' : null,
        v.wheelchair ? 'Rollstuhlplatz' : null, v.closed ? 'geschlossen' : null,
        v.sector ? `Sektor ${v.sector}` : null].filter(Boolean).join(' · ');
    bar.append(c);
  }
  box.append(bar);

  const sek = (pred) => sectorRange(seq.vehicles.filter(pred).map((v) => v.sector), seq.sectors);
  const teile = [];
  const erste = sek((v) => v.first);
  if (erste) teile.push(`1. Klasse: Sektor ${erste}`);
  const bistro = sek((v) => v.dining);
  if (bistro) teile.push(`Bordrestaurant: ${bistro}`);
  const rad = sek((v) => v.bike);
  if (rad) teile.push(`Fahrräder: ${rad}`);

  // Zugteilung: verschiedene Ziele in einem Zug.
  const ziele = [...new Set((seq.trains || []).map((t) => t.destination).filter(Boolean))];
  if (ziele.length > 1) {
    const je = seq.trains.map((t, gi) => {
      const r = sek((v) => v.group === gi);
      return r ? `${t.destination} Sektor ${r}` : null;
    }).filter(Boolean);
    box.append(el('p', 'wagen__split', `Zug wird geteilt — ${je.join(', ')}. Im richtigen Teil einsteigen.`));
  }
  if (teile.length) box.append(el('p', 'wagen__note', teile.join(' · ')));
  return box;
}

/** Inhalt des Umstiegsplans: der Bahnhof aus OpenStreetMap, oder eine Erklärung. */
function transferPlanBody(res, fromTrack, toTrack, stationName) {
  const platforms = res?.platforms || [];

  const find = (track) => (track
    ? platforms.find((p) => (p.tracks || []).some((t) => String(t) === String(track)))
    : null) || null;

  const a = find(fromTrack);
  const b = find(toTrack);

  // Ganz ohne Bahnsteige gibt es nichts zu zeigen. Die drei Gründe dafür
  // verlangen Verschiedenes vom Leser: der Dienst war überlastet — gleich
  // nochmal aufklappen; der Bahnhof ist nicht kartiert — nichts zu machen.
  if (platforms.length === 0) {
    const p = el('p', 'xfer__note');
    p.textContent = res?.error
      ? 'Der Bahnhofsplan lässt sich gerade nicht laden — der OpenStreetMap-Dienst '
        + 'ist überlastet. Zuklappen und wieder aufklappen versucht es erneut.'
      : `Für ${stationName || 'diesen Bahnhof'} sind in OpenStreetMap keine `
        + 'nummerierten Bahnsteige erfasst — die Lage lässt sich daher nicht bestimmen.';
    return [p];
  }

  const out = [];
  const line = el('p', 'xfer__note');

  // WAS DIE ZEILE SAGT, hängt davon ab, wie viel wir wissen. Sie sagt
  // ausdrücklich NICHT, wie weit es ist: der genaue Laufweg wurde aus den
  // OSM-Fußwegen gerechnet und setzte einen innen vollständig kartierten
  // Bahnhof voraus — den gibt es fast nirgends, und die Meter- und
  // Minutenangaben waren dadurch genauer, als sie sein konnten. Wie weit die
  // beiden Bahnsteige auseinanderliegen, zeigt die Karte darunter.
  if (res?.samePlatform) {
    // Auch hier die Karte: „gegenüber" ist eine gute Nachricht, aber man
    // will trotzdem sehen, wo im Bahnhof man steht — und an einem
    // Bahnsteig mit vier Abschnitten ist „gegenüber" auch nicht überall
    // dasselbe. Früher entfiel die Karte in genau diesem Fall.
    line.textContent = 'Gleis gegenüber am selben Bahnsteig — nur die Seite wechseln.';
  } else if (a && b) {
    line.textContent = 'Ankunftsgleis blau, Abfahrtsgleis grün — die Karte zeigt, '
      + 'wo beide liegen.';
  } else if (a || b) {
    // Eines von beiden ist da. Warum das andere fehlt, macht einen
    // Unterschied: nennt der Fahrplan keine Nummer, ist nichts zu machen;
    // kennt OpenStreetMap sie nicht, weiß man wenigstens, woran es liegt.
    const bekannt = a ? `Ankunftsgleis ${fromTrack}` : `Abfahrtsgleis ${toTrack}`;
    const fehlt = a ? toTrack : fromTrack;
    line.textContent = fehlt
      ? `Hervorgehoben ist nur das ${bekannt} — Gleis ${fehlt} kennt OpenStreetMap hier nicht.`
      : `Hervorgehoben ist nur das ${bekannt} — das andere nennt der Fahrplan nicht.`;
  } else if (!fromTrack || !toTrack) {
    line.textContent = 'Der Fahrplan nennt für diesen Umstieg keine Gleisnummern. '
      + `Gezeigt sind die Bahnsteige, die OpenStreetMap für ${stationName || 'den Bahnhof'} kennt.`;
  } else {
    line.textContent = `In OpenStreetMap fehlen für ${stationName || 'diesen Bahnhof'} die Nummern `
      + `von Gleis ${fromTrack} bzw. ${toTrack}. Bekannt sind nur: `
      + platforms.map((x) => x.tracks.join('/')).slice(0, 8).join(', ') + '.';
  }

  // Geschätzte Lage kenntlich machen — sie stammt aus den Nachbargleisen,
  // nicht aus OpenStreetMap selbst.
  const unsichereLage = [a, b].filter((p) => p?.estimated).map((p) => p.tracks.join('/'));
  if (unsichereLage.length) {
    line.append(el('span', 'xfer__level',
      ` Die Lage von Gleis ${unsichereLage.join(' und ')} ist aus den Nachbargleisen geschätzt.`));
  }

  // Ein Ebenenwechsel kostet mehr Zeit, als die Entfernung vermuten lässt.
  if (a?.level != null && b?.level != null && a.level !== b.level) {
    line.append(el('span', 'xfer__level', ' Dazu ein Ebenenwechsel.'));
  }
  out.push(line);

  // EINE KARTE, keine Skizze.
  //
  // Vorher war das ein SVG mit ein paar Balken darauf: maßstäblich zwar,
  // aber ohne Bezug zu irgendetwas, nicht zoombar, und ein Umstieg über
  // mehrere Ebenen lag darin als ein Strich übereinander. Jetzt dieselbe
  // Karte wie überall sonst — Kacheln als Untergrund, ziehen und zoomen,
  // und ein Umschalter für die Ebene.
  const mapEl = el('div', 'map xfer__map');
  out.push(mapEl);

  // Erst anhängen, dann bauen: die Karte misst ihren Kasten aus, und der ist
  // außerhalb des Dokuments null Pixel groß.
  queueMicrotask(() => {
    if (!mapEl.isConnected) return;
    const map = new RouteMap(mapEl, { mode: 'station' });
    map.build();
    map.setStation({
      platforms,
      from: a,
      to: b,
      // Die Gleisnummern gehen mit: die Markierung soll auf dem GLEIS sitzen,
      // nicht in der Mitte des Bahnsteigs. Bei einem Bahnsteig zwischen zwei
      // Gleisen läge sie sonst für beide an derselben Stelle.
      fromTrack: String(fromTrack || ''),
      toTrack: String(toTrack || ''),
      trackPoints: res?.trackPoints || {},
      connectors: res?.connectors || [],
    });
  });

  // Legende, sobald Treppen & Co. im Plan stehen.
  if ((res?.connectors || []).length > 0) {
    const leg = el('p', 'xfer__legend');
    const item = (cls, zeichen, text) => {
      const s = el('span', `xfer__legend-item ${cls}`);
      s.append(el('span', 'xfer__legend-sym', zeichen), document.createTextNode(text));
      return s;
    };
    leg.append(
      item('is-steps', '┅', 'Treppe'),
      item('is-escalator', '➔', 'Rolltreppe, Pfeil = Fahrtrichtung'),
      item('is-elevator', '⇅', 'Aufzug'),
      el('span', 'xfer__legend-note', 'Die Zahl daneben: die Ebene, zu der es führt.'),
    );
    out.push(leg);
  }

  out.push(el('p', 'xfer__source',
    (res?.connectors || []).length > 0
      ? 'Bahnhofsplan aus OpenStreetMap: Bahnsteige, Treppen, Rolltreppen und Aufzüge. '
        + 'Ein berechneter Laufweg ist es nicht — die Gänge dazwischen sind in OSM zu lückenhaft.'
      : 'Bahnhofsplan aus OpenStreetMap. Gezeigt ist die Lage der Bahnsteige, nicht '
        + 'der Weg dorthin — den findet man im Bahnhof besser als jede Karte.'));
  return out;
}

/** Differenz zweier ISO-Zeitpunkte in Minuten, null wenn unbekannt. */
function lateBy(plannedIso, actualIso) {
  const a = Date.parse(plannedIso || '');
  const b = Date.parse(actualIso || '');
  if (Number.isNaN(a) || Number.isNaN(b)) return null;
  return Math.round((b - a) / 60000);
}

/** Uhrzeit eines Abschnitts, bei Abweichung Plan durchgestrichen plus Ist. */
function appendLegTime(line, plan, real) {
  const p = formatTime(plan);
  const r = real ? formatTime(real) : null;
  if (!r || r === p) {
    line.append(el('span', 'leg__time', p));
    return;
  }
  line.append(el('span', 'leg__time leg__time--planned', p));
  line.append(el('span', 'leg__time leg__time--real', r));
}

function renderLegs(journey, entry, state, actions) {
  const wrap = el('div', 'legs');

  // Aufzugs- und Barrierefreiheitsmeldungen am Ein- und Ausstieg. Ein
  // Umsteigebahnhof ist Ausstieg des einen und Einstieg des nächsten Zuges -
  // die Meldung soll trotzdem nur einmal dastehen.
  const gezeigt = new Set();
  const zugang = (leg, at) => (leg.stationNotes || [])
    .filter((n) => n.at === at && n.text && !gezeigt.has(n.text))
    .map((n) => {
      gezeigt.add(n.text);
      return expandableNote('leg__access', 'Zugang', n.text);
    });

  for (const leg of journey.legs) {
    // Für den Vergleich "wie viel später komme ich an" in renderFallback.
    leg.journeyArrival = journey.arrival;
    if (leg.mode === 'walk') {
      // Wechselt der Halt, läuft man wirklich ein Stück — das gehört
      // sichtbar gemacht, samt Start und Ziel des Fußwegs.
      const walksBetween = leg.changesPlace && leg.from?.name && leg.to?.name;
      if (!walksBetween && (leg.durationMin || 0) < 1) continue;

      const text = walksBetween
        ? `Zu Fuß: ${leg.from.name} → ${leg.to.name} · ${formatDuration(leg.durationMin)}`
        : `Umstieg am selben Halt · ${formatDuration(leg.durationMin)}`;

      const row = el('div', 'leg leg--walk');
      if (walksBetween) row.classList.add('leg--walk-between');
      row.append(el('span', 'leg__walk-text', text));
      wrap.append(row);
      continue;
    }

    const type = typeOf(leg);
    const row = el('div', 'leg');
    if (leg.cancelled) {
      row.classList.add('leg--cancelled');
      row.append(el('div', 'leg__cancelled', 'Dieser Zug fällt aus.'));
      // Gleich darunter, was man stattdessen nimmt — übernehmbar.
      const ersatz = renderFallback(journey, leg, actions);
      if (ersatz) row.append(ersatz);
    }

    // Umsteigezeit vor diesem Zug, wenn sie knapp ist.
    const knapp = leg.transferRisk && leg.transferRisk !== 'ok';
    if (knapp) {
      const t = el('div', `leg__transfer leg__transfer--${leg.transferRisk}`,
        leg.transferRisk === 'risky'
          ? `Nur ${leg.transferMin} min zum Umsteigen — bei Verspätung weg`
          : `${leg.transferMin} min zum Umsteigen — knapp`);
      row.append(t);

      // Bei sehr knappen Umstiegen die Alternativen gleich mitliefern:
      // die Frage ist nicht nur, ob man es schafft, sondern auch, ob man
      // lieber gleich anders fährt. Fällt der Zug ohnehin aus, steht der
      // Ersatz schon oben — nicht zweimal.
      const fb = leg.cancelled ? null : renderFallback(journey, leg, actions);
      if (fb) row.append(fb);
    }

    // Lageplan des Umsteigebahnhofs — an JEDEM Umstieg, nicht nur an den
    // knappen. Auch bei zwanzig Minuten will man wissen, ob man quer durch
    // den Bahnhof muss; und `transferMin` steht an jedem Abschnitt, vor dem
    // ein anderer Zug lag. Zugeklappt kostet er nichts: geladen wird erst
    // beim Aufklappen.
    if (leg.transferMin != null) {
      const plan = renderTransferPlan(journey, leg, actions);
      if (plan) row.append(plan);
    }

    const line1 = el('div', 'leg__line');
    appendLegTime(line1, leg.departure, leg.departureReal);
    line1.append(el('span', 'leg__station', leg.from?.name || '?'));
    if (leg.from?.platform) line1.append(el('span', 'leg__platform', `Gl. ${leg.from.platform}`));
    row.append(line1);
    row.append(...zugang(leg, 'from'));

    const info = el('div', 'leg__train');
    info.append(el('span', 'leg__cat', trainLabel(leg)));
    info.append(el('span', 'leg__long', type.long));
    info.append(el('span', 'leg__dur', formatDuration(leg.durationMin)));

    const comfortEntry = entry.comfortPerLeg.find((c) => c.leg === leg);

    // Fahrzeugmodell, wenn bekannt — mit Angabe, woher wir es wissen.
    // Vier Wege dorthin, und sie sind verschieden sicher: nachgesehen,
    // aus früheren Fahrten erinnert, aus der Strecke geschlossen, aus der
    // Gattung geschlossen. Das gehört dazugesagt.
    if (comfortEntry?.model) {
      const m = el('span', 'leg__model', comfortEntry.model.label);
      m.title = {
        series: `Aus der Wagenreihung ermittelt (${leg.seriesName || 'BR ' + leg.series}).`,
        learned: `Dieser Zug fuhr zuletzt als ${leg.seriesName || 'BR ' + leg.series}`
          + (leg.seriesLearned ? ` (vor ${leg.seriesLearned} Tagen beobachtet).` : '.')
          + ' Umläufe ändern sich gelegentlich.',
        route: comfortEntry.note || 'Auf dieser Strecke verkehrt nur dieses Fahrzeug.',
        sole: 'Diese Gattung verkehrt nur mit diesem Fahrzeug.',
      }[comfortEntry.certainty] || '';
      if (comfortEntry.certainty !== 'series') m.classList.add('leg__model--inferred');
      info.append(m);
    } else if (leg.seriesName || leg.series) {
      info.append(el('span', 'leg__series', leg.seriesName || `Baureihe ${leg.series}`));
    }

    // Auslastung dieses Abschnitts.
    if (leg.occupancy) {
      const v = state.travelClass === 1 ? leg.occupancy.first : leg.occupancy.second;
      if (typeof v === 'number' && v > 0) {
        const o = el('span', `leg__occ leg__occ--${v}`, OCCUPANCY_LABELS[v] || `Stufe ${v}`);
        info.append(o);
      }
    }

    if (state.mode === 'nerd' && comfortEntry) {
      info.append(el('span', 'leg__comfort', `Komfort ${comfortEntry.comfort}`));
    }
    row.append(info);

    // Ausstattung laut DB. Was die Fahrt verhindern kann (Reservierungs-
    // pflicht, DB-Fahrscheine gelten nicht), steht zuerst und farbig.
    const amen = [...(leg.amenities || [])].sort((a, b) => Number(b.important) - Number(a.important));
    if (amen.length > 0) {
      const box = el('div', 'leg__amenities');
      for (const a of amen) {
        box.append(el('span', 'leg__amenity' + (a.important ? ' leg__amenity--warn' : ''), a.label));
      }
      row.append(box);
    }

    if (leg.dTicket) {
      row.append(el('div', 'leg__stops-count', leg.dTicket));
    }

    // Warum der Zug spät ist, sagt die DB oft dazu.
    for (const note of leg.remarks || []) {
      row.append(el('div', 'leg__remark', note));
    }

    // Zwischenhalte: im Nerd-Mode alle mit Uhrzeit, sonst nur die Anzahl.
    // Die Zeiten stehen hier nicht zur Zierde: zeitlich begrenzte Abos wie das
    // GA Night gelten je Teilstück, ein ECE ab München 17:03 ist in der
    // Schweiz also trotzdem im Fenster.
    const stops = leg.stops || [];
    if (stops.length > 2) {
      const mid = stops.slice(1, -1);
      if (state.mode === 'nerd') {
        const list = el('div', 'leg__stops');
        for (const s of mid) {
          const item = el('span', 'leg__stop');
          const time = formatTime(s.arrival || s.departure);
          if (time !== '--:--') item.append(el('span', 'leg__stop-time', time));
          item.append(el('span', 'leg__stop-name', s.name));
          if (s.country) item.dataset.country = s.country;
          item.title = `${time} · ${(s.country || '').toUpperCase()}`;
          list.append(item);
        }
        row.append(list);
      } else {
        row.append(el('div', 'leg__stops-count', `${mid.length} Zwischenhalte`));
      }
    }

    const line2 = el('div', 'leg__line');
    appendLegTime(line2, leg.arrival, leg.arrivalReal);
    line2.append(el('span', 'leg__station', leg.to?.name || '?'));
    if (leg.to?.platform) line2.append(el('span', 'leg__platform', `Gl. ${leg.to.platform}`));
    row.append(line2);
    row.append(...zugang(leg, 'to'));

    wrap.append(row);
  }

  // Preisherleitung transparent machen - vor allem bei Schätzungen wichtig.
  const price = journey.price;
  if (price && price.estimated && price.perCountry) {
    const parts = Object.entries(price.perCountry)
      .map(([c, km]) => `${c.toUpperCase()} ${km} km`)
      .join(' · ');
    const abos = price.appliedAbos?.length
      ? `Angewendete Abos: ${price.appliedAbos.join(', ')}.`
      : 'Ohne Abo.';

    const text = price.basedOnReal
      ? `Echtpreis der DB: ${price.amountBase?.toFixed(2).replace('.', ',')} €. ` +
        `Darauf ist der Abo-Rabatt hochgerechnet, weil die DB nur BahnCards kennt. ` +
        `Grundlage: ${price.distanceKm} km (${parts}). ${abos} ` +
        `Verbindlich ist der Preis im Ticketshop.`
      : `Schätzung auf Basis von ${price.distanceKm} km (${parts}). ${abos} ` +
        `Kein Echtpreis verfügbar — verbindlich ist der Ticketshop.`;

    wrap.append(el('p', 'estimate-note', text));
  }

  // Pünktlichkeitshistorie: eigene Messungen und/oder Betreiber-Baseline.
  // Die Quelle wird immer klar mit angegeben, damit ein Baseline-Wert
  // nicht mit eigenen Messungen verwechselt wird.
  const hist = journey.history;
  if (hist && Object.keys(hist).length > 0) {
    const num = (v) => (typeof v === 'number' ? v.toFixed(1).replace('.', ',') : '?');
    const parts = Object.entries(hist).map(([train, h]) => {
      const percent = Math.round((h.rate ?? 0) * 100);
      const recent = h.samples7d > 0
        ? ` · letzte 7 Tage Ø +${num(h.avg7d)} min (${h.samples7d})`
        : '';
      const own = h.samples > 0 ? ` · ${h.samples} eigene` : '';
      return `${train}: ${percent} % pünktlich, Ø +${num(h.avg)} min${own}${recent}`;
    });

    const sources = new Set(Object.values(hist).map((h) => h.source));
    let label;
    if (sources.size === 1 && sources.has('own')) {
      label = 'Pünktlichkeit aus eigenen Messungen';
    } else if (sources.size === 1 && sources.has('baseline')) {
      label = 'Pünktlichkeit — Näherung aus Betreiber-Jahresstatistik (noch keine eigenen Messungen)';
    } else {
      label = 'Pünktlichkeit — eigene Messungen ergänzt um Betreiber-Statistik';
    }
    wrap.append(el('p', 'estimate-note', label + ' — ' + parts.join(' · ')));
  }

  // Welche Strecken diese Verbindung befährt — die Grundlage der
  // Gruppierung, deshalb gehört sie sichtbar an die Verbindung.
  if (state.mode === 'nerd' && entry.routes?.length > 0) {
    const box = el('div', 'route-hits');
    box.append(el('span', 'route-hits__label', 'Strecken'));
    for (const hit of entry.routes) {
      const tag = el('span', 'route-hits__item', hit.route.label);
      // Geschätzte Strecken sind als solche gekennzeichnet: "ø" steht für
      // Reisegeschwindigkeit inklusive Halten, nicht für die zulässige
      // Höchstgeschwindigkeit der gepflegten Einträge.
      if (hit.route.auto) tag.classList.add('route-hits__item--auto');
      tag.append(el('span', 'route-hits__speed',
        hit.route.auto ? `ø ${hit.route.speed} km/h` : `${hit.route.speed} km/h`));
      tag.title = [
        hit.route.note,
        `Rund ${Math.round(hit.share * 100)} % der Fahrzeit.`,
      ].filter(Boolean).join(' ');
      box.append(tag);
    }
    wrap.append(box);
  }

  return wrap;
}

/** Statusmeldung oben in der Ergebnisliste. */
export function renderNotices(container, notices, priceSource) {
  container.replaceChildren();
  if (!notices || notices.length === 0) {
    if (priceSource !== 'estimate') return;
  }

  if (priceSource === 'estimate') {
    const n = el(
      'div',
      'notice notice--warn',
      'Keine Echtpreise verfügbar — alle Preise sind Schätzungen mit Spanne. ' +
        'Fahrplan, Züge und Umstiege sind davon nicht betroffen.'
    );
    container.append(n);
  }

  for (const text of notices || []) {
    if (!text) continue;
    container.append(el('div', 'notice', text));
  }
}
