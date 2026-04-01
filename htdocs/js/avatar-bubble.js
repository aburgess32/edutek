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

    var goBtn = document.getElementById('resign-go');
    goBtn.textContent = '...';
    goBtn.disabled = true;

    fetch('/api/avatar-lookup.php?name=' + encodeURIComponent(val))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.found) {
          showInlineConfirm(wrap, data);
        } else {
          // Not found — go to find-user page
          window.location.href = '/find-user.php';
        }
      })
      .catch(function() {
        window.location.href = '/find-user.php';
      })
      .finally(function() {
        goBtn.textContent = 'Go';
        goBtn.disabled = false;
      });
  }

  function showInlineConfirm(wrap, user) {
    wrap.classList.add('resign-confirm-mode');

    var avatarSvg = '';
    if (typeof generateAvatar === 'function') {
      avatarSvg = generateAvatar(user.avatar_name, 40, user.avatar_color);
    } else {
      avatarSvg = '<span style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background:' + user.avatar_color + ';color:#fff;font-weight:800;font-size:18px;">' + user.avatar_name.charAt(0) + '</span>';
    }

    wrap.innerHTML =
      '<div class="resign-confirm-avatar">' + avatarSvg + '</div>' +
      '<div class="resign-confirm-info">' +
        '<div class="resign-confirm-name" style="color:' + user.avatar_color + ';">' + escapeHtml(user.avatar_name) + '</div>' +
        '<div class="resign-confirm-real">' + escapeHtml(user.display_name) + '</div>' +
      '</div>' +
      '<div class="resign-confirm-actions">' +
        '<button class="resign-confirm-yes" id="resign-yes">That\'s me</button>' +
        '<button class="resign-confirm-no" id="resign-no">&times;</button>' +
      '</div>';

    document.getElementById('resign-yes').onclick = function() {
      this.textContent = '...';
      this.disabled = true;
      fetch('/api/avatar-lookup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: user.id })
      })
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        if (resp.ok) {
          window.location.reload();
        } else {
          alert(resp.error || 'Login failed');
        }
      })
      .catch(function() {
        alert('Something went wrong. Please try again.');
      });
    };

    document.getElementById('resign-no').onclick = function() {
      resetResignInput(wrap);
    };
  }

  function resetResignInput(wrap) {
    wrap.classList.remove('resign-confirm-mode');
    wrap.innerHTML =
      '<input type="text" class="resign-input" id="resign-input" placeholder="Avatar Name" maxlength="20" autocomplete="off">' +
      '<button class="resign-go" id="resign-go">Go</button>' +
      '<button class="resign-close" id="resign-close">&times;</button>';

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
    input.focus();
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
})();
