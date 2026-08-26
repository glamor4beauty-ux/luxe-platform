/* ═══════════════════════════════════════════════════════════════════════════
   page.js — the proxy page, made to work.

   Same shop as the block, on a page of its own. The difference that matters
   is the address bar: this page has a real URL, so a category or a product
   can be linked to, shared and returned to.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var API = 'https://admin.modelsboutique.com/api/shop.php';

  var root = document.querySelector('.mb-page');
  if (!root) return;

  var code  = root.getAttribute('data-code') || '';
  var goods = document.getElementById('mbPageGoods');
  var cats  = document.getElementById('mbPageCats');
  var more  = document.getElementById('mbPageMore');

  var items = [], cursor = null, hasMore = false, busy = false;
  var active = root.getAttribute('data-collection') || '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return '$' + Number(n || 0).toFixed(2); }

  function get(qs) {
    return fetch(API + '?' + qs).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('The shop is not answering just now.'); }
        if (!j.ok) throw new Error(j.error || 'Something went wrong.');
        return j;
      });
    });
  }

  function load(reset) {
    if (busy) return;
    busy = true;

    var qs = 'action=products';
    if (active) qs += '&collection=' + encodeURIComponent(active);
    if (!reset && cursor) qs += '&after=' + encodeURIComponent(cursor);

    get(qs).then(function (d) {
      busy = false;
      items = reset ? d.products : items.concat(d.products);
      cursor = d.cursor;
      hasMore = d.more;
      render();
    }).catch(function (e) {
      busy = false;
      goods.innerHTML = '<div class="mb-page-msg">' + esc(e.message) + '</div>';
      if (more) more.style.display = 'none';
    });
  }

  function render() {
    if (!items.length) {
      goods.innerHTML = '<div class="mb-page-msg">Nothing here just now.</div>';
      if (more) more.style.display = 'none';
      return;
    }

    goods.innerHTML = items.map(function (p) {
      return '<article class="mb-page-good' + (p.available ? '' : ' out') + '" ' +
               'data-handle="' + esc(p.handle) + '" tabindex="0">' +
        '<div class="mb-page-shot">' +
          (p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.alt) +
                     '" loading="lazy">' : '') +
          (p.available ? '' : '<span class="mb-page-tag">Sold out</span>') +
        '</div>' +
        '<h3 class="mb-page-name">' + esc(p.title) + '</h3>' +
        '<div class="mb-page-price">' +
          (p.varies ? '<span class="mb-page-from">from</span> ' : '') +
          money(p.price) +
          (p.was ? ' <s>' + money(p.was) + '</s>' : '') +
        '</div>' +
      '</article>';
    }).join('');

    if (more) more.style.display = hasMore ? '' : 'none';
  }

  /* Categories, and a real address for each — the point of being a page
     rather than a block is that a link to one can be shared. */
  if (cats) {
    get('action=collections').then(function (d) {
      if (!d.collections || !d.collections.length) { cats.style.display = 'none'; return; }

      cats.innerHTML =
        '<button type="button" class="mb-page-cat' + (active ? '' : ' on') +
          '" data-cat="">Everything</button>' +
        d.collections.map(function (c) {
          return '<button type="button" class="mb-page-cat' +
                 (active === c.handle ? ' on' : '') +
                 '" data-cat="' + esc(c.handle) + '">' + esc(c.title) + '</button>';
        }).join('');
    }).catch(function () { cats.style.display = 'none'; });
  }

  root.addEventListener('click', function (ev) {
    var cat = ev.target.closest('[data-cat]');
    if (cat) {
      active = cat.getAttribute('data-cat');
      root.querySelectorAll('.mb-page-cat').forEach(function (b) {
        b.classList.toggle('on', b === cat);
      });

      /* The address follows what is on screen, so a category can be linked to
         and the back button does what it should. */
      var url = location.pathname + (active ? '?c=' + encodeURIComponent(active) : '');
      history.replaceState(null, '', url);

      cursor = null;
      load(true);
      return;
    }

    if (ev.target.closest('.mb-page-btn')) { load(false); return; }

    var g = ev.target.closest('.mb-page-good[data-handle]');
    if (g) open(g.getAttribute('data-handle'));
  });

  root.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    var g = ev.target.closest && ev.target.closest('.mb-page-good[data-handle]');
    if (g) open(g.getAttribute('data-handle'));
  });

  function open(handle) {
    if (typeof window.mbShop !== 'function') return;
    if (code && typeof window.mbShopRef === 'function') window.mbShopRef(code);
    window.mbShop();
    setTimeout(function () {
      if (typeof window.lsOpen === 'function') window.lsOpen(handle);
    }, 60);
  }

  load(true);

  /* Arriving with a product in the address opens straight to it, which is
     what a shared link should do. */
  var wanted = root.getAttribute('data-product');
  if (wanted) setTimeout(function () { open(wanted); }, 700);

})();
