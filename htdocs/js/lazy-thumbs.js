/**
 * Lazy Thumbnail Loader
 *
 * Uses IntersectionObserver to request thumbnails from /api/thumbnail.php
 * only when an image with data-lazy-thumb scrolls into the viewport.
 *
 * Thumbnails are cached on the filesystem (via ffmpeg) and in the browser
 * (natural image caching), so subsequent views are instant.
 */
(function() {
  'use strict';

  var pending = new Set();

  function loadThumb(img) {
    var contentId = img.getAttribute('data-lazy-thumb');
    if (!contentId || pending.has(contentId)) return;
    pending.add(contentId);

    fetch('/api/thumbnail.php?content_id=' + encodeURIComponent(contentId))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success && data.thumbnail_url) {
          img.src = data.thumbnail_url;
        }
        // If generation failed, the placeholder stays visible
      })
      .catch(function() {
        // Network or server error — keep placeholder
      })
      .finally(function() {
        pending.delete(contentId);
        img.removeAttribute('data-lazy-thumb');
      });
  }

  // Use IntersectionObserver if available (all modern browsers + IE11 with polyfill not needed here)
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          loadThumb(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '200px 0px' }); // Start loading 200px before it enters viewport

    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('img[data-lazy-thumb]').forEach(function(img) {
        observer.observe(img);
      });
    });
  } else {
    // Fallback: load all thumbs immediately on older browsers
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('img[data-lazy-thumb]').forEach(loadThumb);
    });
  }
})();
