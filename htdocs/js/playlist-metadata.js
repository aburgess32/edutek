/**
 * FRE-41: Playlist Metadata Loader
 *
 * For each playlist item with a data-video-src attribute:
 *  1. Creates a hidden <video> element to load metadata
 *  2. Reads duration and updates the --:-- badge
 *  3. Seeks to 6s (or 25% for short videos) and captures a thumbnail
 *     frame onto a <canvas> inserted into the thumbnail container
 *
 * Loads items sequentially (one at a time) to avoid hammering the server
 * with parallel video requests.
 */
(function () {
  'use strict';

  var durationEls = document.querySelectorAll('.playlist-item__duration[data-video-src]');
  if (!durationEls.length) return;

  function formatDuration(s) {
    if (isNaN(s) || !isFinite(s) || s <= 0) return '--:--';
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = Math.floor(s % 60);
    if (h > 0) {
      return h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }
    return m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  function loadItem(index) {
    if (index >= durationEls.length) return;

    var el = durationEls[index];
    var src = el.getAttribute('data-video-src');
    if (!src) { loadItem(index + 1); return; }

    // Ensure absolute /content/ URL (some pages may output raw content_ids)
    if (src.indexOf('/content/') !== 0 && src.indexOf('http') !== 0) {
      src = '/content/' + src.replace(/^content\//, '');
    }

    var thumbContainer = el.closest('.playlist-item').querySelector('.playlist-item__thumb');
    var probe = document.createElement('video');
    probe.preload = 'metadata';
    probe.muted = true;
    probe.playsInline = true;
    // Prevent the probe from being visible or taking space
    probe.style.cssText = 'position:absolute;width:0;height:0;opacity:0;pointer-events:none;';
    document.body.appendChild(probe);

    var cleaned = false;
    function cleanup() {
      if (cleaned) return;
      cleaned = true;
      probe.removeAttribute('src');
      probe.load();
      if (probe.parentNode) probe.parentNode.removeChild(probe);
      // Load next item
      loadItem(index + 1);
    }

    var timeout = setTimeout(function () {
      // Bail after 8 seconds if metadata hasn't loaded
      cleanup();
    }, 8000);

    probe.addEventListener('loadedmetadata', function () {
      // Update duration text
      el.textContent = formatDuration(probe.duration);

      // Seek to a frame for thumbnail capture
      var seekTarget = probe.duration > 10 ? 6 : probe.duration * 0.25;
      probe.currentTime = seekTarget;
    });

    probe.addEventListener('seeked', function onSeeked() {
      probe.removeEventListener('seeked', onSeeked);
      clearTimeout(timeout);

      // Capture frame to canvas
      if (thumbContainer && probe.videoWidth > 0 && probe.videoHeight > 0) {
        try {
          var canvas = document.createElement('canvas');
          canvas.width = 200;  // small is fine for thumbnails
          canvas.height = 112; // ~16:9
          var ctx = canvas.getContext('2d');
          ctx.drawImage(probe, 0, 0, canvas.width, canvas.height);
          // Insert canvas into thumb container (before the play icon)
          var playIcon = thumbContainer.querySelector('.playlist-item__play-icon');
          if (playIcon) {
            thumbContainer.insertBefore(canvas, playIcon);
          } else {
            thumbContainer.appendChild(canvas);
          }
        } catch (e) {
          // CORS or SecurityError — keep gradient fallback
        }
      }

      cleanup();
    });

    // Handle errors gracefully — keep gradient fallback and --:--
    probe.addEventListener('error', function () {
      clearTimeout(timeout);
      cleanup();
    });

    probe.src = src;
  }

  // Start loading after a short delay to let the page settle
  setTimeout(function () {
    loadItem(0);
  }, 500);

})();
