/* ═══════════════════════════════════════════════════════════════════════════
   shopfront.js — the products, on the page.

   Template two shows the goods directly rather than behind a button. The
   categories filter what is displayed; tapping a product opens the dialog to
   choose a size and buy, because that is where the bag and the checkout live.

   So: browsing is on her page, buying is in the dialog. One less click to see
   what is for sale, and no second checkout to maintain.

   Runs only where #goods exists, so it is harmless on template one.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var API = 'https://admin.modelsboutique.com/api/shop.php';

  var goods = document.getElementById('goods');
  if (!goods) return;

  var cats = document.getElementById('cats');
  var moreWrap = document.getElementById('moreWrap');
  var moreBtn = document.getElementById('more');

  var items = [], cursor = null, hasMore = false, busy = false, active = '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return '$' + Number(n || 0).toFixed(2); }

  /* the affiliate's code, taken from wherever the page carries it, so a sale
     from this display is credited the same as one from the dialog */
  function code() {
    var s = document.getElementById('affStatus');
    if (s && s.getAttribute('data-code')) return s.getAttribute('data-code');
    var d = document.querySelector('[data-mb-shop]');
    return (d && d.getAttribute('data-mb-shop')) || '';
  }

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

  /* ── the categories ───────────────────────────────────────────────────
     From the shop, so a category added there appears here without this page
     being rebuilt or re-uploaded. */
  function loadCats() {
    get('action=collections').then(function (d) {
      if (!d.collections || !d.collections.length) return;
      cats.innerHTML = '<button class="cat on" data-cat="">Everything</button>' +
        d.collections.map(function (c) {
          return '<button class="cat" data-cat="' + esc(c.handle) + '">' +
                 esc(c.title) + '</button>';
        }).join('');
    }).catch(function () {
      /* the shop still works without them; a broken row of buttons does not */
    });
  }

  /* ── the goods ────────────────────────────────────────────────────────── */
  function skeleton() {
    var h = '';
    for (var i = 0; i < 8; i++) {
      h += '<div class="good sk"><div class="good-shot"></div>' +
           '<div class="sk-line"></div><div class="sk-line short"></div></div>';
    }
    goods.innerHTML = h;
  }

  function load(reset) {
    if (busy) return;
    busy = true;

    if (reset) { items = []; cursor = null; skeleton(); }

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
      goods.innerHTML = '<div class="goods-msg">' + esc(e.message) + '</div>';
      moreWrap.style.display = 'none';
    });
  }

  function render() {
    if (!items.length) {
      goods.innerHTML = '<div class="goods-msg">Nothing here just now.</div>';
      moreWrap.style.display = 'none';
      return;
    }

    goods.innerHTML = items.map(function (p) {
      return '<article class="good' + (p.available ? '' : ' out') + '" ' +
               'data-handle="' + esc(p.handle) + '" tabindex="0">' +
        '<div class="good-shot">' +
          (p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.alt) +
                     '" loading="lazy">' : '') +
          /* whether, never how many — a customer needs to know it is available,
             not that two remain */
          (p.available ? '' : '<span class="good-out">Sold out</span>') +
          (p.was ? '<span class="good-sale">Sale</span>' : '') +
        '</div>' +
        '<h3>' + esc(p.title) + '</h3>' +
        '<div class="price">' +
          (p.varies ? '<span class="from">from</span> ' : '') +
          money(p.price) +
          (p.was ? ' <s>' + money(p.was) + '</s>' : '') +
        '</div>' +
      '</article>';
    }).join('');

    moreWrap.style.display = hasMore ? '' : 'none';
  }

  /* ── what a tap does ──────────────────────────────────────────────────
     Opens the dialog at that product. Sizes, the bag and the checkout all
     live there; building a second set here would be two things to keep
     right instead of one. */
  function open(handle) {
    if (typeof window.mbShop !== 'function') return;

    var c = code();
    if (c && typeof window.mbShopRef === 'function') window.mbShopRef(c);

    window.mbShop();
    /* the dialog builds itself on first open, so the product view waits for it */
    setTimeout(function () {
      if (typeof window.lsOpen === 'function') window.lsOpen(handle);
    }, 60);
  }

  document.addEventListener('click', function (ev) {
    var cat = ev.target.closest('[data-cat]');
    if (cat) {
      active = cat.getAttribute('data-cat');
      [].slice.call(cats.querySelectorAll('.cat')).forEach(function (b) {
        b.classList.toggle('on', b === cat);
      });
      load(true);
      return;
    }

    var g = ev.target.closest('.good[data-handle]');
    if (g && !g.classList.contains('sk')) { open(g.getAttribute('data-handle')); return; }

    if (ev.target.closest('#more')) { load(false); return; }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    var g = ev.target.closest && ev.target.closest('.good[data-handle]');
    if (g) open(g.getAttribute('data-handle'));
  });

  loadCats();
  load(true);

})();
