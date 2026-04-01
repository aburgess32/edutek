/**
 * EduPak FRE-11: Floating Avatar Bubble + Re-Sign-In Input
 * Loaded on ALL pages via navbar includes.
 *
 * Reads data attributes from the body tag set by PHP:
 *   data-user-role, data-avatar-name, data-avatar-color, data-display-name, data-user-id
 */
(function() {
  'use strict';

  var body = document.body;
  var role = body.getAttribute('data-user-role') || '';
  var avatarName = body.getAttribute('data-avatar-name') || '';
  var avatarColor = body.getAttribute('data-avatar-color') || '#6C63FF';
  var displayName = body.getAttribute('data-display-name') || '';
  var miniDashOpen = false;

  if (role === 'student' && avatarName) {
    // Student bubble needs generateAvatar from login.js
    if (typeof generateAvatar === 'function') {
      initBubble();
    }
  } else if (role === 'guest' || role === '') {
    initResignInput();
  }
  // Teachers get nav indicator only (handled by PHP in navbar)

  function initBubble() {
    // Create bubble element
    var bubble = document.createElement('div');
    bubble.className = 'avatar-bubble';
    bubble.id = 'avatar-bubble';
    bubble.innerHTML = '<div class="avatar-bubble-ring" style="border-color:' + avatarColor + ';"></div>' +
      '<div>' + generateAvatar(avatarName, 48, avatarColor) + '</div>';
    bubble.onclick = toggleMiniDash;
    document.body.appendChild(bubble);

    // Create mini dashboard
    var dash = document.createElement('div');
    dash.className = 'mini-dash';
    dash.id = 'mini-dash';
    dash.innerHTML =
      '<div class="mini-dash-header">' +
        '<div class="mini-dash-avatar">' + generateAvatar(avatarName, 64, avatarColor) + '</div>' +
        '<div class="mini-dash-names">' +
          '<div class="mini-dash-avatar-name">' + avatarName.toUpperCase() + '</div>' +
          '<div class="mini-dash-real-name">' + escapeHtml(displayName) + '</div>' +
        '</div>' +
      '</div>' +
      '<div class="mini-dash-section">' +
        '<div class="mini-dash-section-title">Recent Videos</div>' +
        '<div class="mini-dash-placeholder">No videos watched yet</div>' +
      '</div>' +
      '<div class="mini-dash-section" style="padding-top:4px;">' +
        '<div class="mini-dash-section-title">Screen Time</div>' +
        '<div class="mini-dash-bar-label">Today: -- min</div>' +
        '<div class="mini-dash-bar-track"><div class="mini-dash-bar-fill" style="width:0%;background:' + avatarColor + ';"></div></div>' +
      '</div>' +
      '<div class="mini-dash-footer">' +
        '<a href="/logout.php" class="mini-dash-switch" style="text-decoration:none;display:block;text-align:center;">Switch User</a>' +
      '</div>';
    document.body.appendChild(dash);

    // Close on outside click
    document.addEventListener('click', function(e) {
      if (!miniDashOpen) return;
      if (!dash.contains(e.target) && !bubble.contains(e.target)) {
        closeMiniDash();
      }
    });
  }

  function toggleMiniDash() {
    miniDashOpen = !miniDashOpen;
    var dash = document.getElementById('mini-dash');
    if (dash) dash.classList.toggle('open', miniDashOpen);
  }

  function closeMiniDash() {
    miniDashOpen = false;
    var dash = document.getElementById('mini-dash');
    if (dash) dash.classList.remove('open');
  }

  function initResignInput() {
    var wrap = document.createElement('div');
    wrap.className = 'resign-input-wrap';
    wrap.id = 'resign-input-wrap';
    wrap.innerHTML =
      '<input type="text" class="resign-input" id="resign-input" placeholder="Avatar Name" maxlength="20" autocomplete="off">' +
      '<button class="resign-go" id="resign-go">Go</button>' +
      '<button class="resign-close" id="resign-close">&times;</button>';
    document.body.appendChild(wrap);

    var input = document.getElementById('resign-input');
    var goBtn = document.getElementById('resign-go');
    var closeBtn = document.getElementById('resign-close');

    goBtn.onclick = function() { handleResignIn(input, wrap); };
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') handleResignIn(input, wrap);
    });
    closeBtn.onclick = function() {
      wrap.classList.add('hidden');
    };
  }

  function handleResignIn(input, wrap) {
    var val = input.value.trim();
    if (!val) return;

    // POST to find-user.php to check if avatar name exists
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/find-user.php';
    form.style.display = 'none';

    var nameInput = document.createElement('input');
    nameInput.name = 'resign_avatar_name';
    nameInput.value = val;
    form.appendChild(nameInput);

    // Add CSRF token if available
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
      var csrfInput = document.createElement('input');
      csrfInput.name = '_csrf_token';
      csrfInput.value = csrfMeta.getAttribute('content');
      form.appendChild(csrfInput);
    }

    document.body.appendChild(form);
    form.submit();
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
})();
