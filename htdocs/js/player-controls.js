/**
 * FRE-41: Custom Video Player Controls
 * Vanilla JS — no dependencies.
 *
 * Features:
 *  - Play/Pause with icon toggle
 *  - Time display (currentTime / duration)
 *  - Clickable + draggable progress bar with buffered indicator
 *  - Playback speed selector (0.5x–2x) with localStorage persist
 *  - A-B Loop tool (set A, set B, clear on third click)
 *  - Fullscreen toggle
 *  - Volume slider + mute toggle
 *  - Keyboard shortcuts (Space, arrows, F, M) with input guard
 *  - Subtitle font size toggle (localStorage persist)
 */
(function () {
  'use strict';

  var video = document.getElementById('wp-video');
  if (!video) return;

  // ========== ELEMENT REFS ==========
  var playBtn       = document.getElementById('ctrl-play');
  var timeDisplay   = document.getElementById('ctrl-time');
  var progressWrap  = document.getElementById('ctrl-progress');
  var filledBar     = document.getElementById('ctrl-filled');
  var bufferedBar   = document.getElementById('ctrl-buffered');
  var thumbEl       = document.getElementById('ctrl-thumb');
  var loopRegion    = document.getElementById('ctrl-loop-region');
  var speedBtn      = document.getElementById('ctrl-speed');
  var speedMenu     = document.getElementById('speed-menu');
  var loopBtn       = document.getElementById('ctrl-loop');
  var fullscreenBtn = document.getElementById('ctrl-fullscreen');
  var muteBtn       = document.getElementById('ctrl-mute');
  var volSlider     = document.getElementById('ctrl-vol-slider');
  var volFilled     = document.getElementById('ctrl-vol-filled');
  var fontSmBtn     = document.getElementById('ctrl-font-sm');
  var fontLgBtn     = document.getElementById('ctrl-font-lg');
  var playerCard    = video.closest('.player-card');

  if (!playBtn || !progressWrap) return;

  var iconPlay  = playBtn.querySelector('.icon-play');
  var iconPause = playBtn.querySelector('.icon-pause');
  var iconVolOn = muteBtn ? muteBtn.querySelector('.icon-vol-on') : null;
  var iconVolOff = muteBtn ? muteBtn.querySelector('.icon-vol-off') : null;

  // ========== HELPERS ==========
  function formatTime(s) {
    if (isNaN(s) || !isFinite(s)) return '0:00';
    var m = Math.floor(s / 60);
    var sec = Math.floor(s % 60);
    return m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  function lsGet(key) {
    try { return localStorage.getItem('edutek_' + key); } catch (e) { return null; }
  }

  function lsSet(key, val) {
    try { localStorage.setItem('edutek_' + key, val); } catch (e) { /* quota */ }
  }

  function isTyping() {
    var el = document.activeElement;
    if (!el) return false;
    var tag = el.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
  }

  // ========== PLAY / PAUSE ==========
  function updatePlayIcon() {
    if (video.paused) {
      iconPlay.style.display = '';
      iconPause.style.display = 'none';
      playBtn.setAttribute('aria-label', 'Play');
    } else {
      iconPlay.style.display = 'none';
      iconPause.style.display = '';
      playBtn.setAttribute('aria-label', 'Pause');
    }
  }

  playBtn.addEventListener('click', function () {
    if (video.paused) { video.play(); } else { video.pause(); }
  });

  video.addEventListener('play', updatePlayIcon);
  video.addEventListener('pause', updatePlayIcon);
  video.addEventListener('ended', updatePlayIcon);

  // ========== TIME DISPLAY ==========
  function updateTime() {
    timeDisplay.textContent = formatTime(video.currentTime) + ' / ' + formatTime(video.duration);
  }
  video.addEventListener('timeupdate', updateTime);
  video.addEventListener('loadedmetadata', updateTime);

  // ========== PROGRESS BAR ==========
  var isDragging = false;

  function setProgress(fraction) {
    var pct = Math.max(0, Math.min(1, fraction)) * 100;
    filledBar.style.width = pct + '%';
    thumbEl.style.left = pct + '%';
  }

  function updateProgress() {
    if (isDragging) return;
    if (video.duration > 0) {
      setProgress(video.currentTime / video.duration);
    }
  }

  video.addEventListener('timeupdate', updateProgress);
  video.addEventListener('loadedmetadata', updateProgress);

  // Buffered indicator
  function updateBuffered() {
    if (video.buffered.length > 0 && video.duration > 0) {
      var end = video.buffered.end(video.buffered.length - 1);
      bufferedBar.style.width = (end / video.duration * 100) + '%';
    }
  }
  video.addEventListener('progress', updateBuffered);
  video.addEventListener('loadedmetadata', updateBuffered);

  // Click to seek
  function seekFromEvent(e) {
    var rect = progressWrap.getBoundingClientRect();
    var frac = (e.clientX - rect.left) / rect.width;
    frac = Math.max(0, Math.min(1, frac));
    video.currentTime = frac * video.duration;
    setProgress(frac);
  }

  progressWrap.addEventListener('mousedown', function (e) {
    if (video.duration > 0) {
      isDragging = true;
      seekFromEvent(e);
    }
  });

  document.addEventListener('mousemove', function (e) {
    if (isDragging) seekFromEvent(e);
  });

  document.addEventListener('mouseup', function () {
    isDragging = false;
  });

  // ========== SPEED SELECTOR ==========
  var savedSpeed = parseFloat(lsGet('playerSpeed'));
  if (savedSpeed && savedSpeed >= 0.25 && savedSpeed <= 4) {
    video.playbackRate = savedSpeed;
    speedBtn.childNodes[0].textContent = savedSpeed + 'x ';
    updateSpeedMenuActive(savedSpeed);
  }

  function updateSpeedMenuActive(rate) {
    var items = speedMenu.querySelectorAll('.speed-menu__item');
    for (var i = 0; i < items.length; i++) {
      if (parseFloat(items[i].getAttribute('data-speed')) === rate) {
        items[i].classList.add('speed-menu__item--active');
      } else {
        items[i].classList.remove('speed-menu__item--active');
      }
    }
  }

  speedBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    speedMenu.classList.toggle('open');
  });

  speedMenu.addEventListener('click', function (e) {
    var item = e.target.closest('.speed-menu__item');
    if (!item) return;
    var rate = parseFloat(item.getAttribute('data-speed'));
    video.playbackRate = rate;
    speedBtn.childNodes[0].textContent = rate + 'x ';
    lsSet('playerSpeed', rate);
    updateSpeedMenuActive(rate);
    speedMenu.classList.remove('open');
  });

  // Close speed menu on outside click
  document.addEventListener('click', function (e) {
    if (!speedMenu.contains(e.target) && e.target !== speedBtn) {
      speedMenu.classList.remove('open');
    }
  });

  // ========== A-B LOOP ==========
  var loopState = 0; // 0 = off, 1 = A set, 2 = A+B set (looping)
  var loopA = 0, loopB = 0;

  loopBtn.addEventListener('click', function () {
    if (loopState === 0) {
      // Set A
      loopA = video.currentTime;
      loopState = 1;
      loopBtn.classList.add('ctrl-btn--active');
      loopBtn.querySelector('.loop-label').textContent = 'A: ' + formatTime(loopA);
    } else if (loopState === 1) {
      // Set B
      loopB = video.currentTime;
      if (loopB <= loopA) {
        // Swap if B < A
        var tmp = loopA;
        loopA = loopB;
        loopB = tmp;
      }
      loopState = 2;
      loopBtn.querySelector('.loop-label').textContent = formatTime(loopA) + '↔' + formatTime(loopB);
      // Show loop region on progress bar
      if (video.duration > 0) {
        var leftPct = (loopA / video.duration) * 100;
        var widthPct = ((loopB - loopA) / video.duration) * 100;
        loopRegion.style.left = leftPct + '%';
        loopRegion.style.width = widthPct + '%';
        loopRegion.classList.add('active');
      }
    } else {
      // Clear
      loopState = 0;
      loopA = 0;
      loopB = 0;
      loopBtn.classList.remove('ctrl-btn--active');
      loopBtn.querySelector('.loop-label').textContent = 'A↔B';
      loopRegion.classList.remove('active');
    }
  });

  // Loop enforcement
  video.addEventListener('timeupdate', function () {
    if (loopState === 2 && video.currentTime >= loopB) {
      video.currentTime = loopA;
    }
  });

  // ========== FULLSCREEN ==========
  fullscreenBtn.addEventListener('click', function () {
    if (playerCard) {
      if (document.fullscreenElement) {
        document.exitFullscreen();
      } else {
        playerCard.requestFullscreen().catch(function () {
          // Fallback: just fullscreen the video
          if (video.requestFullscreen) video.requestFullscreen();
        });
      }
    }
  });

  // ========== VOLUME ==========
  function updateVolumeUI() {
    var pct = video.muted ? 0 : (video.volume * 100);
    volFilled.style.width = pct + '%';
    if (iconVolOn && iconVolOff) {
      if (video.muted || video.volume === 0) {
        iconVolOn.style.display = 'none';
        iconVolOff.style.display = '';
      } else {
        iconVolOn.style.display = '';
        iconVolOff.style.display = 'none';
      }
    }
  }

  muteBtn.addEventListener('click', function () {
    video.muted = !video.muted;
    updateVolumeUI();
  });

  volSlider.addEventListener('click', function (e) {
    var rect = volSlider.getBoundingClientRect();
    var frac = (e.clientX - rect.left) / rect.width;
    frac = Math.max(0, Math.min(1, frac));
    video.volume = frac;
    video.muted = false;
    updateVolumeUI();
  });

  video.addEventListener('volumechange', updateVolumeUI);
  updateVolumeUI();

  // ========== SUBTITLE FONT SIZE ==========
  var fontSizes = ['small', 'medium', 'large'];
  var currentFontSize = lsGet('subtitleFontSize') || 'medium';

  function applyFontSize(size) {
    currentFontSize = size;
    lsSet('subtitleFontSize', size);
    // Apply to any <track> cue styling if available
    var tracks = video.textTracks;
    if (tracks) {
      for (var i = 0; i < tracks.length; i++) {
        // This will be used when subtitle tracks are added in FRE-42
      }
    }
    // Toggle active state on buttons
    if (size === 'small' || size === 'medium') {
      fontSmBtn.classList.add('ctrl-btn--active');
      fontLgBtn.classList.remove('ctrl-btn--active');
    } else {
      fontSmBtn.classList.remove('ctrl-btn--active');
      fontLgBtn.classList.add('ctrl-btn--active');
    }
  }

  fontSmBtn.addEventListener('click', function () { applyFontSize('small'); });
  fontLgBtn.addEventListener('click', function () { applyFontSize('large'); });
  applyFontSize(currentFontSize);

  // ========== KEYBOARD SHORTCUTS ==========
  document.addEventListener('keydown', function (e) {
    if (isTyping()) return;

    switch (e.key) {
      case ' ':
        e.preventDefault();
        if (video.paused) { video.play(); } else { video.pause(); }
        break;
      case 'ArrowLeft':
        e.preventDefault();
        video.currentTime = Math.max(0, video.currentTime - 10);
        break;
      case 'ArrowRight':
        e.preventDefault();
        video.currentTime = Math.min(video.duration || 0, video.currentTime + 10);
        break;
      case 'ArrowUp':
        e.preventDefault();
        video.volume = Math.min(1, video.volume + 0.1);
        updateVolumeUI();
        break;
      case 'ArrowDown':
        e.preventDefault();
        video.volume = Math.max(0, video.volume - 0.1);
        updateVolumeUI();
        break;
      case 'f':
      case 'F':
        e.preventDefault();
        fullscreenBtn.click();
        break;
      case 'm':
      case 'M':
        e.preventDefault();
        video.muted = !video.muted;
        updateVolumeUI();
        break;
    }
  });

  // ========== CLICK VIDEO TO PLAY/PAUSE ==========
  video.addEventListener('click', function () {
    if (video.paused) { video.play(); } else { video.pause(); }
  });

  // ========== FRE-45: AUTO-ADVANCE ON VIDEO END ==========
  video.addEventListener('ended', function () {
    // Don't auto-advance if A-B loop is active
    if (loopState === 2) return;

    var upNextEl = document.querySelector('[data-up-next="true"]');
    if (!upNextEl || !upNextEl.href) return;

    var toast = document.getElementById('up-next-toast');
    var titleEl = document.getElementById('up-next-toast-title');
    var countdownEl = document.getElementById('up-next-countdown');
    var cancelBtn = document.getElementById('up-next-cancel');
    if (!toast) return;

    var upNextTitle = upNextEl.querySelector('.playlist-item__title');
    titleEl.textContent = upNextTitle ? upNextTitle.textContent : 'Next video';
    toast.style.display = 'flex';

    var seconds = 5;
    countdownEl.textContent = seconds;
    var cancelled = false;

    function onCancel() {
      cancelled = true;
      toast.style.display = 'none';
      cancelBtn.removeEventListener('click', onCancel);
    }
    cancelBtn.addEventListener('click', onCancel);

    var interval = setInterval(function () {
      if (cancelled) { clearInterval(interval); return; }
      seconds--;
      countdownEl.textContent = seconds;
      if (seconds <= 0) {
        clearInterval(interval);
        window.location.href = upNextEl.href;
      }
    }, 1000);
  });

  // ========== INIT: remove native controls ==========
  video.removeAttribute('controls');

})();
