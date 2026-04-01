<?php
/**
 * FRE-12: Breadcrumb HTML template
 *
 * Expects $crumbs array from buildBreadcrumb().
 * Renders a fixed-bottom nav with toggle button + mode cycle icon.
 * Always renders the nav (toggle + mode icon) even with empty crumbs,
 * so the layout mode toggle is accessible from every page.
 */
$crumbCount = is_array($crumbs ?? null) ? count($crumbs) : 0;
$hasCrumbs = $crumbCount > 0;
?>
<nav class="bc" aria-label="You are here" style="pointer-events:none">
  <!-- Mode cycle button — hidden when bc is collapsed -->
  <button class="bc__mode" type="button"
          aria-label="Switch layout mode"
          title="Switch layout mode"
          style="pointer-events:auto"
          onclick="(function(b){var modes=['phone','tablet','screen'];var icons={phone:'&#x1F4F1;',tablet:'&#x1F4CB;',screen:'&#x1F4FA;'};var cur=document.body.dataset.mode||'phone';var next=modes[(modes.indexOf(cur)+1)%3];document.body.dataset.mode=next;b.querySelector('.bc__mode-icon').textContent=icons[next];document.cookie='edupak_mode='+next+';path=/;max-age='+(86400*30)+';SameSite=Lax';var fd=new FormData();fd.append('mode',next);fetch('/api/set-mode.php',{method:'POST',body:fd}).catch(function(){});})(this)">
    <span class="bc__mode-icon"><?php
      $modeIcons = ['phone' => "\u{1F4F1}", 'tablet' => "\u{1F4CB}", 'screen' => "\u{1F4FA}"];
      echo $modeIcons[getMode()] ?? "\u{1F4F1}";
    ?></span>
  </button>
  <button class="bc__toggle" type="button"
          aria-label="Toggle breadcrumb"
          aria-expanded="true"
          style="pointer-events:auto"
          onclick="this.closest('.bc').classList.toggle('bc--collapsed');var c=this.closest('.bc').classList.contains('bc--collapsed');this.setAttribute('aria-expanded',!c);try{localStorage.setItem('bc-collapsed',c?'1':'0')}catch(e){}">
    <svg class="bc__toggle-icon" width="12" height="12" viewBox="0 0 16 16"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M6 4l4 4-4 4"/>
    </svg>
  </button>
  <?php if ($hasCrumbs): ?>
  <ol class="bc__list">
    <?php foreach ($crumbs as $i => $crumb):
        $isLast = ($i === $crumbCount - 1);
        $label  = htmlspecialchars($crumb['label'] ?? '', ENT_QUOTES, 'UTF-8');
        $color  = $crumb['color'] ?? null;
        $href   = $crumb['href'] ?? null;
    ?>
      <?php if ($i > 0): ?>
        <li class="bc__sep" aria-hidden="true">
          <svg width="10" height="10" viewBox="0 0 16 16" fill="none"
               stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 4l4 4-4 4"/>
          </svg>
        </li>
      <?php endif; ?>
      <li class="bc__item<?php echo $isLast ? ' bc__item--current' : ''; ?>"
          <?php echo $isLast ? 'aria-current="page"' : ''; ?>>
        <?php if ($color !== null): ?>
          <span class="bc__dot" style="background:<?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>"></span>
        <?php endif; ?>
        <?php if (!$isLast && $href !== null): ?>
          <a class="bc__link" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
             title="<?php echo $label; ?>"
             style="pointer-events:auto">
            <?php echo $label; ?>
          </a>
        <?php else: ?>
          <span class="bc__label" title="<?php echo $label; ?>">
            <?php echo $label; ?>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</nav>
<script>
try{if(localStorage.getItem('bc-collapsed')==='1'){var n=document.querySelector('.bc');if(n){n.classList.add('bc--collapsed');var t=n.querySelector('.bc__toggle');if(t)t.setAttribute('aria-expanded','false');}}}catch(e){}
</script>
