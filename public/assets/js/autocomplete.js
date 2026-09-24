/**
 * Vorschlagsliste für Bahnhofsfelder.
 *
 * Eigenes Modul, weil zwei Stellen sie brauchen: das Suchformular und die
 * Abfahrtstafel.
 *
 * Tippt man in ein leeres Feld, stehen Favoriten und die zuletzt benutzten
 * Orte da, ohne dass man etwas eingeben muss. Jeder Vorschlag trägt einen
 * Stern, der ihn zum Favoriten macht oder wieder entfernt.
 */

import { api } from './api.js';
import { places } from './favorites.js';

const node = (tag, className, text) => {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text != null) n.textContent = text;
  return n;
};

/**
 * @param {HTMLInputElement} input
 * @param {HTMLElement} list    <ul> für die Vorschläge
 * @param {(loc: object) => void} onPick
 */
export function setupAutocomplete(input, list, onPick) {
  let timer = null;
  let abort = null;
  /** @type {{loc: object, group?: string}[]} */
  let items = [];
  let active = -1;

  const close = () => {
    list.hidden = true;
    list.replaceChildren();
    active = -1;
    input.setAttribute('aria-expanded', 'false');
  };

  const pick = (loc) => {
    input.value = loc.name;
    places.remember(loc);
    onPick(loc);
    close();
  };

  const draw = () => {
    list.replaceChildren();
    let gruppe = null;
    items.forEach(({ loc, group }, i) => {
      // Zwischenüberschrift, wenn die Liste aus Favoriten und Verlauf besteht.
      if (group && group !== gruppe) {
        gruppe = group;
        const h = node('li', 'ac__group', group);
        h.setAttribute('role', 'presentation');
        list.append(h);
      }

      const li = node('li', 'ac__item' + (i === active ? ' is-active' : ''));
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', String(i === active));

      li.append(node('span', 'ac__name', loc.name));

      if (loc.country) li.append(node('span', 'ac__country', loc.country.toUpperCase()));

      const fav = places.isFavorite(loc);
      const star = node('button', 'ac__star' + (fav ? ' is-on' : ''), fav ? '★' : '☆');
      star.type = 'button';
      star.tabIndex = -1;
      star.title = fav ? 'Aus den Favoriten entfernen' : 'Als Favorit merken';
      star.setAttribute('aria-label', star.title);
      // mousedown statt click: sonst verliert das Feld zuerst den Fokus und
      // die Liste schließt, bevor der Stern gedrückt ist.
      star.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();
        places.toggle(loc);
        draw();
      });
      li.append(star);

      li.addEventListener('mousedown', (e) => {
        if (e.target.closest('.ac__star')) return;
        e.preventDefault(); // verhindert blur vor dem Klick
        pick(loc);
      });
      list.append(li);
    });
    list.hidden = items.length === 0;
    input.setAttribute('aria-expanded', String(!list.hidden));
  };

  /** Favoriten und Verlauf - für ein leeres Feld statt einer leeren Liste. */
  const showSaved = () => {
    const fav = places.favorites().map((loc) => ({ loc, group: 'Favoriten' }));
    const rec = places.recent().map((loc) => ({ loc, group: 'Zuletzt' }));
    items = [...fav, ...rec];
    active = -1;
    draw();
  };

  input.addEventListener('focus', () => {
    // Nur bei leerem Feld - wer schon etwas getippt hat, will seine Treffer.
    if (input.value.trim() === '') showSaved();
  });

  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (abort) abort.abort();

    if (q.length === 0) { showSaved(); return; }
    if (q.length < 2) { close(); return; }

    timer = setTimeout(async () => {
      abort = new AbortController();
      try {
        const res = await api.locations(q, { signal: abort.signal });
        items = (res.locations || []).map((loc) => ({ loc }));
        active = -1;
        draw();
      } catch (err) {
        if (err.name !== 'AbortError') close();
      }
    }, 220);
  });

  input.addEventListener('keydown', (e) => {
    if (list.hidden || items.length === 0) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      active = (active + 1) % items.length;
      draw();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      active = (active - 1 + items.length) % items.length;
      draw();
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      pick(items[active].loc);
    } else if (e.key === 'Escape') {
      close();
    }
  });

  input.addEventListener('blur', () => setTimeout(close, 120));
}

/**
 * Favoriten als Knöpfe unter den Bahnhofsfeldern.
 *
 * Ein Tipp setzt den Ort in das Feld, das man zuletzt angefasst hat. Hat
 * man noch keines angefasst, in "Von", solange das leer ist, sonst in
 * "Nach" - so ergeben zwei Tipps hintereinander eine Strecke.
 *
 * @param {HTMLElement} box
 * @param {{ target: () => string, fill: (field: string, loc: object) => void }} opts
 */
export function renderFavoriteChips(box, { target, fill }) {
  const draw = () => {
    const favs = places.favorites();
    box.replaceChildren();
    box.hidden = favs.length === 0;
    if (favs.length === 0) return;

    box.append(node('span', 'fav-chips__label', '★'));
    for (const loc of favs) {
      const b = node('button', 'fav-chip', loc.name);
      b.type = 'button';
      b.title = `${loc.name} einsetzen`;
      b.addEventListener('click', () => fill(target(), loc));
      box.append(b);
    }
  };
  draw();
  places.onChange(draw);
}
