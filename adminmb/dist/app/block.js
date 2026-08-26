/* ═══════════════════════════════════════════════════════════════════════════
   block.js — what makes the theme block a shop.

   Finds every Models Boutique block on the page, fills it with products, and
   opens the dialog when one is tapped. A merchant can place more than one, so
   nothing here assumes there is only a single block.

   Browsing happens in their theme; buying happens in the dialog, because that
   is where the bag and the checkout live and building a second set would be
   two things to keep right instead of one.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var API = 'https://admin.modelsboutique.com/api/shop.php';

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

  /* ── one block ────────────────────────────────────────────────────────── */
  function wire(root) {
    if (root.dataset.mbReady === '1') return;
    root.dataset.mbReady = '1';

    var id    = root.id.replace('mb-block-', '');
    var code  = root.getAttribute('data-code') || '';
    var goods = document.getElementById('mb-goods-' + id);
    var cats  = document.getElementById('mb-cats-' + id);
    var more  = document.getElementById('mb-more-' + id);

    if (!goods) return;

    var items = [], cursor = null, hasMore = false, active = '', busy = false;

    function skeleton() {
      var h = '';
      for (var i = 0; i < 8; i++) {
        h += '<div class="mb-good mb-skel"><div class="mb-shot"></div></div>';
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
        goods.innerHTML = '<div class="mb-msg">' + esc(e.message) + '</div>';
        if (more) more.style.display = 'none';
      });
    }

    function render() {
      if (!items.length) {
        goods.innerHTML = '<div class="mb-msg">Nothing here just now.</div>';
        if (more) more.style.display = 'none';
        return;
      }

      goods.innerHTML = items.map(function (p) {
        return '<article class="mb-good' + (p.available ? '' : ' out') + '" ' +
                 'data-handle="' + esc(p.handle) + '" tabindex="0">' +
          '<div class="mb-shot">' +
            (p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.alt) +
                       '" loading="lazy">' : '') +
            /* whether, never how many — a customer needs to know it is
               available, not that two remain */
            (p.available ? '' : '<span class="mb-tag">Sold out</span>') +
            (p.was ? '<span class="mb-tag sale">Sale</span>' : '') +
          '</div>' +
          '<h3 class="mb-name">' + esc(p.title) + '</h3>' +
          '<div class="mb-price">' +
            (p.varies ? '<span class="mb-from">from</span> ' : '') +
            money(p.price) +
            (p.was ? ' <s>' + money(p.was) + '</s>' : '') +
          '</div>' +
        '</article>';
      }).join('');

      if (more) more.style.display = hasMore ? '' : 'none';
    }

    /* Categories come from the shop, so one added there appears here without
       the merchant touching their theme. */
    if (cats) {
      get('action=collections').then(function (d) {
        if (!d.collections || !d.collections.length) { cats.style.display = 'none'; return; }
        cats.innerHTML = '<button type="button" class="mb-cat on" data-cat="">Everything</button>' +
          d.collections.map(function (c) {
            return '<button type="button" class="mb-cat" data-cat="' + esc(c.handle) + '">' +
                   esc(c.title) + '</button>';
          }).join('');
      }).catch(function () { cats.style.display = 'none'; });
    }

    root.addEventListener('click', function (ev) {
      var cat = ev.target.closest('[data-cat]');
      if (cat) {
        active = cat.getAttribute('data-cat');
        root.querySelectorAll('.mb-cat').forEach(function (b) {
          b.classList.toggle('on', b === cat);
        });
        load(true);
        return;
      }

      if (ev.target.closest('.mb-btn-more')) { load(false); return; }

      var g = ev.target.closest('.mb-good[data-handle]');
      if (g && !g.classList.contains('mb-skel')) open(g.getAttribute('data-handle'));
    });

    root.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') return;
      var g = ev.target.closest && ev.target.closest('.mb-good[data-handle]');
      if (g) open(g.getAttribute('data-handle'));
    });

    /* The dialog builds itself on first open, so the product view waits a
       moment for it rather than calling into something that is not there. */
    function open(handle) {
      if (typeof window.mbShop !== 'function') return;
      if (code && typeof window.mbShopRef === 'function') window.mbShopRef(code);
      window.mbShop();
      setTimeout(function () {
        if (typeof window.lsOpen === 'function') window.lsOpen(handle);
      }, 60);
    }

    load(true);
  }

  function start() {
    document.querySelectorAll('[id^="mb-block-"]').forEach(wire);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  /* The theme editor adds and removes sections without reloading, so a block
     dropped in while the merchant is designing has to wire itself up. */
  document.addEventListener('shopify:section:load', start);
  document.addEventListener('shopify:block:select', start);

})();
