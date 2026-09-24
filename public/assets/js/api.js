/**
 * Zugriff auf das PHP-Backend.
 *
 * Alle Aufrufe gehen relativ auf ./api/ - dadurch funktioniert das Tool in
 * jedem Unterordner deiner Website, ohne dass du eine Domain konfigurieren musst.
 */

const BASE = new URL('api/', document.baseURI).href;

async function call(action, params = {}, { signal } = {}) {
  const url = new URL(BASE);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  }

  let res;
  try {
    res = await fetch(url, { signal, headers: { Accept: 'application/json' } });
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    throw new Error(
      'Das Backend ist nicht erreichbar. Läuft api/index.php auf dem Server und ist PHP aktiv?'
    );
  }

  let data;
  try {
    data = await res.json();
  } catch {
    // Typischer Fall: PHP wirft eine Warnung/Fatal und liefert HTML statt JSON.
    throw new Error(
      `Das Backend hat keine gültige Antwort geliefert (HTTP ${res.status}). Ruf api/index.php?action=health direkt auf, um die Fehlermeldung zu sehen.`
    );
  }

  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Fehler ${res.status}`);
  }

  // Ohne Netz liefert der Service Worker die letzte Antwort - das soll die
  // App wissen, damit sie den Stand nicht als aktuell ausgibt.
  if (res.headers.get('X-From-Cache') === '1') data.fromCache = true;
  // Auch der Offline-Hinweis oben hängt daran: "navigator.onLine" sagt nur,
  // ob das Gerät ein Netz hat, nicht, ob der Server erreichbar ist.
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('omnirail:net', { detail: { fromCache: Boolean(data.fromCache) } }));
  }

  return data;
}

/** Koordinate auf fünf Stellen (rund ein Meter), leer wenn unbekannt. */
const coord = (v) => (typeof v === 'number' && Number.isFinite(v) ? v.toFixed(5) : '');

export const api = {
  health: (opts) => call('health', {}, opts),

  catalogue: (opts) => call('catalogue', {}, opts),

  locations: (q, opts) => call('locations', { q }, opts),

  /** @param {number[]} bounds [süd, west, nord, ost] */
  liveTrains: (bounds, products, opts) =>
    call('livetrains', {
      bbox: bounds.map((v) => v.toFixed(4)).join(','),
      products: (products || []).join(','),
    }, opts),

  trainDetails: (jid, opts) => call('traindetails', { jid }, opts),

  /**
   * Zuglauf aus einer anderen Quelle als HAFAS: `{ db: journeyId }` für
   * Abschnitte mit DB-Kennung, oder `{ mvgFrom, mvgTo, line, dep, arr }`
   * für Abschnitte aus der MVG-Suche. Antwort im selben Format.
   */
  trainRun: (params, opts) => call('traindetails', params, opts),

  /**
   * Nächste Verbindungen ab einem Umsteigebahnhof.
   *
   * Zwei Verwendungen: ein Treffer als Rückfallebene an der Karte, drei als
   * Auswahl während der Fahrt, wenn der Anschluss zu platzen droht.
   */
  nextConnection: (params, opts) =>
    call('nextconnection', {
      from: params.from, to: params.to, date: params.date, time: params.time,
      class: params.travelClass || 2,
      exclude: params.exclude || '',
      limit: params.limit || 1,
      discounts: (params.discounts || []).join(','),
      products: (params.products || []).join(','),
    }, opts),

  /**
   * Ersatzweg im MVV (U-Bahn, Tram, Bus) zwischen zwei Punkten — über die
   * MVG, weil die Fahrplanquelle der ÖBB die Münchner U-Bahn nicht kennt.
   * Liegt ein Ende außerhalb des MVG-Netzes, ist die Antwort leer.
   */
  localRoute: (params, opts) =>
    call('localroute', {
      fromLat: params.fromLat, fromLon: params.fromLon,
      toLat: params.toLat, toLon: params.toLon,
      date: params.date, time: params.time,
    }, opts),

  /**
   * Alle DB-Tarife einer Verbindung (Super Sparpreis bis Flexpreis, beide
   * Klassen, mit Bedingungen). `ctx` ist der ctxRecon, den die Suche an der
   * Verbindung als `dbRecon` mitliefert.
   */
  offers: (params, opts) =>
    call('offers', {
      ctx: params.ctx,
      class: params.travelClass || 2,
      discounts: (params.discounts || []).join(','),
    }, opts),

  /**
   * Abfahrts- oder Ankunftstafel. lat/lon helfen dem Server, in München die
   * MVG-Haltestellen des Bahnhofs zu finden.
   */
  departures: (params, opts) =>
    call('departures', {
      station: params.station,
      lat: coord(params.lat), lon: coord(params.lon),
      date: params.date, time: params.time,
      type: params.type || 'dep',
      duration: params.duration || 60,
    }, opts),

  disruptions: (opts) => call('disruptions', {}, opts),

  /** EZB-Tageskurse, um Preise auch in Franken zu zeigen. */
  fxRate: (opts) => call('fxrate', {}, opts),

  /** Bauarbeiten im Netz, mit Abschnitt und Zeitraum. */
  works: (opts) => call('works', { days: 30 }, opts),

  /**
   * Die nummerierten Bahnsteige eines Bahnhofs aus OpenStreetMap.
   *
   * from/to sind die beiden Gleise des Umstiegs; der Server sagt damit, ob
   * sie am selben Bahnsteig liegen. Beide dürfen leer bleiben - dann kommt
   * schlicht der ganze Bahnhof zurück.
   */
  platforms: (lat, lon, from, to, opts) =>
    call('platforms', {
      lat: lat.toFixed(5), lon: lon.toFixed(5),
      from: from || '', to: to || '',
    }, opts),

  bestPrices: (params, opts) =>
    call('bestprices', {
      from: params.from, to: params.to, date: params.date,
      class: params.travelClass || 2,
      discounts: (params.discounts || []).join(','),
      products: (params.products || []).join(','),
    }, opts),

  journeys(params, opts) {
    return call(
      'journeys',
      {
        from: params.from,
        to: params.to,
        date: params.date,
        time: params.time,
        arrival: params.arrival ? '1' : '0',
        class: params.travelClass || 2,
        results: params.results || 8,
        discounts: (params.discounts || []).join(','),
        products: (params.products || []).join(','),
        via: (params.via || []).join(','),
        minchange: params.minChange || '',
        // Blätter-Kontext der vorigen Antwort; leer = erste Seite.
        scroll: params.scroll || '',
        // Koordinaten der beiden Enden. Damit erkennt der Server Stadtfahrten
        // in München, ohne jeden Bahnhof erst nachschlagen zu müssen.
        fromLat: coord(params.fromLat), fromLon: coord(params.fromLon),
        toLat: coord(params.toLat), toLon: coord(params.toLon),
      },
      opts
    );
  },
};
