/* Training tab module — adds a "Training" sidebar item + tab-content panel.
 * Self-contained. If sidebar doesn't exist, does nothing.
 */
(function () {
  'use strict';

  var SIDEBAR_ITEM_HTML =
    '<div class="sb-item clickable" data-tab="training" id="trainSidebarItem">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round">' +
        '<polygon points="23 7 16 12 23 17 23 7"/>' +
        '<rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>' +
        '<circle cx="8" cy="12" r="2"/>' +
      '</svg>' +
      '<span>Training</span>' +
    '</div>';

  var TAB_HTML =
    '<div class="tab-content" id="tab-training">' +
      '<div class="train-wrap">' +
        '<div class="train-hdr">' +
          '<h1>Virtual Collaboration and Education</h1>' +
          '<p>Stream content from a host machine to one or more remote users over the internet. ' +
          'Whiteboarding, screen sharing, chat, and annotation in one place. Use it as a virtual ' +
          'classroom, or to communicate over video the way you might in person.</p>' +
        '</div>' +
        '<div class="train-grid">' +
          '<div class="train-card">' +
            '<div class="train-card-h">Stream Video</div>' +
            '<div class="train-card-b">Host selects any video from their local directory and streams it to remote users in real time.</div>' +
          '</div>' +
          '<div class="train-card">' +
            '<div class="train-card-h">Whiteboard</div>' +
            '<div class="train-card-b">Live canvas for diagrams and notes. Host\'s voice is captured. Works as an online classroom.</div>' +
          '</div>' +
          '<div class="train-card">' +
            '<div class="train-card-h">Screen Share</div>' +
            '<div class="train-card-b">Capture the host\'s screen and stream it live to all connected participants.</div>' +
          '</div>' +
          '<div class="train-card">' +
            '<div class="train-card-h">Live Chat</div>' +
            '<div class="train-card-b">Connected users converse via live chat during the stream.</div>' +
          '</div>' +
        '</div>' +
        '<div class="train-launch-row">' +
          '<div>' +
            '<div class="train-launch-title">Ready to start a session?</div>' +
            '<div class="train-launch-sub">The streaming server is being provisioned. Launch will be enabled once the backend is online.</div>' +
          '</div>' +
          '<a class="train-launch-btn-live" href="/training/" target="_blank" ' +
            'style="background:#d4a830;color:#0e1117;text-decoration:none;text-align:center">Launch Session</a>' +
        '</div>' +
      '</div>' +
    '</div>';

  function init() {
    // Skip if already injected
    if (document.getElementById('trainSidebarItem')) return;

    // Find sidebar nav — try common selectors
    var nav = document.querySelector('.sb-nav');
    if (!nav) return; // not the admin dashboard, do nothing

    // Find a good place to insert the sidebar item — before the Logout item if present,
    // otherwise at the end of .sb-nav
    var logout = nav.querySelector('.sb-item[onclick*="doLogout"]');
    var wrapper = document.createElement('div');
    wrapper.innerHTML = SIDEBAR_ITEM_HTML;
    var item = wrapper.firstChild;
    if (logout) {
      // Insert before the spacer that's right before logout (the empty sb-section)
      var spacer = logout.previousElementSibling;
      if (spacer && spacer.classList.contains('sb-section')) {
        nav.insertBefore(item, spacer);
      } else {
        nav.insertBefore(item, logout);
      }
    } else {
      nav.appendChild(item);
    }

    // Find the tab-content container (the parent that holds all #tab-* divs)
    var anyTab = document.querySelector('.tab-content');
    if (!anyTab || !anyTab.parentNode) return;
    var tabWrap = document.createElement('div');
    tabWrap.innerHTML = TAB_HTML;
    anyTab.parentNode.appendChild(tabWrap.firstChild);

    // The existing sidebar JS handler should pick up the new data-tab on its own;
    // if it uses event delegation it works, if it iterates once at load it won't.
    // Add our own click handler as a safety net — switches the active tab the same
    // way the existing handlers do.
    item.addEventListener('click', function () {
      document.querySelectorAll('.tab-content').forEach(function(t){ t.classList.remove('active'); });
      document.querySelectorAll('.sb-item').forEach(function(s){ s.classList.remove('active'); });
      document.getElementById('tab-training').classList.add('active');
      item.classList.add('active');
      var title = document.getElementById('pageTitle');
      if (title) title.textContent = 'Training';
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
