/**
 * EduPak Tiles — Minimal JS
 * Vanilla JS only, no jQuery. Supports Android 4.2+ WebView.
 */

(function () {
    'use strict';

    // ── Directory: search filtering ─────────────────────────────────────
    var searchInput = document.getElementById('dir-search');
    if (searchInput) {
        var debounceTimer = null;
        var items = document.querySelectorAll('.dir-item');
        var noResults = document.getElementById('dir-no-results');

        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var query = searchInput.value.toLowerCase().trim();
                var visibleCount = 0;

                for (var i = 0; i < items.length; i++) {
                    var label = items[i].getAttribute('data-label') || '';
                    if (query === '' || label.toLowerCase().indexOf(query) !== -1) {
                        items[i].className = items[i].className.replace(' dir-item--hidden', '');
                        visibleCount++;
                    } else {
                        if (items[i].className.indexOf('dir-item--hidden') === -1) {
                            items[i].className += ' dir-item--hidden';
                        }
                    }
                }

                // Show/hide section headers based on visible children
                var sections = document.querySelectorAll('.dir-section');
                for (var s = 0; s < sections.length; s++) {
                    var sectionItems = sections[s].querySelectorAll('.dir-item');
                    var hasVisible = false;
                    for (var j = 0; j < sectionItems.length; j++) {
                        if (sectionItems[j].className.indexOf('dir-item--hidden') === -1) {
                            hasVisible = true;
                            break;
                        }
                    }
                    sections[s].style.display = hasVisible ? '' : 'none';
                }

                if (noResults) {
                    noResults.style.display = (visibleCount === 0 && query !== '') ? 'block' : 'none';
                }
            }, 300);
        });
    }

    // ── Directory: anchor nav smooth scroll ──────────────────────────────
    var anchorLinks = document.querySelectorAll('.dir-anchor-link');
    for (var a = 0; a < anchorLinks.length; a++) {
        anchorLinks[a].addEventListener('click', function (e) {
            var href = this.getAttribute('href');
            if (href && href.charAt(0) === '#') {
                var target = document.getElementById(href.substring(1));
                if (target) {
                    e.preventDefault();
                    var navHeight = 120;
                    var top = target.getBoundingClientRect().top + window.pageYOffset - navHeight;
                    window.scrollTo(0, top);
                }
            }
        });
    }

    // ── Admin: classify button AJAX ─────────────────────────────────────
    var classifyBtn = document.getElementById('admin-classify-btn');
    if (classifyBtn) {
        classifyBtn.addEventListener('click', function (e) {
            e.preventDefault();
            classifyBtn.disabled = true;
            classifyBtn.textContent = 'Classifying...';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'classify.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            var csrfInput = document.querySelector('input[name="_csrf_token"]');
            var csrfToken = csrfInput ? csrfInput.value : '';

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    classifyBtn.disabled = false;
                    classifyBtn.textContent = 'Auto-Classify New Content';
                    if (xhr.status === 200) {
                        try {
                            var result = JSON.parse(xhr.responseText);
                            if (result.classified && result.classified > 0) {
                                alert('Classified ' + result.classified + ' new item(s). Reloading...');
                                window.location.reload();
                            } else {
                                alert(result.message || 'No new content to classify.');
                            }
                        } catch (err) {
                            alert('Classification complete. Reloading...');
                            window.location.reload();
                        }
                    } else {
                        alert('Error during classification. Please try again.');
                    }
                }
            };

            xhr.send('_csrf_token=' + encodeURIComponent(csrfToken) + '&action=classify');
        });
    }
})();
