/* ═══════════════════════════════════════════════════════════════════════════
   boutique.js — the shop, as a dialog.

   Opens over whichever page called it, the same way the performer dialog does,
   with a back button rather than a navigation away. Header, product grid,
   product view with variant picker, bag, and checkout.

   Stock is never shown as a number. The server sends only whether something
   can be bought, so the most this can say is "sold out" — a customer needs to
   know availability, not that two remain.

   Call mbShop() from a link, or add data-mb-shop to any element.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var API = 'https://admin.modelsboutique.com/api/shop.php';
  var BAG_KEY = 'mb_bag_v1';
  var REF_KEY = 'mb_ref_v1';
  var REF_DAYS = 30;

  /* ── who gets the credit ────────────────────────────────────────────────
     A performer's link carries ?ref=her-code, or the element that opens the
     shop carries data-mb-shop="her-code". Either is remembered for 30 days.

     Last touch wins. Two performers selling to two customers both earn, since
     attribution follows the customer rather than the product; the only case
     this rule decides is one customer arriving through two links, and the more
     recent link is the one that did the persuading. */
  function refSet(code) {
    code = String(code || '').trim().slice(0, 60);
    if (!code) return;
    try {
      localStorage.setItem(REF_KEY, JSON.stringify({ code: code, at: Date.now() }));
    } catch (e) {}
  }

  function refGet() {
    try {
      var raw = localStorage.getItem(REF_KEY);
      if (!raw) return '';
      var r = JSON.parse(raw);
      if (!r || !r.code) return '';
      /* expired credit is no credit; a stale reference paying commission on a
         sale it did not cause is worse than none */
      if (Date.now() - r.at > REF_DAYS * 86400000) {
        localStorage.removeItem(REF_KEY);
        return '';
      }
      return r.code;
    } catch (e) { return ''; }
  }

  /* a ref in the address is picked up on any page the script is on, whether
     or not the shop is ever opened */
  (function () {
    try {
      var m = location.search.match(/[?&]ref=([^&]+)/);
      if (m) refSet(decodeURIComponent(m[1]));
    } catch (e) {}
  })();

  var bag = [];            /* {variantId, title, variant, price, image, qty} */
  var view = 'grid';       /* grid | product | bag */
  var products = [], cursor = null, more = false, loading = false;
  var current = null, chosen = {};
  var built = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function el(id) { return document.getElementById(id); }
  function money(n) { return '$' + Number(n || 0).toFixed(2); }

  /* The bag survives a reload, because losing a bag to a mistyped URL is the
     kind of small annoyance that ends a sale. */
  function loadBag() {
    try {
      var raw = localStorage.getItem(BAG_KEY);
      bag = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(bag)) bag = [];
    } catch (e) { bag = []; }
  }
  function saveBag() {
    try { localStorage.setItem(BAG_KEY, JSON.stringify(bag)); } catch (e) {}
    var n = bag.reduce(function (t, l) { return t + l.qty; }, 0);
    var b = el('lsCount');
    if (b) {
      b.textContent = n;
      b.style.display = n ? '' : 'none';
    }
  }
  function bagTotal() {
    return bag.reduce(function (t, l) { return t + (l.price * l.qty); }, 0);
  }

  function api(qs, payload) {
    var opt = {};
    if (payload !== undefined) {
      opt.method = 'POST';
      opt.headers = { 'Content-Type': 'application/json' };
      opt.body = JSON.stringify(payload);
    }
    return fetch(API + '?' + qs, opt).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); }
        catch (e) { throw new Error('The shop is not answering just now.'); }
        if (!j.ok) throw new Error(j.error || 'Something went wrong.');
        return j;
      });
    });
  }

  /* ═══ OPENING ═════════════════════════════════════════════════════════ */
  window.mbShop = function () {
    if (!built) {
      var host = document.createElement('div');
      host.id = 'mbShopHost';
      host.innerHTML = '<style>' + CSS + '</style>' + SHELL;
      document.body.appendChild(host);
      built = true;
      loadBag();
      saveBag();
      wire();
    }
    el('lsOverlay').classList.add('on');
    document.body.style.overflow = 'hidden';

    var ref = refGet();
    var tag = el('lsRef');
    if (tag) {
      tag.textContent = ref ? 'via ' + ref : '';
      tag.style.display = ref ? '' : 'none';
    }

    if (!products.length) loadGrid(true);
    else show('grid');
  };

  /* Set from outside — the inline shop on template two tells the dialog who
     to credit before opening it, so a sale made from the page counts the same
     as one made from the dialog. */
  window.mbShopRef = function (c) { refSet(c); };

  window.mbShopClose = function () {
    el('lsOverlay').classList.remove('on');
    document.body.style.overflow = '';
  };

  function wire() {
    el('lsOverlay').addEventListener('click', function (e) {
      if (e.target.id === 'lsOverlay') mbShopClose();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && el('lsOverlay').classList.contains('on')) {
        if (el('lsDrawer').classList.contains('on')) lsMenu();
        else if (view === 'grid') mbShopClose();
        else show('grid');
      }
    });
    el('lsSearch').addEventListener('input', function () {
      clearTimeout(wire._t);
      wire._t = setTimeout(function () { loadGrid(true); }, 400);
    });
  }

  /* Collections come from Shopify, so a category added there appears here
     without anything being edited. */
  var cats = null, activeCat = '';

  window.lsMenu = function () {
    var d = el('lsDrawer'), sc = el('lsScrim');
    var open = d.classList.toggle('on');
    sc.classList.toggle('on', open);
    if (open && cats === null) loadCats();
  };

  function loadCats() {
    api('action=collections').then(function (d) {
      cats = d.collections;
      renderCats();
    }).catch(function () {
      el('lsCats').innerHTML = '<div class="ls-dloading">Categories unavailable.</div>';
    });
  }

  function renderCats() {
    var h = '<button type="button" class="ls-cat' + (activeCat ? '' : ' on') +
            '" data-cat="">Everything</button>';
    (cats || []).forEach(function (c) {
      h += '<button type="button" class="ls-cat' +
           (activeCat === c.handle ? ' on' : '') + '" data-cat="' + esc(c.handle) + '">' +
           esc(c.title) + '</button>';
    });
    el('lsCats').innerHTML = h;
  }

  window.lsCat = function (handle) {
    activeCat = handle;
    renderCats();
    lsMenu();
    el('lsSearch').value = '';
    loadGrid(true);
  };

  function show(v) {
    view = v;
    ['lsGrid', 'lsProduct', 'lsBag'].forEach(function (id) {
      el(id).style.display = 'none';
    });
    el(v === 'grid' ? 'lsGrid' : v === 'product' ? 'lsProduct' : 'lsBag').style.display = '';
    el('lsBack').style.display = v === 'grid' ? 'none' : '';
    el('lsBody').scrollTop = 0;
  }

  window.lsBack = function () { show('grid'); };

  /* ═══ THE GRID ════════════════════════════════════════════════════════ */
  function loadGrid(reset) {
    if (loading) return;
    loading = true;

    if (reset) { products = []; cursor = null; el('lsGrid').innerHTML = skeleton(); }

    var qs = 'action=products';
    var q = el('lsSearch').value.trim();
    if (q) qs += '&q=' + encodeURIComponent(q);
    if (activeCat) qs += '&collection=' + encodeURIComponent(activeCat);
    if (!reset && cursor) qs += '&after=' + encodeURIComponent(cursor);

    api(qs).then(function (d) {
      loading = false;
      products = reset ? d.products : products.concat(d.products);
      cursor = d.cursor;
      more = d.more;
      renderGrid();
      show('grid');
    }).catch(function (e) {
      loading = false;
      el('lsGrid').innerHTML = '<div class="ls-alert">' + esc(e.message) + '</div>';
    });
  }

  function skeleton() {
    var s = '<div class="ls-cards">';
    for (var i = 0; i < 8; i++) s += '<div class="ls-card ls-skel"><div class="ls-shot"></div>' +
      '<div class="ls-sk-line"></div><div class="ls-sk-line short"></div></div>';
    return s + '</div>';
  }

  function renderGrid() {
    if (!products.length) {
      el('lsGrid').innerHTML = '<div class="ls-empty">Nothing here matches that.</div>';
      return;
    }

    var h = '<div class="ls-cards">';
    products.forEach(function (p) {
      h += '<article class="ls-card' + (p.available ? '' : ' out') + '" ' +
             'data-handle="' + esc(p.handle) + '" tabindex="0">' +
        '<div class="ls-shot">' +
          (p.image
            ? '<img src="' + esc(p.image) + '" alt="' + esc(p.alt) + '" loading="lazy">' +
              (p.hover ? '<img class="ls-hover" src="' + esc(p.hover) + '" alt="" loading="lazy">' : '')
            : '<div class="ls-noshot"></div>') +
          (p.available ? '' : '<span class="ls-out">Sold out</span>') +
          (p.was ? '<span class="ls-sale">Sale</span>' : '') +
        '</div>' +
        '<h3 class="ls-title">' + esc(p.title) + '</h3>' +
        '<div class="ls-price">' +
          (p.varies ? '<span class="ls-from">from</span> ' : '') +
          money(p.price) +
          (p.was ? ' <s>' + money(p.was) + '</s>' : '') +
        '</div>' +
      '</article>';
    });
    h += '</div>';

    if (more) {
      h += '<div class="ls-more"><button type="button" class="ls-btn" id="lsMore">' +
           'Show more</button></div>';
    }

    el('lsGrid').innerHTML = h;
    if (el('lsMore')) el('lsMore').onclick = function () { loadGrid(false); };
  }

  /* ═══ ONE PRODUCT ═════════════════════════════════════════════════════ */
  window.lsOpen = function (handle) {
    el('lsProduct').innerHTML = '<div class="ls-loading">Loading…</div>';
    show('product');

    api('action=product&handle=' + encodeURIComponent(handle)).then(function (d) {
      current = d.product;
      chosen = {};

      /* Start on the first variant that can actually be bought, so the page
         does not open on a sold-out size and look broken. */
      var first = current.variants.filter(function (v) { return v.available; })[0]
               || current.variants[0];
      if (first) chosen = Object.assign({}, first.options);

      renderProduct();
    }).catch(function (e) {
      el('lsProduct').innerHTML = '<div class="ls-alert">' + esc(e.message) + '</div>';
    });
  };

  function matchVariant() {
    if (!current) return null;
    return current.variants.filter(function (v) {
      return Object.keys(chosen).every(function (k) { return v.options[k] === chosen[k]; });
    })[0] || null;
  }

  /* Whether a given option value leads anywhere buyable — so a sold-out size
     can be shown as unavailable rather than silently failing on Add. */
  function valueAvailable(name, value) {
    var test = Object.assign({}, chosen);
    test[name] = value;
    return current.variants.some(function (v) {
      return v.available && Object.keys(test).every(function (k) { return v.options[k] === test[k]; });
    });
  }

  function renderProduct() {
    var p = current;
    var v = matchVariant();

    var h = '<div class="ls-detail">';

    h += '<div class="ls-gallery">';
    if (p.images.length) {
      h += '<img class="ls-main" id="lsMain" src="' +
           esc((v && v.image) || p.images[0].url) + '" alt="' + esc(p.images[0].alt) + '">';
      if (p.images.length > 1) {
        h += '<div class="ls-thumbs">';
        p.images.forEach(function (im, i) {
          h += '<button type="button" class="ls-thumb' + (i === 0 ? ' on' : '') +
               '" data-img="' + esc(im.url) + '"><img src="' + esc(im.url) +
               '" alt="" loading="lazy"></button>';
        });
        h += '</div>';
      }
    } else {
      h += '<div class="ls-noshot big"></div>';
    }
    h += '</div>';

    h += '<div class="ls-info">' +
      '<h2>' + esc(p.title) + '</h2>' +
      '<div class="ls-pprice" id="lsPPrice">' +
        (v ? money(v.price) + (v.was ? ' <s>' + money(v.was) + '</s>' : '') : '—') +
      '</div>';

    (p.options || []).forEach(function (opt) {
      if (opt.values.length < 2 && opt.name === 'Title') return;
      h += '<div class="ls-opt"><label>' + esc(opt.name) + '</label><div class="ls-vals">';
      opt.values.forEach(function (val) {
        var can = valueAvailable(opt.name, val);
        h += '<button type="button" class="ls-val' +
             (chosen[opt.name] === val ? ' on' : '') + (can ? '' : ' gone') +
             '" data-opt="' + esc(opt.name) + '" data-val="' + esc(val) + '">' +
             esc(val) + '</button>';
      });
      h += '</div></div>';
    });

    var can = v && v.available;
    h += '<div class="ls-buy">' +
      '<button type="button" class="ls-btn go" id="lsAdd"' + (can ? '' : ' disabled') + '>' +
        (can ? 'Add to bag' : (v ? 'Sold out' : 'Choose an option')) +
      '</button></div>';

    if (p.description) {
      h += '<details class="ls-desc" open><summary>Details</summary>' +
           '<div class="ls-desc-body">' + p.description + '</div></details>';
    }

    h += '</div></div>';

    el('lsProduct').innerHTML = h;
    if (el('lsAdd')) el('lsAdd').onclick = addToBag;
  }

  window.lsPick = function (name, value) {
    chosen[name] = value;
    renderProduct();
  };

  function addToBag() {
    var v = matchVariant();
    if (!v || !v.available) return;

    var line = bag.filter(function (l) { return l.variantId === v.id; })[0];
    if (line) line.qty++;
    else {
      bag.push({
        variantId: v.id,
        title: current.title,
        variant: v.title === 'Default Title' ? '' : v.title,
        price: parseFloat(v.price),
        image: v.image || (current.images[0] && current.images[0].url) || '',
        qty: 1
      });
    }
    saveBag();

    var b = el('lsAdd');
    b.textContent = 'Added';
    b.classList.add('added');
    setTimeout(function () {
      b.textContent = 'Add to bag';
      b.classList.remove('added');
    }, 1300);
  }

  /* ═══ THE BAG ═════════════════════════════════════════════════════════ */
  window.lsBag = function () {
    renderBag();
    show('bag');
  };

  function renderBag() {
    if (!bag.length) {
      el('lsBag').innerHTML = '<div class="ls-empty">Your bag is empty.' +
        '<br><button type="button" class="ls-btn" style="margin-top:16px" ' +
        'onclick="lsBack()">Keep looking</button></div>';
      return;
    }

    var h = '<div class="ls-baglist">';
    bag.forEach(function (l, i) {
      h += '<div class="ls-line">' +
        (l.image ? '<img src="' + esc(l.image) + '" alt="">' : '<div class="ls-noshot sm"></div>') +
        '<div class="ls-lmid">' +
          '<div class="ls-lname">' + esc(l.title) + '</div>' +
          (l.variant ? '<div class="ls-lvar">' + esc(l.variant) + '</div>' : '') +
          '<div class="ls-qty">' +
            '<button type="button" data-qty="' + i + '" data-d="-1">&minus;</button>' +
            '<span>' + l.qty + '</span>' +
            '<button type="button" data-qty="' + i + '" data-d="1">+</button>' +
          '</div>' +
        '</div>' +
        '<div class="ls-lright">' +
          '<div class="ls-lprice">' + money(l.price * l.qty) + '</div>' +
          '<button type="button" class="ls-remove" data-rm="' + i + '">Remove</button>' +
        '</div>' +
      '</div>';
    });
    h += '</div>';

    h += '<div class="ls-sum">' +
      '<div class="ls-sumrow"><span>Subtotal</span><b>' + money(bagTotal()) + '</b></div>' +
      '<div class="ls-note">Shipping and tax are worked out at checkout.</div>' +
      '<button type="button" class="ls-btn go big" id="lsCheckout">Checkout</button>' +
      '<button type="button" class="ls-btn plain" onclick="lsBack()">Keep looking</button>' +
      '<div class="ls-msg" id="lsMsg"></div>' +
    '</div>';

    el('lsBag').innerHTML = h;
    el('lsCheckout').onclick = checkout;
  }

  window.lsQty = function (i, d) {
    if (!bag[i]) return;
    bag[i].qty += d;
    if (bag[i].qty < 1) bag.splice(i, 1);
    saveBag();
    renderBag();
  };

  window.lsRemove = function (i) {
    bag.splice(i, 1);
    saveBag();
    renderBag();
  };

  /* ═══ CHECKOUT ════════════════════════════════════════════════════════
     Shopify takes the payment. Card details never touch our server, which is
     the whole reason for handing this step over. */
  function checkout() {
    var btn = el('lsCheckout');
    var msg = el('lsMsg');
    btn.disabled = true;
    msg.textContent = 'Preparing your checkout…';
    msg.className = 'ls-msg';

    api('action=checkout', {
      lines: bag.map(function (l) { return { variantId: l.variantId, quantity: l.qty }; }),
      ref: refGet()
    }).then(function (d) {
      msg.textContent = 'Taking you to secure checkout…';
      /* The tab is opened from inside the click that started this, so it is
         not treated as a pop-up. */
      window.location.href = d.url;
    }).catch(function (e) {
      btn.disabled = false;
      msg.textContent = e.message;
      msg.className = 'ls-msg bad';
    });
  }

  /* one listener; handles and option values are data, never built into code */
  document.addEventListener('click', function (ev) {
    var opener = ev.target.closest('[data-mb-shop]');
    if (opener) {
      ev.preventDefault();
      var code = opener.getAttribute('data-mb-shop');
      if (code) refSet(code);
      mbShop();
      return;
    }

    var cat = ev.target.closest('[data-cat]');
    if (cat) { lsCat(cat.getAttribute('data-cat')); return; }

    var card = ev.target.closest('.ls-card[data-handle]');
    if (card) { lsOpen(card.getAttribute('data-handle')); return; }

    var val = ev.target.closest('[data-opt]');
    if (val) { lsPick(val.getAttribute('data-opt'), val.getAttribute('data-val')); return; }

    var th = ev.target.closest('.ls-thumb[data-img]');
    if (th) {
      el('lsMain').src = th.getAttribute('data-img');
      document.querySelectorAll('.ls-thumb').forEach(function (t) { t.classList.remove('on'); });
      th.classList.add('on');
      return;
    }

    var q = ev.target.closest('[data-qty]');
    if (q) { lsQty(+q.getAttribute('data-qty'), +q.getAttribute('data-d')); return; }

    var rm = ev.target.closest('[data-rm]');
    if (rm) { lsRemove(+rm.getAttribute('data-rm')); return; }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    var card = ev.target.closest && ev.target.closest('.ls-card[data-handle]');
    if (card) lsOpen(card.getAttribute('data-handle'));
  });

  var SHELL =
    '<div class="ls-overlay" id="lsOverlay">' +
      '<div class="ls-box" role="dialog" aria-modal="true" aria-label="Boutique">' +

        '<header class="ls-head">' +
          '<button type="button" class="ls-icon" id="lsMenu" onclick="lsMenu()" ' +
            'aria-label="Categories">&#9776;</button>' +
          '<button type="button" class="ls-icon" id="lsBack" onclick="lsBack()" ' +
            'aria-label="Back" style="display:none">&#8592;</button>' +
          '<div class="ls-brand">Models <span>Boutique</span></div>' +
          '<div class="ls-tools">' +
            '<input id="lsSearch" class="ls-search" placeholder="Search" aria-label="Search">' +
            '<button type="button" class="ls-icon bag" onclick="lsBag()" aria-label="Bag">' +
              '&#128717;<span class="ls-count" id="lsCount" style="display:none">0</span>' +
            '</button>' +
            '<button type="button" class="ls-icon" onclick="mbShopClose()" ' +
              'aria-label="Close">&times;</button>' +
          '</div>' +
        '</header>' +

        '<nav class="ls-drawer" id="lsDrawer" aria-label="Categories">' +
          '<div class="ls-dhead">Shop by' +
            '<button type="button" class="ls-icon" onclick="lsMenu()" ' +
              'aria-label="Close">&times;</button></div>' +
          '<div class="ls-dlist" id="lsCats">' +
            '<div class="ls-dloading">Loading…</div>' +
          '</div>' +
        '</nav>' +
        '<div class="ls-scrim" id="lsScrim" onclick="lsMenu()"></div>' +

        '<div class="ls-body" id="lsBody">' +
          '<div id="lsGrid"></div>' +
          '<div id="lsProduct" style="display:none"></div>' +
          '<div id="lsBag" style="display:none"></div>' +
        '</div>' +

        '<footer class="ls-foot">' +
          '<span>Models Boutique</span>' +
          '<span class="ls-secure">&#128274; Secure checkout by Shopify' +
            '<span class="ls-ref" id="lsRef" style="display:none"></span></span>' +
        '</footer>' +

      '</div>' +
    '</div>';

  var CSS = [
    '.ls-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:9000;display:none;align-items:center;justify-content:center;padding:0}',
    '.ls-overlay.on{display:flex}',
    '.ls-box{position:relative;background:#0f1115;color:#e9eaee;width:100%;max-width:1180px;height:100%;max-height:100vh;display:flex;flex-direction:column;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}',
    '@media(min-width:900px){.ls-box{height:92vh;border-radius:16px;overflow:hidden;border:1px solid #242832}}',

    '.ls-head{flex:0 0 auto;display:flex;align-items:center;gap:12px;padding:13px 18px;background:#171a21;border-bottom:1px solid #242832}',
    '.ls-brand{font-size:17px;font-weight:800;letter-spacing:-.2px;flex:1;white-space:nowrap}',
    '.ls-brand span{color:#d8a94b;font-weight:400}',
    '.ls-tools{display:flex;align-items:center;gap:8px}',
    '.ls-search{background:#12151b;border:1px solid #333846;border-radius:9px;padding:9px 12px;color:#e9eaee;font-size:14px;outline:none;font-family:inherit;width:170px}',
    '.ls-search:focus{border-color:#d8a94b;width:220px}',
    '@media(max-width:620px){.ls-search{width:110px}.ls-search:focus{width:130px}.ls-brand{font-size:15px}}',
    '.ls-icon{position:relative;background:none;border:none;color:#b9bcc6;font-size:21px;cursor:pointer;padding:5px 8px;line-height:1;font-family:inherit}',
    '.ls-icon:hover{color:#d8a94b}',
    '.ls-count{position:absolute;top:-1px;right:-1px;background:#d8a94b;color:#14161b;font-size:10px;font-weight:800;min-width:17px;height:17px;border-radius:9px;line-height:17px;text-align:center;padding:0 4px}',

    '.ls-body{flex:1 1 auto;overflow-y:auto;padding:20px 18px;-webkit-overflow-scrolling:touch}',
    '.ls-drawer{position:absolute;left:0;top:0;bottom:0;width:270px;max-width:82vw;background:#171a21;border-right:1px solid #242832;z-index:20;transform:translateX(-102%);transition:transform .24s ease;display:flex;flex-direction:column}',
    '.ls-drawer.on{transform:translateX(0)}',
    '.ls-dhead{display:flex;justify-content:space-between;align-items:center;padding:15px 16px;border-bottom:1px solid #242832;font-size:10.5px;color:#8a8fa8;text-transform:uppercase;letter-spacing:.09em;font-weight:800}',
    '.ls-dlist{flex:1;overflow-y:auto;padding:10px}',
    '.ls-cat{display:block;width:100%;text-align:left;background:none;border:none;border-radius:9px;color:#c0c4d0;padding:12px 13px;font-size:14.5px;cursor:pointer;font-family:inherit}',
    '.ls-cat:hover{background:#1e222b;color:#d8a94b}',
    '.ls-cat.on{background:rgba(216,169,75,.13);color:#d8a94b;font-weight:700}',
    '.ls-dloading{padding:16px 13px;color:#5a6070;font-size:13px}',
    '.ls-scrim{position:absolute;inset:0;background:rgba(0,0,0,.5);z-index:15;opacity:0;pointer-events:none;transition:opacity .24s}',
    '.ls-scrim.on{opacity:1;pointer-events:auto}',

    '.ls-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:20px}',
    '@media(max-width:620px){.ls-cards{grid-template-columns:1fr 1fr;gap:13px}.ls-body{padding:14px}}',
    '.ls-card{cursor:pointer;outline:none}',
    '.ls-card:focus-visible .ls-shot{outline:2px solid #d8a94b;outline-offset:2px}',
    '.ls-shot{position:relative;aspect-ratio:3/4;background:#171a21;border-radius:12px;overflow:hidden;margin-bottom:10px}',
    '.ls-shot img{width:100%;height:100%;object-fit:cover;display:block;transition:opacity .35s,transform .5s}',
    '.ls-hover{position:absolute;inset:0;opacity:0}',
    '.ls-card:hover .ls-hover{opacity:1}',
    '.ls-card:hover .ls-shot img{transform:scale(1.03)}',
    '.ls-card.out .ls-shot img{opacity:.42}',
    '.ls-noshot{width:100%;height:100%;background:linear-gradient(135deg,#171a21,#1e222b)}',
    '.ls-noshot.big{aspect-ratio:3/4;border-radius:12px}',
    '.ls-noshot.sm{width:64px;height:64px;border-radius:8px;flex:0 0 64px}',
    '.ls-out{position:absolute;left:10px;top:10px;background:rgba(15,17,21,.9);color:#e9eaee;font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:5px 10px;border-radius:6px}',
    '.ls-sale{position:absolute;right:10px;top:10px;background:#d8a94b;color:#14161b;font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:5px 10px;border-radius:6px}',
    '.ls-title{font-size:14px;font-weight:600;margin:0 0 4px;line-height:1.4}',
    '.ls-price{font-size:14px;color:#d8a94b;font-weight:700}',
    '.ls-price s{color:#5a6070;font-weight:400;margin-left:5px}',
    '.ls-from{color:#8a8fa8;font-size:11px;font-weight:400}',
    '.ls-more{text-align:center;padding:26px 0 6px}',

    '.ls-skel .ls-shot{animation:lsPulse 1.4s ease-in-out infinite}',
    '.ls-sk-line{height:11px;background:#171a21;border-radius:4px;margin-bottom:6px;animation:lsPulse 1.4s ease-in-out infinite}',
    '.ls-sk-line.short{width:45%}',
    '@keyframes lsPulse{0%,100%{opacity:1}50%{opacity:.45}}',

    '.ls-detail{display:grid;grid-template-columns:1fr 1fr;gap:30px;max-width:960px;margin:0 auto}',
    '@media(max-width:780px){.ls-detail{grid-template-columns:1fr;gap:20px}}',
    '.ls-main{width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:12px;display:block;background:#171a21}',
    '.ls-thumbs{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}',
    '.ls-thumb{width:60px;height:74px;border:1.5px solid transparent;border-radius:8px;overflow:hidden;background:none;padding:0;cursor:pointer}',
    '.ls-thumb.on{border-color:#d8a94b}',
    '.ls-thumb img{width:100%;height:100%;object-fit:cover;display:block}',
    '.ls-info h2{font-size:23px;font-weight:700;margin:0 0 8px;line-height:1.25}',
    '.ls-pprice{font-size:21px;color:#d8a94b;font-weight:800;margin-bottom:22px}',
    '.ls-pprice s{color:#5a6070;font-size:15px;font-weight:400;margin-left:7px}',
    '.ls-opt{margin-bottom:17px}',
    '.ls-opt label{display:block;font-size:10px;color:#8a8fa8;text-transform:uppercase;letter-spacing:.07em;font-weight:700;margin-bottom:8px}',
    '.ls-vals{display:flex;gap:7px;flex-wrap:wrap}',
    '.ls-val{background:#12151b;border:1px solid #333846;border-radius:8px;color:#c0c4d0;padding:10px 15px;font-size:13.5px;cursor:pointer;font-family:inherit;min-width:46px}',
    '.ls-val:hover{border-color:#d8a94b}',
    '.ls-val.on{background:#d8a94b;border-color:#d8a94b;color:#14161b;font-weight:700}',
    '.ls-val.gone{opacity:.34;text-decoration:line-through}',
    '.ls-buy{margin:24px 0 18px}',
    '.ls-desc{border-top:1px solid #242832;padding-top:14px}',
    '.ls-desc summary{cursor:pointer;font-size:11px;color:#8a8fa8;text-transform:uppercase;letter-spacing:.07em;font-weight:700;padding:5px 0}',
    '.ls-desc-body{font-size:13.5px;line-height:1.75;color:#b9bcc6;padding-top:8px}',
    '.ls-desc-body img{max-width:100%;height:auto}',

    '.ls-btn{background:transparent;border:1px solid #333846;border-radius:10px;color:#c0c4d0;padding:12px 22px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}',
    '.ls-btn:hover{border-color:#d8a94b;color:#d8a94b}',
    '.ls-btn.go{background:#d8a94b;border-color:#d8a94b;color:#14161b;width:100%;padding:15px}',
    '.ls-btn.go:hover{background:#e6bb63;color:#14161b}',
    '.ls-btn.go.added{background:#4ec97a;border-color:#4ec97a}',
    '.ls-btn:disabled{opacity:.45;cursor:not-allowed}',
    '.ls-btn.big{font-size:15.5px;margin-bottom:9px}',
    '.ls-btn.plain{width:100%;border-color:transparent;color:#8a8fa8}',

    '.ls-baglist{max-width:640px;margin:0 auto}',
    '.ls-line{display:flex;gap:13px;padding:15px 0;border-bottom:1px solid #1e222b}',
    '.ls-line img{width:64px;height:80px;object-fit:cover;border-radius:8px;flex:0 0 64px}',
    '.ls-lmid{flex:1;min-width:0}',
    '.ls-lname{font-size:14.5px;font-weight:600;margin-bottom:2px}',
    '.ls-lvar{font-size:12px;color:#8a8fa8;margin-bottom:9px}',
    '.ls-qty{display:inline-flex;align-items:center;border:1px solid #333846;border-radius:8px;overflow:hidden}',
    '.ls-qty button{background:none;border:none;color:#c0c4d0;width:32px;height:32px;font-size:15px;cursor:pointer;font-family:inherit}',
    '.ls-qty button:hover{background:#1e222b;color:#d8a94b}',
    '.ls-qty span{min-width:30px;text-align:center;font-size:13.5px;font-weight:700}',
    '.ls-lright{text-align:right;flex:0 0 auto}',
    '.ls-lprice{font-size:14.5px;color:#d8a94b;font-weight:700;margin-bottom:8px}',
    '.ls-remove{background:none;border:none;color:#5a6070;font-size:11.5px;cursor:pointer;text-decoration:underline;font-family:inherit;padding:0}',
    '.ls-remove:hover{color:#f2685e}',
    '.ls-sum{max-width:640px;margin:22px auto 0;padding-top:18px;border-top:2px solid #d8a94b}',
    '.ls-sumrow{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;font-size:15px}',
    '.ls-sumrow b{font-size:22px;color:#d8a94b}',
    '.ls-note{font-size:12px;color:#5a6070;margin-bottom:16px}',
    '.ls-msg{font-size:13px;color:#8a8fa8;margin-top:11px;min-height:18px;text-align:center}',
    '.ls-msg.bad{color:#f2685e}',

    '.ls-foot{flex:0 0 auto;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 18px;background:#171a21;border-top:1px solid #242832;font-size:11px;color:#5a6070}',
    '.ls-secure{color:#8a8fa8}',
    '.ls-ref{color:#d8a94b;margin-left:9px}',

    '.ls-empty{text-align:center;padding:60px 20px;color:#5a6070;font-size:14.5px}',
    '.ls-loading{text-align:center;padding:60px 20px;color:#5a6070;font-size:13.5px}',
    '.ls-alert{max-width:520px;margin:40px auto;background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);color:#ffb3ac;border-radius:11px;padding:15px 18px;font-size:13.5px;line-height:1.6}'
  ].join('');

})();
