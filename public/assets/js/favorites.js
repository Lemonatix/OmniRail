/**
 * Lieblingsorte und zuletzt benutzte Bahnhöfe.
 *
 * WOZU: Wer regelmäßig dieselben Strecken fährt, tippt dieselben Namen
 * immer wieder ein - und die Ortssuche schlägt bei "München" erst einmal
 * ein Dutzend Halte vor. Favoriten stehen deshalb ohne Tippen bereit: als
 * Knöpfe unter den Bahnhofsfeldern und oben in der Vorschlagsliste, sobald
 * man in ein leeres Feld tippt. Dazu merkt sich die App die letzten Orte
 * von selbst.
 *
 * Alles liegt im localStorage dieses Browsers. Nichts geht an den Server.
 */

const FAV_KEY = 'train-maxxing:favorites';
const RECENT_KEY = 'train-maxxing:recent';

/** Mehr passen als Knöpfe nicht sinnvoll unter die Felder. */
const MAX_FAVORITES = 12;
const MAX_RECENT = 6;

const listeners = new Set();

function read(key) {
  try {
    const v = JSON.parse(localStorage.getItem(key) || '[]');
    return Array.isArray(v) ? v.filter((l) => l && l.id && l.name) : [];
  } catch {
    return [];
  }
}

function write(key, list) {
  try { localStorage.setItem(key, JSON.stringify(list)); } catch { /* privat oder voll */ }
}

/**
 * Nur, was die Suche braucht. Ein Ort aus der Ortssuche trägt mehr mit
 * (Produktlisten, Relevanz), das hier nur Platz kosten würde.
 */
function slim(loc) {
  return {
    id: String(loc.id),
    name: String(loc.name),
    country: loc.country || '',
    lat: loc.lat ?? null,
    lon: loc.lon ?? null,
    noJourneys: Boolean(loc.noJourneys),
    longDistance: Boolean(loc.longDistance),
  };
}

function changed() {
  for (const fn of listeners) {
    try { fn(); } catch { /* ein Beobachter darf die anderen nicht stören */ }
  }
}

export const places = {
  favorites() {
    return read(FAV_KEY);
  },

  isFavorite(loc) {
    return Boolean(loc) && read(FAV_KEY).some((f) => f.id === String(loc.id));
  },

  /** Favorit an- oder abwählen. @returns {boolean} ob er es jetzt ist */
  toggle(loc) {
    if (!loc?.id) return false;
    const list = read(FAV_KEY);
    const i = list.findIndex((f) => f.id === String(loc.id));
    if (i >= 0) list.splice(i, 1);
    else list.push(slim(loc));
    write(FAV_KEY, list.slice(-MAX_FAVORITES));
    changed();
    return i < 0;
  },

  /** Zuletzt benutzt, ohne die Favoriten - die stehen ohnehin darüber. */
  recent() {
    const fav = new Set(read(FAV_KEY).map((f) => f.id));
    return read(RECENT_KEY).filter((r) => !fav.has(r.id));
  },

  /** Einen benutzten Ort nach vorn holen. */
  remember(loc) {
    if (!loc?.id) return;
    const list = read(RECENT_KEY).filter((r) => r.id !== String(loc.id));
    list.unshift(slim(loc));
    write(RECENT_KEY, list.slice(0, MAX_RECENT));
  },

  /** @param {() => void} fn */
  onChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },
};

// Ein anderer Tab hat Favoriten geändert: nachziehen.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === FAV_KEY) changed();
  });
}
