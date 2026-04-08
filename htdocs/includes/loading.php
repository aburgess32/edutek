<?php
/**
 * Inline loading indicator — renders immediately before heavy content.
 * Call loadingStart() after navbar, loadingEnd() when content is ready.
 */
function loadingStart(string $message = 'Loading content...') {
    // Flush the navbar HTML immediately so the user sees something
    if (ob_get_level()) ob_flush();
    flush();
    echo '<div id="edupak-loading" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;text-align:center;">
  <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:edupak-spin 0.8s linear infinite;"></div>
  <p style="margin-top:16px;color:#888;font-size:14px;">' . htmlspecialchars($message) . '</p>
</div>
<style>@keyframes edupak-spin{to{transform:rotate(360deg)}}</style>';
    if (ob_get_level()) ob_flush();
    flush();
}

function loadingEnd() {
    echo '<script>document.getElementById("edupak-loading").style.display="none";</script>';
}
