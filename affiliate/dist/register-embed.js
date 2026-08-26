/* ═══════════════════════════════════════════════════════════════════════════
   register-embed.js — the sign-up form, sized for a column.

   Same five steps as the standalone page, built narrow: one field per row,
   stacked options, smaller type. It fits a half-column on a desktop and the
   whole width of a phone without either looking like a compromise.

   Drop <div id="mbRegister"></div> where it should appear and load this after
   it. Everything else — styling, steps, uploads — comes with it.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var API = '/api/register.php';
  var step = 1, LAST = 5;
  var files = { profile: null, idphoto: null };

  function el(id) { return document.getElementById(id); }
  function v(id) { var e = el(id); return e ? e.value.trim() : ''; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function say(text, cls) {
    var m = el('rgMsg');
    if (m) { m.textContent = text || ''; m.className = 'rg-msg ' + (cls || ''); }
  }

  /* ═══ MARKUP ══════════════════════════════════════════════════════════ */
  var HTML =
  '<div class="rg">' +

    '<div class="rg-head">' +
      '<h3>Start selling</h3>' +
      '<p>Free to join. No stock to buy.</p>' +
      '<div class="rg-rail" id="rgRail">' +
        '<div class="on"></div><div></div><div></div><div></div><div></div>' +
      '</div>' +
    '</div>' +

    /* ── 1 ── */
    '<section class="rg-step" data-s="1">' +
      '<div class="rg-no">Step 1 of 5 &middot; Hosting</div>' +
      '<div class="rg-picks">' +
        '<label class="rg-pick" data-g="hosting">' +
          '<input type="radio" name="hosting" value="managed">' +
          '<b><i class="bi bi-shield-check"></i>Managed Hosting</b>' +
          '<span class="rg-price">$29.99 a month</span>' +
          '<span class="rg-sub">We build it, host it and keep it running. ' +
            'Save 5% paying quarterly.</span>' +
        '</label>' +
        '<label class="rg-pick" data-g="hosting">' +
          '<input type="radio" name="hosting" value="diy">' +
          '<b><i class="bi bi-download"></i>Host On Your Site</b>' +
          '<span class="rg-price">Free</span>' +
          '<span class="rg-sub">Download your finished site and put it on your ' +
            'own hosting.</span>' +
        '</label>' +
        /* A real subscription at a nominal price, so the whole path — card,
           webhook, provisioning — can be walked through without refunding
           thirty dollars each time. Remove this option when testing is done. */
        '<label class="rg-pick rg-test" data-g="hosting">' +
          '<input type="radio" name="hosting" value="test">' +
          '<b><i class="bi bi-wrench"></i>Test subscription</b>' +
          '<span class="rg-price">$2.00 a year</span>' +
          '<span class="rg-sub">For trying the system out. Same as managed hosting, ' +
            'at a nominal price.</span>' +
        '</label>' +
      '</div>' +
      '<button type="button" class="rg-btn go" data-next>Continue</button>' +
    '</section>' +

    /* ── 2 ── */
    '<section class="rg-step" data-s="2" hidden>' +
      '<div class="rg-no">Step 2 of 5 &middot; Your details</div>' +
      '<label class="rg-l">Store name</label>' +
      '<input id="rgStore" type="text" placeholder="Bella\'s Boutique">' +
      '<div class="rg-two">' +
        '<div><label class="rg-l">First name</label><input id="rgFirst" type="text"></div>' +
        '<div><label class="rg-l">Last name</label><input id="rgLast" type="text"></div>' +
      '</div>' +
      '<label class="rg-l">Email</label>' +
      '<input id="rgEmail" type="email" autocomplete="email">' +
      '<label class="rg-l">Phone</label>' +
      '<input id="rgPhone" type="tel" autocomplete="tel">' +
      '<label class="rg-l">Choose a password</label>' +
      '<input id="rgPass" type="password" autocomplete="new-password" ' +
        'placeholder="At least 8 characters">' +
      '<div class="rg-hint">This is how you sign in to see your sales and get paid.</div>' +
      '<label class="rg-l">Street address</label>' +
      '<input id="rgStreet" type="text" autocomplete="address-line1">' +
      '<div class="rg-two">' +
        '<div><label class="rg-l">City</label><input id="rgCity" type="text"></div>' +
        '<div><label class="rg-l">State</label><input id="rgState" type="text"></div>' +
      '</div>' +
      '<div class="rg-two">' +
        '<div><label class="rg-l">Postcode</label><input id="rgZip" type="text"></div>' +
        '<div><label class="rg-l">Country</label>' +
          '<select id="rgCountry">' +
            '<option>United States</option><option>Canada</option>' +
            '<option>United Kingdom</option><option>Australia</option>' +
            '<option>Other</option></select></div>' +
      '</div>' +
      '<label class="rg-chk"><input type="checkbox" id="rgSms">' +
        '<span>Text me about my account and payouts. Reply STOP to end.</span></label>' +
      '<div class="rg-btns">' +
        '<button type="button" class="rg-btn" data-back>Back</button>' +
        '<button type="button" class="rg-btn go" data-next>Continue</button>' +
      '</div>' +
    '</section>' +

    /* ── 3 ── */
    '<section class="rg-step" data-s="3" hidden>' +
      '<div class="rg-no">Step 3 of 5 &middot; Your look</div>' +
      '<div class="rg-tpls">' +
        '<label class="rg-tpl" data-g="template">' +
          '<input type="radio" name="template" value="template1">' +
          '<span class="rg-shot"><i class="b"></i><i class="band"></i>' +
            '<i class="r"></i></span>' +
          '<b>Classic</b>' +
          '<a href="/example.html" target="_blank" rel="noopener">Preview &rarr;</a>' +
        '</label>' +
        '<label class="rg-tpl" data-g="template">' +
          '<input type="radio" name="template" value="template2">' +
          '<span class="rg-shot"><i class="b"></i><i class="band wide"></i>' +
            '<i class="r"></i></span>' +
          '<b>Banner</b>' +
          '<a href="/example2.html" target="_blank" rel="noopener">Preview &rarr;</a>' +
        '</label>' +
      '</div>' +
      '<div class="rg-btns">' +
        '<button type="button" class="rg-btn" data-back>Back</button>' +
        '<button type="button" class="rg-btn go" data-next>Continue</button>' +
      '</div>' +
    '</section>' +

    /* ── 4 ── */
    '<section class="rg-step" data-s="4" hidden>' +
      '<div class="rg-no">Step 4 of 5 &middot; Photographs</div>' +
      '<div class="rg-ups">' +
        '<div class="rg-up" id="rgUpP">' +
          '<input type="file" id="rgProfile" accept="image/*">' +
          '<label for="rgProfile"><i class="bi bi-person-circle"></i>' +
            '<b>Your photo</b></label>' +
          '<img id="rgProfilePrev" alt="">' +
          '<button type="button" class="rg-x" data-clear="rgProfile">&times;</button>' +
        '</div>' +
        '<div class="rg-up" id="rgUpI">' +
          '<input type="file" id="rgIdphoto" accept="image/*" capture="environment">' +
          '<label for="rgIdphoto"><i class="bi bi-card-image"></i>' +
            '<b>Photo ID</b></label>' +
          '<img id="rgIdphotoPrev" alt="">' +
          '<button type="button" class="rg-x" data-clear="rgIdphoto">&times;</button>' +
        '</div>' +
      '</div>' +
      '<p class="rg-note">Your ID is kept privately and never appears on your site. ' +
        'It is how we confirm who you are before paying you.</p>' +
      '<div class="rg-btns">' +
        '<button type="button" class="rg-btn" data-back>Back</button>' +
        '<button type="button" class="rg-btn go" data-next>Continue</button>' +
      '</div>' +
    '</section>' +

    /* ── 5 ── */
    '<section class="rg-step" data-s="5" hidden>' +
      '<div class="rg-no">Step 5 of 5 &middot; The agreement</div>' +
      '<div class="rg-terms">' +
        '<h4>What you earn</h4>' +
        '<p>You earn commission on the product value of every order placed through ' +
          'your site, before shipping and tax. A customer who arrives through your ' +
          'link is credited to you for 30 days, so a sale made days later still ' +
          'counts.</p>' +
        '<h4>When you are paid</h4>' +
        '<p>Commission is earned once an order is paid for and not refunded. Payouts ' +
          'are recorded in your dashboard so you can see what has been paid and what ' +
          'is still owed.</p>' +
        '<h4>Your site</h4>' +
        '<p>The site is yours to run. You may not present it as being operated by ' +
          'anyone other than yourself, and you are responsible for anything you add ' +
          'to it.</p>' +
        '<h4>What we do</h4>' +
        '<p>We hold the stock, take payments, ship orders and handle returns and ' +
          'customer service. Product photographs and descriptions are provided for ' +
          'you to use while you are an active affiliate.</p>' +
        '<h4>Ending it</h4>' +
        '<p>Either of us may end this at any time. Commission already earned on ' +
          'settled orders is still paid. Accounts may be closed for misrepresenting ' +
          'the business or for fraudulent orders.</p>' +
        '<h4>Your details</h4>' +
        '<p>We keep your name, address, contact details and ID in order to run your ' +
          'account and pay you. Your ID is never published.</p>' +
      '</div>' +
      '<label class="rg-chk"><input type="checkbox" id="rgAgree">' +
        '<span>I have read the agreement and I agree to it.</span></label>' +
      '<label class="rg-chk"><input type="checkbox" id="rgAge">' +
        '<span>I am 18 or older and these are my own details.</span></label>' +
      '<div class="rg-btns">' +
        '<button type="button" class="rg-btn" data-back>Back</button>' +
        '<button type="button" class="rg-btn go" id="rgFinish">Finish</button>' +
      '</div>' +
    '</section>' +

    /* ── after ── */
    '<section class="rg-done" id="rgDone" hidden>' +
      '<div class="rg-tick">&#10003;</div>' +
      '<h3 id="rgDoneTitle"></h3>' +
      '<p id="rgDoneText"></p>' +
      '<div class="rg-code"><small>Your affiliate code</small><b id="rgCode"></b></div>' +
      '<div id="rgAction"></div>' +
      '<p class="rg-after" id="rgAfter"></p>' +
    '</section>' +

    '<div class="rg-msg" id="rgMsg"></div>' +
  '</div>';

  /* ═══ STYLE ═══════════════════════════════════════════════════════════
     Every rule prefixed and scoped to .rg, because this drops into a page
     that has its own opinions about buttons, inputs and labels. */
  var CSS = [
    '.rg{background:#111622;border:1px solid #1e2330;border-radius:14px;padding:20px;',
      'font-family:"DM Sans",system-ui,-apple-system,sans-serif;color:#e8eaf0;',
      'font-size:14px;line-height:1.55;text-align:left}',
    '.rg *{box-sizing:border-box}',
    '.rg-head{margin-bottom:16px}',
    '.rg-head h3{font-size:19px;font-weight:700;margin:0 0 3px;color:#e8eaf0;letter-spacing:-.3px}',
    '.rg-head p{font-size:13px;color:#8a8fa8;margin:0 0 13px}',
    '.rg-rail{display:flex;gap:4px}',
    '.rg-rail div{flex:1;height:3px;background:#1e2330;border-radius:2px;transition:background .3s}',
    '.rg-rail div.on{background:#d4a830}',
    '.rg-no{font-size:9.5px;color:#d4a830;text-transform:uppercase;letter-spacing:.1em;',
      'font-weight:700;margin-bottom:12px}',

    /* choices, stacked because the column is narrow */
    '.rg-picks{display:grid;gap:9px;margin-bottom:4px}',
    '.rg-pick{display:block;position:relative;border:1.5px solid #2a3040;border-radius:11px;',
      'padding:14px;cursor:pointer;transition:border-color .18s,background .18s;margin:0}',
    '.rg-pick:hover{border-color:rgba(212,168,48,.55)}',
    '.rg-pick.on{border-color:#d4a830;background:rgba(212,168,48,.07)}',
    '.rg-pick input{position:absolute;opacity:0;pointer-events:none}',
    '.rg-pick b{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;',
      'margin-bottom:3px}',
    '.rg-pick b i{color:#d4a830;font-size:16px}',
    '.rg-price{display:block;color:#d4a830;font-weight:700;font-size:13.5px;margin-bottom:5px}',
    '.rg-sub{display:block;font-size:12.5px;color:#8a8fa8;line-height:1.6}',

    /* fields */
    '.rg-l{display:block;font-size:9.5px;color:#8a8fa8;text-transform:uppercase;',
      'letter-spacing:.08em;font-weight:700;margin:11px 0 5px}',
    '.rg input[type=text],.rg input[type=email],.rg input[type=tel],',
      'width:100%;background:#0e1219;border:1px solid #2a3040;border-radius:9px;',
      'padding:11px 12px;color:#e8eaf0;font-size:16px;outline:none;font-family:inherit;',
      'margin:0}',
    '.rg input:focus,.rg select:focus{border-color:#d4a830}',
    '.rg input::placeholder{color:#4a5060}',
    '.rg-two{display:grid;grid-template-columns:1fr 1fr;gap:9px}',
    '.rg-two .rg-l{margin-top:11px}',

    '.rg-chk{display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:#b9bcc6;',
      'line-height:1.55;cursor:pointer;padding:11px 0;margin:0}',
    '.rg-chk input{width:19px;height:19px;flex:0 0 19px;margin-top:1px;accent-color:#d4a830}',

    /* templates side by side even here — two is few enough */
    '.rg-tpls{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.rg-tpl{position:relative;border:1.5px solid #2a3040;border-radius:11px;padding:10px;',
      'cursor:pointer;transition:border-color .18s;text-align:center;margin:0}',
    '.rg-tpl:hover{border-color:rgba(212,168,48,.55)}',
    '.rg-tpl.on{border-color:#d4a830;background:rgba(212,168,48,.07)}',
    '.rg-tpl input{position:absolute;opacity:0;pointer-events:none}',
    /* a drawn impression: instant, and the real page is one tap away */
    '.rg-shot{display:flex;flex-direction:column;gap:4px;aspect-ratio:4/3;background:#0e1219;',
      'border-radius:7px;padding:7px;margin-bottom:8px}',
    '.rg-shot i{display:block;border-radius:2px;background:#2a3040}',
    '.rg-shot i.b{height:6px}',
    '.rg-shot i.band{flex:1;background:linear-gradient(135deg,rgba(212,168,48,.25),',
      'rgba(212,168,48,.06))}',
    '.rg-shot i.band.wide{flex:1.8}',
    '.rg-shot i.r{height:11px}',
    '.rg-tpl b{display:block;font-size:13px;margin-bottom:3px}',
    '.rg-tpl a{font-size:11.5px;color:#d4a830;text-decoration:none;font-weight:600}',
    '.rg-tpl a:hover{color:#e8bf44}',

    /* uploads */
    '.rg-ups{display:grid;grid-template-columns:1fr 1fr;gap:9px}',
    '.rg-up{position:relative}',
    '.rg-up input[type=file]{position:absolute;width:1px;height:1px;opacity:0}',
    '.rg-up label{display:flex;flex-direction:column;align-items:center;justify-content:center;',
      'gap:5px;min-height:104px;background:#0e1219;border:1.5px dashed #2a3040;',
      'border-radius:10px;cursor:pointer;padding:10px;text-align:center;margin:0}',
    '.rg-up label:hover{border-color:#d4a830}',
    '.rg-up label i{font-size:20px;color:#5a6070}',
    '.rg-up label b{font-size:12px;color:#b9bcc6;font-weight:600}',
    '.rg-up.has label{display:none}',
    '.rg-up img{display:none;width:100%;height:104px;object-fit:cover;border-radius:10px;',
      'border:1.5px solid #3fb950}',
    '.rg-up.has img{display:block}',
    '.rg-x{display:none;position:absolute;top:5px;right:5px;background:rgba(10,13,20,.9);',
      'border:1px solid #2a3040;border-radius:6px;color:#b9bcc6;font-size:14px;',
      'width:24px;height:24px;cursor:pointer;line-height:1;padding:0;font-family:inherit}',
    '.rg-up.has .rg-x{display:block}',

    '.rg-note{font-size:11.5px;color:#5a6070;line-height:1.6;margin:11px 0 0}',

    /* terms */
    '.rg-terms{background:#0e1219;border:1px solid #1e2330;border-radius:10px;padding:13px;',
      'max-height:170px;overflow-y:auto;font-size:12.5px;line-height:1.7;color:#b9bcc6}',
    '.rg-terms h4{font-size:11.5px;color:#d4a830;margin:12px 0 4px;font-weight:700}',
    '.rg-terms h4:first-child{margin-top:0}',
    '.rg-terms p{margin:0 0 8px}',

    /* buttons */
    '.rg-btns{display:flex;gap:8px;margin-top:16px}',
    '.rg-btn{border-radius:10px;padding:12px 18px;font-size:14px;font-weight:700;',
      'cursor:pointer;font-family:inherit;border:1px solid #2a3040;background:transparent;',
      'color:#b9bcc6;width:100%;margin-top:16px}',
    '.rg-btns .rg-btn{margin-top:0}',
    '.rg-btn:hover{border-color:#d4a830;color:#d4a830}',
    '.rg-btn.go{background:#d4a830;border-color:#d4a830;color:#0a0d14;flex:1.6}',
    '.rg-btn.go:hover{background:#e8bf44;color:#0a0d14}',
    '.rg-btn:disabled{opacity:.45;cursor:not-allowed}',

    '.rg-test{border-style:dashed}',
    '.rg-test b i{color:#8a8fa8}',
    '.rg-hint{font-size:11.5px;color:#5a6070;line-height:1.6;margin-top:5px}',
    '.rg-msg{font-size:12.5px;margin-top:11px;min-height:17px;line-height:1.55;color:#8a8fa8}',
    '.rg-msg.bad{color:#f2685e}',
    '.rg-msg.ok{color:#3fb950}',

    /* after */
    '.rg-done{text-align:center;padding:6px 0}',
    '.rg-tick{width:52px;height:52px;border-radius:50%;background:rgba(63,185,80,.12);',
      'border:2px solid #3fb950;color:#3fb950;font-size:25px;line-height:48px;margin:0 auto 14px}',
    '.rg-done h3{font-size:19px;margin:0 0 7px}',
    '.rg-done>p{font-size:13px;color:#b9bcc6;line-height:1.7;margin:0 0 15px}',
    '.rg-code{background:#0e1219;border:1px solid #d4a830;border-radius:10px;padding:13px;',
      'margin-bottom:15px}',
    '.rg-code small{display:block;font-size:9px;color:#8a8fa8;text-transform:uppercase;',
      'letter-spacing:.1em;font-weight:700;margin-bottom:4px}',
    '.rg-code b{font-size:21px;color:#d4a830;font-family:ui-monospace,Menlo,monospace;',
      'letter-spacing:.5px}',
    '.rg-dl{display:inline-flex;align-items:center;gap:8px;background:#d4a830;color:#0a0d14;',
      'border:none;border-radius:10px;padding:13px 24px;font-size:14.5px;font-weight:700;',
      'cursor:pointer;font-family:inherit;text-decoration:none}',
    '.rg-dl:hover{background:#e8bf44;color:#0a0d14}',
    '.rg-after{font-size:12px;color:#8a8fa8;line-height:1.7;margin-top:15px;text-align:left;',
      'background:rgba(212,168,48,.05);border-left:2px solid rgba(212,168,48,.4);',
      'border-radius:0 8px 8px 0;padding:11px 13px}',
    '.rg-after b{color:#d4a830}',

    /* a phone gets one column for the pairs */
    '@media(max-width:420px){.rg-two{grid-template-columns:1fr}}'
  ].join('');

  /* ═══ BEHAVIOUR ═══════════════════════════════════════════════════════ */
  var host = el('mbRegister');
  if (!host) return;

  var style = document.createElement('style');
  style.textContent = CSS;
  document.head.appendChild(style);
  host.innerHTML = HTML;

  function show(n) {
    host.querySelectorAll('.rg-step').forEach(function (s) {
      s.hidden = (+s.getAttribute('data-s') !== n);
    });
    host.querySelectorAll('#rgRail div').forEach(function (d, i) {
      d.classList.toggle('on', i < n);
    });
    step = n;
    say('');
    host.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  host.addEventListener('click', function (ev) {
    var pick = ev.target.closest('[data-g]');
    if (pick) {
      var g = pick.getAttribute('data-g');
      host.querySelectorAll('[data-g="' + g + '"]').forEach(function (o) {
        o.classList.remove('on');
      });
      pick.classList.add('on');
      pick.querySelector('input').checked = true;
      say('');
      return;
    }

    if (ev.target.closest('[data-next]')) { if (ok(step)) show(step + 1); return; }
    if (ev.target.closest('[data-back]')) { show(step - 1); return; }

    var x = ev.target.closest('[data-clear]');
    if (x) {
      var id = x.getAttribute('data-clear');
      var key = id === 'rgProfile' ? 'profile' : 'idphoto';
      files[key] = null;
      el(id).value = '';
      el(id === 'rgProfile' ? 'rgUpP' : 'rgUpI').classList.remove('has');
      return;
    }
  });

  function ok(n) {
    if (n === 1 && !host.querySelector('input[name=hosting]:checked')) {
      say('Choose one of the two.', 'bad'); return false;
    }

    if (n === 2) {
      var need = [['rgStore', 'your store name'], ['rgFirst', 'your first name'],
                  ['rgLast', 'your last name'], ['rgEmail', 'your email'],
                  ['rgPhone', 'your phone number'], ['rgStreet', 'your street address'],
                  ['rgCity', 'your city'], ['rgState', 'your state'], ['rgZip', 'your postcode'],
                  ['rgPass', 'a password']];
      for (var i = 0; i < need.length; i++) {
        if (!v(need[i][0])) {
          say('Please add ' + need[i][1] + '.', 'bad');
          el(need[i][0]).focus();
          return false;
        }
      }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v('rgEmail'))) {
        say('That email does not look right.', 'bad');
        el('rgEmail').focus();
        return false;
      }
      /* Eight characters, and nothing else demanded. Rules about capitals and
         symbols mostly produce Password1! written on a sticky note. */
      if (v('rgPass').length < 8) {
        say('Your password needs at least 8 characters.', 'bad');
        el('rgPass').focus();
        return false;
      }
    }

    if (n === 3 && !host.querySelector('input[name=template]:checked')) {
      say('Choose a look.', 'bad'); return false;
    }

    if (n === 4) {
      if (!files.profile) { say('Add a photo of yourself.', 'bad'); return false; }
      if (!files.idphoto) { say('Add a photo of your ID.', 'bad'); return false; }
    }

    return true;
  }

  /* photographs, scaled before sending — a phone camera makes several
     megabytes of detail nobody needs, and two of those is a slow upload */
  [['rgProfile', 'profile', 'rgUpP'], ['rgIdphoto', 'idphoto', 'rgUpI']].forEach(function (t) {
    el(t[0]).addEventListener('change', function () {
      var f = this.files && this.files[0];
      if (!f || !/^image\//.test(f.type)) { say('Choose a photo.', 'bad'); return; }

      var fr = new FileReader();
      fr.onload = function () {
        var img = new Image();
        img.onload = function () {
          var max = 1400, w = img.width, h = img.height;
          if (w > max || h > max) {
            if (w > h) { h = Math.round(h * max / w); w = max; }
            else { w = Math.round(w * max / h); h = max; }
          }
          var c = document.createElement('canvas');
          c.width = w; c.height = h;
          c.getContext('2d').drawImage(img, 0, 0, w, h);

          files[t[1]] = c.toDataURL('image/jpeg', 0.85);
          el(t[0] + 'Prev').src = files[t[1]];
          el(t[2]).classList.add('has');
          say('');
        };
        img.onerror = function () { say('That image could not be read.', 'bad'); };
        img.src = fr.result;
      };
      fr.readAsDataURL(f);
    });
  });

  el('rgFinish').addEventListener('click', function () {
    if (!el('rgAgree').checked) { say('Tick the box to agree.', 'bad'); return; }
    if (!el('rgAge').checked) { say('Confirm you are 18 or older.', 'bad'); return; }

    var btn = this;
    btn.disabled = true;
    say('Setting up your account — the photographs take a moment…');

    fetch(API + '?action=register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        hosting:  host.querySelector('input[name=hosting]:checked').value,
        template: host.querySelector('input[name=template]:checked').value,
        store: v('rgStore'), first: v('rgFirst'), last: v('rgLast'),
        email: v('rgEmail'), phone: v('rgPhone'), street: v('rgStreet'),
        city: v('rgCity'), state: v('rgState'), zip: v('rgZip'),
        country: v('rgCountry'), sms: el('rgSms').checked,
        password: v('rgPass'),
        profile: files.profile, id_photo: files.idphoto, agreed: true
      })
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { say(d.error || 'That did not go through.', 'bad'); return; }
        finish(d);
      }).catch(function () {
        btn.disabled = false;
        say('Could not reach the server. Try again in a moment.', 'bad');
      });
  });

  function finish(d) {
    host.querySelectorAll('.rg-step').forEach(function (s) { s.hidden = true; });
    el('rgRail').style.display = 'none';
    el('rgDone').hidden = false;
    el('rgCode').textContent = d.code;
    say('');

    if (d.hosting === 'managed') {
      el('rgDoneTitle').textContent = 'Almost there';
      el('rgDoneText').textContent = 'Your account is created. One last step — set up ' +
        'your subscription and we will build your site.';
      el('rgAction').innerHTML = '<a class="rg-dl" href="' + esc(d.checkout_url) + '">' +
        '<i class="bi bi-credit-card"></i>Set up payment</a>';
      el('rgAfter').innerHTML = '<b>What happens next.</b> Once your first payment goes ' +
        'through we build your site and email you the address, usually within a working ' +
        'day. Nothing is charged until you complete that step.';
    } else {
      el('rgDoneTitle').textContent = "You're in";
      el('rgDoneText').textContent = 'Your site is built and ready, with your details ' +
        'already in it.';
      el('rgAction').innerHTML = '<a class="rg-dl" href="' + esc(d.zip_url) + '" download>' +
        '<i class="bi bi-download"></i>Download your site</a>';
      el('rgAfter').innerHTML = '<b>Inside the zip.</b> Your page, an images folder, and ' +
        'a readme with steps for cPanel and Plesk.<br><br><b>Keep your code safe.</b> ' +
        esc(d.code) + ' is what earns you commission. It is already in your page — do ' +
        'not change it.';
    }
  }

})();
