<?php
// Output buffering MUST start before any includes that call session_start(),
// otherwise "headers already sent" errors occur on servers with output_buffering=Off.
if (!ob_get_level()) {
    ob_start();
}
// navbar.php outputs the full HTML document shell (<!DOCTYPE>, <head>, <body>, nav)
include_once "navbar.php";

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$queryEsc = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
?>

<div class="container-fluid" style="padding-top:30px; min-height:60vh;">
  <h4 style="margin-bottom:16px;">Search results<?php if ($queryEsc !== '') echo ' for <em>&ldquo;' . $queryEsc . '&rdquo;</em>'; ?></h4>

  <div id="search-results">
    <?php if ($query === ''): ?>
      <div class="sr-hint">
        <div class="sr-hint__icon"><i class="fa fa-search"></i></div>
        <div class="sr-hint__text">Enter a search term above to find content.</div>
      </div>
    <?php else: ?>
      <div class="sr-loading">
        <div class="sr-spinner"></div>
        Searching...
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($query !== ''): ?>
<script>
(function() {
  var query = <?php echo json_encode($query, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  var container = document.getElementById('search-results');

  fetch('/api/search.php?q=' + encodeURIComponent(query))
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function(data) {
      var items = Array.isArray(data.results) ? data.results : [];
      var total = parseInt(data.total, 10) || 0;

      if (total === 0) {
        container.innerHTML =
          '<div class="sr-empty">' +
            '<div class="sr-empty__icon"><i class="fa fa-search"></i></div>' +
            '<div class="sr-empty__text">No results found for &ldquo;' + escHtml(query) + '&rdquo;</div>' +
            '<div class="sr-empty__hint">Try a different spelling or broader search term.</div>' +
          '</div>';
        return;
      }

      var fuzzyNotice = '';
      if (items.length > 0 && items[0].match_type && items[0].match_type !== 'exact' && items[0].match_type !== 'prefix') {
        var label = items[0].match_type === 'alias' ? 'synonym' :
                    items[0].match_type === 'soundex' ? 'sounds-like' : 'fuzzy';
        fuzzyNotice = '<div class="sr-fuzzy-notice">Showing <strong>' + label + '</strong> matches for &ldquo;' + escHtml(query) + '&rdquo;</div>';
      }

      var html = '<div class="sr-result-count">' + total + ' result' + (total !== 1 ? 's' : '') + ' found</div>' + fuzzyNotice;

      // Render category / subcategory browse groups first
      var groups = Array.isArray(data.groups) ? data.groups : [];
      if (groups.length > 0) {
        html += '<div class="sr-groups">';
        groups.forEach(function(g) {
          var icon = g.type === 'category' ? '&#128193;' : '&#128194;';
          var labelText = g.type === 'category' ? 'Category' : 'Subcategory';
          var countText = g.count ? ' &middot; ' + g.count + ' item' + (g.count !== 1 ? 's' : '') : '';
          var parentText = g.parent ? escHtml(g.parent) + ' / ' : '';
          html += '<a class="sr-group-card" href="' + escAttr(g.url || '#') + '">' +
                    '<span class="sr-group-card__icon">' + icon + '</span>' +
                    '<span class="sr-group-card__info">' +
                      '<span class="sr-group-card__label">' + labelText + '</span>' +
                      '<span class="sr-group-card__name">' + parentText + escHtml(g.name || '') + countText + '</span>' +
                    '</span>' +
                    '<span class="sr-group-card__arrow">&#8250;</span>' +
                  '</a>';
        });
        html += '</div>';
      }

      html += '<div class="sr-grid">';

      items.forEach(function(item) {
        var type = normalizeType(item.content_type);
        var thumb = '';

        if (item.thumbnail_path) {
			thumb = '<img src="' + escAttr(encodeContentUrlPath(item.thumbnail_path)) + '" alt="">';
			} else if (type === 'video') {
          thumb = '<img src="images/sample.png" data-lazy-thumb="' + escAttr(item.content_id) + '" alt="">';
        } else {
          var iconMap = {
            video: 'fa-film',
            audiobook: 'fa-headphones',
			music: 'fa-music',
            audio: 'fa-music',
            song: 'fa-music',
            pdf: 'fa-file-pdf-o',
            interactive: 'fa-puzzle-piece',
            tool: 'fa-wrench',
            book: 'fa-book',
            books: 'fa-book',
            ebook: 'fa-book',
            document: 'fa-file-text-o'
          };
          var iconCls = iconMap[type] || 'fa-file';
          thumb = '<span class="sr-card__thumb-placeholder"><i class="fa ' + iconCls + '" aria-hidden="true"></i></span>';
        }

        var duration = item.duration_seconds ? formatDuration(item.duration_seconds) : '';
        var badgeClass = 'sr-card__type-badge sr-card__type-badge--' + (type || 'unknown');
        var href = buildResultHref(item, type);

        // Build breadcrumb with clickable category / subcategory links
        var breadcrumbHtml = '';
        if (item.category) {
          var catLink = item.category_url
            ? '<a class="sr-card__breadcrumb-link" href="' + escAttr(item.category_url) + '">' + escHtml(item.category) + '</a>'
            : '<span>' + escHtml(item.category) + '</span>';

          breadcrumbHtml = catLink;

          if (item.subcategory) {
            var subcatLink = item.subcategory_url
              ? '<a class="sr-card__breadcrumb-link" href="' + escAttr(item.subcategory_url) + '">' + escHtml(item.subcategory) + '</a>'
              : '<span>' + escHtml(item.subcategory) + '</span>';

            breadcrumbHtml += ' <span class="sr-card__breadcrumb-sep">/</span> ' + subcatLink;
          }
        }

        html += '<div class="sr-card">' +
          '<a class="sr-card__thumb-link" href="' + escAttr(href) + '">' +
            '<div class="sr-card__thumb">' + thumb + '</div>' +
          '</a>' +
          '<div class="sr-card__info">' +
            '<a class="sr-card__title-link" href="' + escAttr(href) + '">' +
              '<div class="sr-card__title">' + escHtml(item.title || '') + '</div>' +
            '</a>' +
            (breadcrumbHtml ? '<div class="sr-card__breadcrumb">' + breadcrumbHtml + '</div>' : '') +
            '<div class="sr-card__meta">' +
              '<span class="' + badgeClass + '">' + escHtml(item.content_type || 'unknown') + '</span>' +
              (duration ? '<span class="sr-card__duration">' + duration + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>';
      });

      html += '</div>';
      container.innerHTML = html;
    })
    .catch(function() {
      container.innerHTML =
        '<div class="sr-empty">' +
          '<div class="sr-empty__icon"><i class="fa fa-exclamation-triangle"></i></div>' +
          '<div class="sr-empty__text">Something went wrong while searching.</div>' +
          '<div class="sr-empty__hint">Please try again later.</div>' +
        '</div>';
    });

  function normalizeType(value) {
    return String(value || '').toLowerCase().trim();
  }

function buildResultHref(item, type) {
  if (item && item.result_url) {
    return String(item.result_url);
  }

  var id = item && item.content_id != null ? String(item.content_id) : '';

    if (type === 'video' && id) {
      return 'watch.php?id=' + encodeURIComponent(id);
    }

    if (type === 'audiobook' && id) {
      return 'listen.php?id=' + encodeURIComponent(id);
    }

    if (
      (type === 'pdf' ||
       type === 'book' ||
       type === 'books' ||
       type === 'ebook' ||
       type === 'document') && id
    ) {
      return 'readpdf.php?id=' + encodeURIComponent(id);
    }

    if (item.subcategory_url) {
      return item.subcategory_url;
    }

    if (item.category_url) {
      return item.category_url;
    }

    return '#';
  }

function encodeContentUrlPath(path) {
  var value = String(path || '').replace(/\\/g, '/');

  if (!value) {
    return '';
  }

  /*
   * Keep absolute URLs unchanged. API thumbnail paths are normally relative,
   * such as:
   * content/Physics/Physics for Kids/Video #22.jpg
   */
/*
 * Keep normal application URLs and absolute URLs unchanged.
 *
 * Examples:
 *   book_thumb.php?folder=Physics
 *   /book_thumb.php?folder=Physics
 *   https://example.invalid/image.jpg
 *
 * Only filesystem-style content paths should be encoded segment by segment.
 */
if (
  /^https?:\/\//i.test(value) ||
  value.indexOf('?') !== -1 ||
  /\.php(?:$|\?)/i.test(value)
) {
  return value;
}

  /*
   * Preserve a leading slash if the API ever returns one. Encode each folder
   * and filename segment individually so "/" remains a path separator while
   * characters such as "#", spaces, "&", and Unicode punctuation are safe.
   */
  var hasLeadingSlash = value.charAt(0) === '/';

  var encoded = value
    .split('/')
    .filter(function(segment) {
      return segment !== '';
    })
    .map(function(segment) {
      return encodeURIComponent(segment);
    })
    .join('/');

  return (hasLeadingSlash ? '/' : '') + encoded;
}

  function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function escAttr(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function formatDuration(sec) {
    sec = parseInt(sec, 10) || 0;
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;

    if (h > 0) return h + ':' + pad(m) + ':' + pad(s);
    return m + ':' + pad(s);
  }

  function pad(n) {
    return n < 10 ? '0' + n : '' + n;
  }
})();
</script>
<?php endif; ?>

<?php include_once "footer.php"; ?>
<script src="js/lazy-thumbs.js"></script>