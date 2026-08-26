/* ═══════════════════════════════════════════════════════════════════════════
   affiliate-page.js — the parts of an affiliate's page that stay ours.

   Loaded from the studio rather than copied into her zip, so a fix reaches
   every affiliate site without anyone re-uploading anything. Her page is hers;
   this is the part that has to keep working.

   It does three things: tells us the page is alive, shows her status under her
   name, and fills the sponsored strip.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var HOME = 'https://luxetalentsystems.com';

  function el(id) { return document.getElementById(id); }

  /* the year, so a page built today does not still read 2026 in five years */
  var yr = el('yr');
  if (yr) yr.textContent = new Date().getFullYear();

  /* ── the heartbeat, and her status ────────────────────────────────────
     Every load tells us the page is alive. The studio also checks nightly,
     because a page nobody visited today has not stopped existing — one
     signal answers what the other cannot. */
  (function () {
    var box = el('affStatus');
    if (!box) return;

    var code = box.getAttribute('data-code');
    if (!code) { box.style.display = 'none'; return; }

    fetch(HOME + '/api/affiliate-status.php?code=' + encodeURIComponent(code) +
          '&url=' + encodeURIComponent(location.origin + location.pathname))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { box.style.display = 'none'; return; }
        box.className = 'brand-status ' + (d.css || 'off');
        var t = box.querySelector('.st-text');
        if (t) t.textContent = d.label || '';
      })
      .catch(function () {
        /* an unreachable studio is not her fault, and a red mark would say it
           was — better to show nothing */
        box.style.display = 'none';
      });
  })();

  /* ── sponsored ────────────────────────────────────────────────────────
     Fetched on each load, so what appears can change from the studio without
     her rebuilding or re-uploading. */
  (function () {
    var strip = el('adStrip');
    var section = el('adSection');
    if (!strip) return;

    function hide() { if (section) section.style.display = 'none'; }

    fetch(HOME + '/api/ads.php?action=live')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.ads || !d.ads.length) { hide(); return; }

        strip.innerHTML = d.ads.map(function (a) {
          var alt = String(a.alt || 'Sponsored').replace(/"/g, '&quot;');
          var img = '<img src="' + a.image + '" alt="' + alt + '" loading="lazy">';
          return a.url
            ? '<a class="ad" href="' + a.url + '" target="_blank" rel="noopener sponsored">' +
              img + '</a>'
            : '<div class="ad">' + img + '</div>';
        }).join('');

        if (section) section.style.display = '';
      })
      .catch(hide);
  })();

  /* ── the product strip ────────────────────────────────────────────────
     Moves by one picture at a time, and stops when the last one is in view
     rather than scrolling into empty space. Pictures that fail to load are
     dropped, so an affiliate who uploads four instead of eight gets a strip
     of four rather than four and a row of gaps. */
  (function () {
    var track = el('track');
    if (!track) return;

    var strip = track.closest('.strip');
    var at = 0, timer = null;

    [].slice.call(track.querySelectorAll('figure')).forEach(function (fig) {
      var img = fig.querySelector('img');
      if (!img) { fig.classList.add('gone'); return; }
      img.addEventListener('error', function () {
        fig.classList.add('gone');
        clamp();
      });
    });

    function live() {
      return [].slice.call(track.querySelectorAll('figure'))
               .filter(function (f) { return !f.classList.contains('gone'); });
    }

    function step() {
      var f = live()[0];
      if (!f) return 0;
      /* the gap between pictures counts, or the strip drifts out of line */
      var gap = parseFloat(getComputedStyle(track).gap) || 0;
      return f.getBoundingClientRect().width + gap;
    }

    /* how many can move past before the last picture reaches the right edge */
    function most() {
      var w = step();
      if (!w) return 0;
      var visible = Math.floor(strip.clientWidth / w);
      return Math.max(0, live().length - visible);
    }

    function clamp() {
      var l = live();

      /* Nothing loaded: hide the band. But only once the pictures have had
         their chance — running before they load would hide a strip that was
         about to be perfectly fine, which is exactly what it did. */
      if (!l.length) {
        if (document.readyState === 'complete') { strip.style.display = 'none'; stop(); }
        return;
      }
      strip.style.display = '';

      var max = most();
      if (at > max) at = max;
      track.style.transform = 'translateX(' + (-at * step()) + 'px)';

      /* no point offering arrows when everything already fits */
      var arrows = strip.querySelectorAll('.s-arrow');
      [].slice.call(arrows).forEach(function (a) {
        a.style.display = max > 0 ? '' : 'none';
      });
    }

    function go(n) {
      var max = most();
      if (max <= 0) return;
      /* wrapping rather than stopping dead, so it can run on its own */
      at = n > max ? 0 : (n < 0 ? max : n);
      track.style.transform = 'translateX(' + (-at * step()) + 'px)';
      restart();
    }

    function start() {
      if (most() > 0) timer = setInterval(function () { go(at + 1); }, 4000);
    }
    function stop() { if (timer) clearInterval(timer); timer = null; }
    function restart() { stop(); start(); }

    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-strip]');
      if (b) go(at + (+b.getAttribute('data-strip')));
    });

    /* a strip that keeps moving while somebody is looking at it is an
       irritation, and one running in a background tab is wasted work */
    strip.addEventListener('mouseenter', stop);
    strip.addEventListener('mouseleave', start);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop(); else start();
    });

    /* a rotated phone changes how many fit */
    var t;
    window.addEventListener('resize', function () {
      clearTimeout(t);
      t = setTimeout(clamp, 180);
    });

    window.addEventListener('load', clamp);
    clamp();
    start();
  })();

})();
