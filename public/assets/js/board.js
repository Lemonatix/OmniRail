/**
 * Abfahrtstafel - eigener Tab neben der Verbindungssuche.
 *
 * WOZU: Wer gestrandet ist oder einfach am Bahnhof steht, fragt nicht
 * "wie komme ich nach X", sondern "was fährt hier als Nächstes". Die
 * Verbindungssuche beantwortet das nur umständlich.
 *
 * Quelle ist HAFAS (jeder Bahnhof in CH, DE, AT), in München ergänzt um die
 * MVG: U-Bahn, Tram und Bus, und Echtzeit auch für die S-Bahn. Ein Tipp auf
 * eine Zeile lädt den Zuglauf - welche Halte noch kommen, mit Ist-Zeiten.
 *
 * Die Tafel frischt sich jede Minute auf, solange sie sichtbar ist und
 * "jetzt" zeigt.
 */

import { api } from './api.js';
import { setupAutocomplete, renderFavoriteChips } from './autocomplete.js';
import { places } from './favorites.js';
import { trainLabel } from './map.js';
import { typeOf } from './data/trains.js';

const BOARD_KEY = 'train-maxxing:board';
const REFRESH_MS = 60_000;
/** So weit reicht eine Seite der Tafel. "Später" hängt die nächste an. */
const WINDOW_MIN = 60;

const GROUPS = [
  { id: 'all', label: 'Alle' },
  { id: 'fern', label: 'Fernverkehr' },
  { id: 'regio', label: 'Regional' },
  { id: 'S', label: 'S-Bahn' },
  { id: 'U', label: 'U-Bahn' },
  { id: 'Tram', label: 'Tram' },
  { id: 'Bus', label: 'Bus' },
];

const el = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

const hhmm = (iso) => {
  const d = new Date(iso || '');
  return Number.isNaN(d.getTime())
    ? '--:--'
    : d.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
};

const pad = (n) => String(n).padStart(2, '0');
const localDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const localTime = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;

/** Zu welcher Filtergruppe gehört ein Eintrag? */
export function groupOf(entry) {
  const t = typeOf({ ...entry, mode: 'train' });
  if (t.longDistance) return 'fern';
  if (['S', 'U', 'Tram', 'Bus'].includes(t.label)) return t.label;
  return 'regio';
}

function load() {
  try { return JSON.parse(localStorage.getItem(BOARD_KEY) || '{}') || {}; } catch { return {}; }
}

function save(s) {
  try {
    localStorage.setItem(BOARD_KEY, JSON.stringify({
      station: s.station, arrivals: s.arrivals, group: s.group,
    }));
  } catch { /* privat */ }
}

/**
 * @param {HTMLElement} root  der Tab-Inhalt (#view-board)
 * @returns {{ activate: () => void, deactivate: () => void }}
 */
