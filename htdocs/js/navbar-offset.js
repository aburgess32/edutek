(function () {
  'use strict';

  if (window.edutekNavbarOffsetLoaded) return;

  var nav = document.getElementById('mainNav');
  var body = document.body;
  if (!nav || !body) return;

  window.edutekNavbarOffsetLoaded = true;

  var originalPadding = body.style.getPropertyValue('padding-top');
  var originalPriority = body.style.getPropertyPriority('padding-top');
  var framePending = false;
  var appliedPadding = null;
  var gap = 12;

  function restorePadding() {
    if (appliedPadding === null) return;

    if (originalPadding) {
      body.style.setProperty(
        'padding-top',
        originalPadding,
        originalPriority
      );
    } else {
      body.style.removeProperty('padding-top');
    }

    appliedPadding = null;
  }

  function updateOffset() {
    framePending = false;

    if (
      !body.classList.contains('fixed-nav') ||
      window.getComputedStyle(nav).position !== 'fixed'
    ) {
      restorePadding();
      return;
    }

    var rect = nav.getBoundingClientRect();

    if (rect.height <= 0) {
      restorePadding();
      return;
    }

    var padding = Math.ceil(Math.max(0, rect.bottom)) + gap + 'px';

    if (padding !== appliedPadding) {
      body.style.setProperty('padding-top', padding, 'important');
      appliedPadding = padding;
    }
  }

  function scheduleUpdate() {
    if (framePending) return;
    framePending = true;
    window.requestAnimationFrame(updateOffset);
  }

  if ('ResizeObserver' in window) {
    var resizeObserver = new ResizeObserver(scheduleUpdate);
    resizeObserver.observe(nav);
  }

  if ('MutationObserver' in window) {
    var navObserver = new MutationObserver(scheduleUpdate);
    navObserver.observe(nav, {
      attributes: true,
      childList: true,
      subtree: true
    });

    var bodyObserver = new MutationObserver(scheduleUpdate);
    bodyObserver.observe(body, {
      attributes: true,
      attributeFilter: ['class']
    });
  }

  window.addEventListener('resize', scheduleUpdate);
  window.addEventListener('orientationchange', scheduleUpdate);
  window.addEventListener('load', scheduleUpdate);
  document.addEventListener('DOMContentLoaded', scheduleUpdate);
  nav.addEventListener('load', scheduleUpdate, true);

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(scheduleUpdate);
  }

  updateOffset();
})();