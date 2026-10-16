/**
 * @file
 * Live Verify asset library: search + automatic refresh.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  const fmtDate = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString();
  };

  const fmtSize = (bytes) => {
    if (!bytes) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
  };

  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  };

  let canImport = false;

  const kind = (asset) => {
    const ext = (asset.name || '').match(/\.([a-z0-9]{1,5})$/i);
    return (ext ? ext[1] : (asset.type || 'file').split('/').pop()).toUpperCase();
  };

  function renderCard(asset, isNew) {
    const card = el('article', 'verify-asset' + (isNew ? ' is-new' : ''));
    card.dataset.id = asset.id;
    if (isNew) setTimeout(() => card.classList.remove('is-new'), 10000);

    const media = el('div', 'verify-asset__media');
    if (asset.thumbnail) {
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = asset.name || '';
      img.src = asset.thumbnail;
      img.addEventListener('error', () => {
        img.remove();
        media.appendChild(el('span', 'verify-asset__placeholder', kind(asset)));
      });
      media.appendChild(img);
    }
    else {
      media.appendChild(el('span', 'verify-asset__placeholder', kind(asset)));
    }
    if (asset.status) {
      media.appendChild(el('span', `verify-asset__badge verify-asset__badge--${asset.status.toLowerCase()}`, asset.status));
    }
    if (asset.imported) {
      card.classList.add('is-imported');
      media.appendChild(el('span', 'verify-asset__imported', Drupal.t('In Drupal')));
    }
    if (canImport) {
      card.tabIndex = 0;
      card.setAttribute('role', 'button');
      card.setAttribute('aria-label', asset.imported
        ? Drupal.t('Open @name in Drupal', { '@name': asset.name })
        : Drupal.t('Copy @name to Drupal', { '@name': asset.name }));
      media.appendChild(el('span', 'verify-asset__action', asset.imported ? Drupal.t('Open in Drupal') : Drupal.t('Copy to Drupal')));
    }
    card.appendChild(media);

    const body = el('div', 'verify-asset__body');
    body.appendChild(el('h3', 'verify-asset__name', asset.name || asset.id)).title = asset.name || '';
    // Line 1: dimensions · size. Line 2: date (always on its own line).
    const meta = [
      asset.width && asset.height ? `${asset.width}×${asset.height}` : '',
      fmtSize(asset.size),
    ].filter(Boolean).join(' · ');
    body.appendChild(el('p', 'verify-asset__meta', meta || '\u00a0'));
    body.appendChild(el('p', 'verify-asset__date', fmtDate(asset.created) || '\u00a0'));
    card.appendChild(body);
    return card;
  }

  Drupal.behaviors.verifyLibrary = {
    attach(context) {
      once('verify-library', '.verify-library', context).forEach((root) => {
        const settings = drupalSettings.verifyDam || {};
        const input = root.querySelector('.verify-library__search');
        const grid = root.querySelector('.verify-library__grid');
        const status = root.querySelector('.verify-library__status');

        let query = '';
        let type = '';
        const typeSelect = root.querySelector('.verify-library__type');
        let knownIds = null; // Set of ids for the current query; null = first load.
        let inFlight = null;
        let timer = null;

        const setStatus = (text) => { status.textContent = text; };
        // Drupal.Message comes from core/drupal.message; fall back to the
        // status line if it isn't loaded (e.g. before a cache rebuild).
        const messages = Drupal.Message ? new Drupal.Message() : {
          clear() {},
          add(html) { const d = document.createElement('div'); d.innerHTML = html; setStatus(d.textContent); },
        };
        canImport = Boolean(settings.canImport);
        const opened = new Map(); // asset id -> URL of the Drupal copy.
        let csrfToken = null;

        async function copyToDrupal(card) {
          const id = card.dataset.id;
          if (card.classList.contains('is-busy')) return;
          if (opened.has(id)) {
            window.open(opened.get(id), '_blank', 'noopener');
            return;
          }
          card.classList.add('is-busy');
          card.setAttribute('aria-busy', 'true');
          const overlay = el('div', 'verify-asset__progress');
          overlay.appendChild(el('span', 'verify-asset__spinner'));
          overlay.appendChild(el('span', 'verify-asset__progress-text', Drupal.t('Copying to Drupal…')));
          card.querySelector('.verify-asset__media').appendChild(overlay);
          const label = card.querySelector('.verify-asset__action');
          const before = label ? label.textContent : '';
          if (label) label.textContent = Drupal.t('Copying…');
          try {
            csrfToken = csrfToken || await fetch(settings.tokenUrl, { credentials: 'same-origin' }).then((r) => r.text());
            const res = await fetch(settings.importUrl.replace('ASSET_ID', encodeURIComponent(id)), {
              method: 'POST',
              credentials: 'same-origin',
              headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || Drupal.t('Copy failed (HTTP @code).', { '@code': res.status }));

            opened.set(id, data.open_url);
            card.classList.add('is-imported');
            if (!card.querySelector('.verify-asset__imported')) {
              card.querySelector('.verify-asset__media').appendChild(el('span', 'verify-asset__imported', Drupal.t('In Drupal')));
            }
            if (label) label.textContent = Drupal.t('Open in Drupal');
            const where = data.media_url ? Drupal.t('Media') : Drupal.t('Files');
            const text = data.existing
              ? Drupal.t('“@name” was already in Drupal (@where).', { '@name': data.name, '@where': where })
              : Drupal.t('Copied “@name” to Drupal (@where).', { '@name': data.name, '@where': where });
            messages.clear();
            messages.add(`${Drupal.checkPlain(text)} <a href="${Drupal.checkPlain(data.open_url)}" target="_blank" rel="noopener">${Drupal.t('Open')}</a>`, { type: 'status' });
          }
          catch (e) {
            if (label) label.textContent = before;
            messages.clear();
            messages.add(Drupal.checkPlain(e.message), { type: 'error' });
          }
          finally {
            card.classList.remove('is-busy');
            card.removeAttribute('aria-busy');
            overlay.remove();
          }
        }

        if (canImport) {
          grid.addEventListener('click', (e) => {
            const card = e.target.closest('.verify-asset');
            if (card) copyToDrupal(card);
          });
          grid.addEventListener('keydown', (e) => {
            const card = e.target.closest('.verify-asset');
            if (card && (e.key === 'Enter' || e.key === ' ')) {
              e.preventDefault();
              copyToDrupal(card);
            }
          });
        }

        function showError(data) {
          grid.replaceChildren();
          const box = el('div', 'verify-library__message messages messages--error', data.error || Drupal.t('Something went wrong.'));
          if (data.auth && settings.connectUrl) {
            box.append(' ');
            const a = el('a', null, Drupal.t('Reconnect your API key'));
            a.href = settings.connectUrl;
            box.appendChild(a);
          }
          grid.appendChild(box);
          setStatus('');
        }

        async function load({ silent = false } = {}) {
          if (inFlight) inFlight.abort();
          const controller = new AbortController();
          inFlight = controller;
          if (!silent) setStatus(Drupal.t('Loading…'));

          const forQuery = query;
          const forType = type;
          let res;
          let data;
          try {
            res = await fetch(`${settings.endpoint}?q=${encodeURIComponent(forQuery)}&type=${encodeURIComponent(forType)}`, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
              signal: controller.signal,
            });
            data = await res.json();
          }
          catch (e) {
            if (e.name === 'AbortError') return;
            if (!silent) showError({ error: Drupal.t('Could not load assets.') });
            return;
          }
          finally {
            if (inFlight === controller) inFlight = null;
          }
          if (forQuery !== query || forType !== type) return;
          if (!res.ok) {
            showError(data || {});
            return;
          }

          const assets = data.assets || [];
          const newIds = knownIds ? assets.filter((a) => !knownIds.has(a.id)).map((a) => a.id) : [];
          const newSet = new Set(newIds);
          knownIds = new Set(assets.map((a) => a.id));

          // Reuse cards that are unchanged so thumbnails don't flicker on refresh.
          const existing = new Map();
          grid.querySelectorAll('.verify-asset').forEach((c) => existing.set(c.dataset.id, c));
          grid.replaceChildren();
          if (!assets.length) {
            grid.appendChild(el('p', 'verify-library__empty', query ? Drupal.t('No assets match “@q”.', { '@q': query }) : (type ? Drupal.t('No assets of this type.') : Drupal.t('Your Verify library is empty.'))));
          }
          else {
            const frag = document.createDocumentFragment();
            assets.forEach((a) => {
              const sig = `${a.name}|${a.status}|${a.modified}|${a.imported ? 1 : 0}`;
              let card = existing.get(a.id);
              if (!card || card.dataset.sig !== sig) {
                card = renderCard(a, newSet.has(a.id));
                card.dataset.sig = sig;
              }
              frag.appendChild(card);
            });
            grid.appendChild(frag);
          }

          const time = new Date().toLocaleTimeString();
          const count = Drupal.formatPlural(assets.length, '1 asset', '@count assets');
          setStatus(newIds.length
            ? Drupal.formatPlural(newIds.length, '1 new asset', '@count new assets') + ` · ${count} · ${time}`
            : `${count} · ${Drupal.t('updated')} ${time}`);
        }

        function schedule() {
          clearTimeout(timer);
          timer = setTimeout(async () => {
            if (document.visibilityState === 'visible') await load({ silent: true });
            schedule();
          }, settings.pollInterval || 15000);
        }

        // Search runs on the Search button or Enter, not on every keystroke.
        function runSearch() {
          const next = input.value.trim();
          if (next === query) return;
          query = next;
          knownIds = null; // Don't flag results of a new search as "new".
          load();
        }
        if (typeSelect) {
          typeSelect.addEventListener('change', () => {
            type = typeSelect.value;
            knownIds = null; // Don't flag results of a new filter as "new".
            load();
          });
        }

        const form = root.querySelector('.verify-library__search-form');
        if (form) {
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            runSearch();
          });
        }
        // Clearing the box (or its × button) returns to the full library.
        input.addEventListener('input', () => {
          if (input.value.trim() === '' && query !== '') runSearch();
        });

        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') load({ silent: true });
        });

        load().then(schedule);
      });
    },
  };
})(Drupal, drupalSettings, once);