export function initBoard(root) {
  const $ = (sel) => root.querySelector(sel);
  const saved = load();
  const s = {
    station: saved.station || null,
    arrivals: Boolean(saved.arrivals),
    group: saved.group || 'all',
    time: null,          // null = jetzt
    entries: [],
    until: null,         // Ende des geladenen Zeitfensters (ISO)
    loading: false,
    error: null,
    active: false,
    open: new Set(),     // aufgeklappte Zeilen (Schlüssel)
    details: new Map(),  // jid -> Promise<Zuglauf>
    timer: null,
    abort: null,
  };

  const input = $('#board-station');
  const star = $('#board-star');

  // --- Bahnhof -------------------------------------------------------

  const setStation = (loc) => {
    s.station = loc;
    input.value = loc?.name || '';
    places.remember(loc);
    s.open.clear();
    save(s);
    renderStar();
    refresh();
  };

  setupAutocomplete(input, $('#board-list'), setStation);
  renderFavoriteChips($('#board-fav'), { target: () => 'board', fill: (_, loc) => setStation(loc) });

  const renderStar = () => {
    const passt = s.station && input.value.trim() === s.station.name;
    star.hidden = !passt;
    if (!passt) return;
    const fav = places.isFavorite(s.station);
    star.textContent = fav ? '★' : '☆';
    star.classList.toggle('is-on', fav);
    star.title = fav ? `${s.station.name} aus den Favoriten entfernen` : `${s.station.name} als Favorit merken`;
    star.setAttribute('aria-label', star.title);
    star.setAttribute('aria-pressed', String(fav));
  };
  star.addEventListener('click', () => { if (s.station) places.toggle(s.station); });
  places.onChange(renderStar);
  input.addEventListener('input', renderStar);

  // --- Abfahrt / Ankunft, Uhrzeit --------------------------------------

  const typeBtns = [...root.querySelectorAll('[data-board-type]')];
  const renderType = () => {
    for (const b of typeBtns) {
      const on = (b.dataset.boardType === 'arr') === s.arrivals;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', String(on));
    }
  };
  for (const b of typeBtns) {
    b.addEventListener('click', () => {
      s.arrivals = b.dataset.boardType === 'arr';
      save(s);
      renderType();
      refresh();
    });
  }

  const timeInput = $('#board-time');
  const setNow = () => {
    s.time = null;
    timeInput.value = localTime(new Date());
  };
  timeInput.addEventListener('change', () => {
    s.time = timeInput.value || null;
    refresh();
  });
  $('#board-now').addEventListener('click', () => { setNow(); refresh(); });

  $('#board-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    // Getippt, aber nicht ausgewählt: den ersten Treffer nehmen, wie in der Suche.
    const q = input.value.trim();
    if (q && q !== s.station?.name) {
      try {
        const res = await api.locations(q);
        const hit = (res.locations || [])[0];
        if (hit) { setStation(hit); return; }
      } catch { /* bleibt bei der Meldung unten */ }
    }
    refresh();
  });

  // --- Filter ----------------------------------------------------------

  const filterBox = $('#board-filter');
  const renderFilter = () => {
    filterBox.replaceChildren();
    // Nur Gruppen zeigen, die in der Tafel auch vorkommen - am Hauptbahnhof
    // Zürich gibt es keine U-Bahn, und ein toter Knopf verwirrt.
    const da = new Set(s.entries.map(groupOf));
    for (const g of GROUPS) {
      if (g.id !== 'all' && !da.has(g.id)) continue;
      const b = el('button', 'board__chip' + (s.group === g.id ? ' is-active' : ''), g.label);
      b.type = 'button';
      b.setAttribute('aria-pressed', String(s.group === g.id));
      b.addEventListener('click', () => {
        s.group = g.id;
        save(s);
        render();
      });
      filterBox.append(b);
    }
    filterBox.hidden = s.entries.length === 0;
  };

  // --- Laden -----------------------------------------------------------

  async function refresh({ append = false } = {}) {
    if (!s.station) { render(); return; }
    if (s.abort) s.abort.abort();
    s.abort = new AbortController();

    let date;
    let time;
    if (append && s.until) {
      const d = new Date(s.until);
      date = localDate(d);
      time = localTime(d);
    } else if (s.time) {
      date = localDate(new Date());
      time = s.time;
    } else {
      // Zwei Minuten zurück: der Zug, der gerade einfährt, gehört noch dazu.
      const d = new Date(Date.now() - 2 * 60_000);
      date = localDate(d);
      time = localTime(d);
    }

    s.loading = true;
    s.error = null;
    render();
    try {
      const res = await api.departures({
        station: s.station.id,
        lat: s.station.lat, lon: s.station.lon,
        date, time,
        type: s.arrivals ? 'arr' : 'dep',
        duration: WINDOW_MIN,
      }, { signal: s.abort.signal });
      const neu = res.departures || [];
      if (append) {
        const known = new Set(s.entries.map(keyOf));
        s.entries.push(...neu.filter((e) => !known.has(keyOf(e))));
      } else {
        s.entries = neu;
      }
      s.until = res.until || null;
      s.sources = res.sources || [];
      s.fromCache = Boolean(res.fromCache);
    } catch (err) {
      if (err.name === 'AbortError') return;
      s.error = err.message;
      if (!append) s.entries = [];
    } finally {
      s.loading = false;
    }
    render();
  }

  const keyOf = (e) => `${e.jid || ''}|${e.line}|${e.planned}|${e.direction}`;

  // --- Zeichnen --------------------------------------------------------

  const list = $('#board-out');
  const status = $('#board-status');
  const more = $('#board-more');
  more.addEventListener('click', () => refresh({ append: true }));

  function render() {
    renderFilter();

    if (!s.station) {
      status.className = 'status';
      status.textContent = 'Bahnhof oder Haltestelle eingeben — oder einen Favoriten antippen.';
      list.replaceChildren();
      more.hidden = true;
      return;
    }
    if (s.loading && s.entries.length === 0) {
      status.className = 'status status--busy';
      status.textContent = `Lade ${s.arrivals ? 'Ankünfte' : 'Abfahrten'} …`;
    } else if (s.error) {
      status.className = 'status status--error';
      status.textContent = s.error;
    } else {
      status.className = 'status';
      const quelle = (s.sources || []).includes('mvg') ? ' · mit U-Bahn, Tram und Bus der MVG' : '';
      status.textContent = `${s.arrivals ? 'Ankünfte' : 'Abfahrten'} ${s.station.name}`
        + `${s.time ? ` ab ${s.time}` : ''}${quelle}${s.loading ? ' · aktualisiert …' : ''}`
        + (s.fromCache ? ' · offline, gespeicherter Stand' : '');
    }

    const shown = s.entries.filter((e) => s.group === 'all' || groupOf(e) === s.group);
    list.replaceChildren(...shown.map(renderRow));
    if (!s.loading && !s.error && shown.length === 0) {
      list.append(el('li', 'board__empty', s.entries.length
        ? 'In dieser Gruppe fährt im Zeitfenster nichts.'
        : 'In der nächsten Stunde fährt hier nichts — oder die Quelle kennt den Halt nicht.'));
    }
    more.hidden = s.entries.length === 0 || !s.until;
  }

  function renderRow(e) {
    const li = el('li', 'dep');
    if (e.cancelled) li.classList.add('is-cancelled');
    const key = keyOf(e);

    const main = el('button', 'dep__main');
    main.type = 'button';

    // Zeit: Plan, und wenn abweichend die Ist-Zeit daneben.
    const zeit = el('span', 'dep__time');
    const plan = hhmm(e.planned);
    const ist = e.real ? hhmm(e.real) : null;
    zeit.append(el('span', ist && ist !== plan ? 'dep__plan is-shifted' : 'dep__plan', plan));
    if (ist && ist !== plan) {
      zeit.append(el('span', 'dep__real' + ((e.delay ?? 0) >= 5 ? ' is-late' : ''), ist));
    }
    main.append(zeit);

    const typ = typeOf({ ...e, mode: 'train' });
    const chip = el('span', 'dep__line chip ' + (typ.longDistance ? 'chip--fern' : 'chip--nah'), trainLabel(e));
    main.append(chip);

    const richtung = el('span', 'dep__dir', e.direction || '');
    main.append(richtung);

    if (e.platform) {
      const g = el('span', 'dep__plat' + (e.platformChanged ? ' is-changed' : ''), `Gl. ${e.platform}`);
      if (e.platformChanged) g.title = 'Gleiswechsel';
      main.append(g);
    }

    // Zweite Zeile: Ausfall, Verspätung, Countdown.
    const info = [];
    if (e.cancelled) info.push('fällt aus');
    else if ((e.delay ?? 0) > 0) info.push(`+${e.delay} min`);
    const inMin = Math.round((Date.parse(e.real || e.planned) - Date.now()) / 60000);
    if (!e.cancelled && !s.time && inMin >= 0 && inMin <= 20) {
      info.push(inMin === 0 ? 'jetzt' : `in ${inMin} min`);
    }
    if (e.sev) info.push('Ersatzverkehr');
    if (info.length) main.append(el('span', 'dep__info', info.join(' · ')));

    li.append(main);

    // Aufklappen: der Zuglauf ab bzw. bis hier.
    const detail = el('div', 'dep__detail');
    detail.hidden = !s.open.has(key);
    li.append(detail);
    const kannAuf = Boolean(e.jid) || (e.remarks || []).length > 0;
    main.setAttribute('aria-expanded', String(!detail.hidden));
    if (!kannAuf) main.classList.add('is-static');
    main.addEventListener('click', () => {
      if (!kannAuf) return;
      const auf = detail.hidden;
      detail.hidden = !auf;
      main.setAttribute('aria-expanded', String(auf));
      if (auf) { s.open.add(key); fillDetail(detail, e); } else s.open.delete(key);
    });
    if (!detail.hidden) fillDetail(detail, e);
    return li;
  }

  function fillDetail(box, e) {
    box.replaceChildren();
    for (const r of e.remarks || []) box.append(el('p', 'dep__remark', r));
    if (!e.jid) return;

    const wait = el('p', 'dep__loading', 'Lade Zuglauf …');
    box.append(wait);
    if (!s.details.has(e.jid)) {
      s.details.set(e.jid, api.trainDetails(e.jid).then((r) => r.train).catch((err) => {
        s.details.delete(e.jid);
        throw err;
      }));
    }
    s.details.get(e.jid).then((t) => {
      wait.remove();
      box.append(renderRun(t, e));
    }, (err) => {
      wait.textContent = `Zuglauf nicht verfügbar: ${err.message}`;
    });
  }

  /**
   * Die Halte ab hier (Abfahrt) bzw. bis hier (Ankunft). Der ganze Lauf
   * eines ICE hat dreißig Halte; interessant sind die, zu denen man will
   * oder von denen der Zug kommt.
   */
  function renderRun(t, e) {
    const stops = t?.stops || [];
    const name = s.station?.name;
    let at = stops.findIndex((x) => String(x.id) === String(s.station?.id));
    if (at < 0) at = stops.findIndex((x) => x.name === name);
    // Die S-Bahn hält in "München Hbf (tief)", gefragt war "München Hbf":
    // derselbe Bahnhof, andere Kennung.
    if (at < 0 && name) at = stops.findIndex((x) => String(x.name || '').startsWith(name));
    const teil = at < 0 ? stops : s.arrivals ? stops.slice(0, at + 1) : stops.slice(at);

    const ol = el('ol', 'dep__stops');
    for (const x of teil) {
      const li = el('li', 'dep__stop' + (x.cancelled ? ' is-cancelled' : ''));
      const p = hhmm(x.departure || x.arrival);
      const r = x.departureReal || x.arrivalReal ? hhmm(x.departureReal || x.arrivalReal) : null;
      li.append(el('span', 'dep__stop-time', p));
      if (r && r !== p) li.append(el('span', 'dep__stop-real', r));
      li.append(el('span', 'dep__stop-name', x.name));
      if (x.platform) li.append(el('span', 'dep__stop-plat', `Gl. ${x.platform}`));
      ol.append(li);
    }
    const frag = document.createDocumentFragment();
    // Eine Meldung reicht hier - sie ist gekürzt, die Tafel soll Tafel bleiben.
    const m = (t?.messages || []).map((x) => (typeof x === 'string' ? x : x?.text)).find(Boolean);
    if (m) frag.append(el('p', 'dep__remark', m));
    frag.append(ol);
    return frag;
  }

  // --- Auffrischen -------------------------------------------------------

  const startTimer = () => {
    stopTimer();
    s.timer = setInterval(() => {
      if (s.active && !document.hidden && !s.time && s.station) refresh();
    }, REFRESH_MS);
  };
  const stopTimer = () => { if (s.timer) { clearInterval(s.timer); s.timer = null; } };

  // Erster Zustand.
  input.value = s.station?.name || '';
  setNow();
  renderType();
  renderStar();
  render();

  return {
    activate() {
      s.active = true;
      startTimer();
      if (!s.station) { input.focus(); return; }
      // Beim Zurückkommen sofort den aktuellen Stand, nicht den von vorhin.
      if (!s.time) setNow();
      refresh();
    },
    deactivate() {
      s.active = false;
      stopTimer();
    },
  };
}
