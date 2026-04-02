/**
 * Search Typeahead Component (FRE-40)
 *
 * Attaches to any input with [data-search-typeahead] attribute.
 * Debounced input (300ms), calls /api/search-suggest.php on 2+ chars.
 * Renders grouped dropdown (Categories, Content) with keyboard navigation.
 *
 * Vanilla JS, no dependencies.
 *
 * Usage:
 *   <input data-search-typeahead
 *          data-typeahead-on-category="functionName"
 *          data-typeahead-on-content="functionName">
 *
 * Callbacks receive the selected suggestion object.
 * If no callback configured, dispatches custom events on the input:
 *   'typeahead:select-category' with detail = {name, type}
 *   'typeahead:select-content'  with detail = {content_id, title, ...}
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 300;
    var MIN_CHARS = 2;
    var instanceId = 0;

    function initTypeahead(input) {
        var id = 'search-typeahead-' + (++instanceId);
        var debounceTimer = null;
        var suggestions = { categories: [], content: [] };
        var flatItems = [];
        var activeIndex = -1;
        var dropdown = null;
        var isOpen = false;

        // Create dropdown container
        dropdown = document.createElement('div');
        dropdown.className = 'st-dropdown';
        dropdown.id = id + '-listbox';
        dropdown.setAttribute('role', 'listbox');
        dropdown.setAttribute('aria-label', 'Search suggestions');
        dropdown.style.display = 'none';

        // Position relative to input
        var wrapper = document.createElement('div');
        wrapper.className = 'st-wrapper';
        wrapper.style.position = 'relative';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        wrapper.appendChild(dropdown);

        // ARIA attributes on input
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-owns', dropdown.id);
        input.setAttribute('autocomplete', 'off');

        // --- Event handlers ---

        input.addEventListener('input', function () {
            var term = input.value.trim();
            clearTimeout(debounceTimer);

            if (term.length < MIN_CHARS) {
                close();
                return;
            }

            debounceTimer = setTimeout(function () {
                fetchSuggestions(term);
            }, DEBOUNCE_MS);
        });

        input.addEventListener('keydown', function (e) {
            if (!isOpen) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    moveActive(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    moveActive(-1);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (activeIndex >= 0 && activeIndex < flatItems.length) {
                        selectItem(flatItems[activeIndex]);
                    }
                    break;
                case 'Escape':
                    e.preventDefault();
                    close();
                    input.focus();
                    break;
            }
        });

        input.addEventListener('focus', function () {
            if (flatItems.length > 0 && input.value.trim().length >= MIN_CHARS) {
                open();
            }
        });

        // Click outside closes
        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) {
                close();
            }
        });

        // --- Core functions ---

        function fetchSuggestions(term) {
            fetch('/api/search-suggest.php?q=' + encodeURIComponent(term))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    suggestions = data || { categories: [], content: [] };
                    buildFlatList();
                    render();
                    if (flatItems.length > 0) {
                        open();
                    } else {
                        close();
                    }
                })
                .catch(function () {
                    close();
                });
        }

        function buildFlatList() {
            flatItems = [];
            if (suggestions.categories) {
                suggestions.categories.forEach(function (cat) {
                    flatItems.push({ group: 'category', data: cat });
                });
            }
            if (suggestions.content) {
                suggestions.content.forEach(function (item) {
                    flatItems.push({ group: 'content', data: item });
                });
            }
        }

        function render() {
            dropdown.innerHTML = '';
            activeIndex = -1;

            if (flatItems.length === 0) {
                dropdown.style.display = 'none';
                return;
            }

            var currentGroup = '';
            flatItems.forEach(function (item, idx) {
                // Group header
                if (item.group !== currentGroup) {
                    currentGroup = item.group;
                    var header = document.createElement('div');
                    header.className = 'st-group-header';
                    header.textContent = currentGroup === 'category' ? 'Categories' : 'Content';
                    header.setAttribute('role', 'presentation');
                    dropdown.appendChild(header);
                }

                var option = document.createElement('div');
                option.className = 'st-option';
                option.id = id + '-option-' + idx;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.setAttribute('data-index', idx);

                if (item.group === 'category') {
                    option.innerHTML = '<span class="st-option__icon">&#128193;</span>' +
                        '<span class="st-option__text">' + escapeHtml(item.data.name) + '</span>';
                } else {
                    var badge = getTypeBadge(item.data.content_type);
                    var breadcrumb = '';
                    if (item.data.category) {
                        breadcrumb = item.data.category;
                        if (item.data.subcategory) {
                            breadcrumb += ' \u203A ' + item.data.subcategory;
                        }
                    }
                    option.innerHTML =
                        '<span class="st-option__icon">' + badge.icon + '</span>' +
                        '<div class="st-option__info">' +
                            '<span class="st-option__text">' + escapeHtml(item.data.title) + '</span>' +
                            (breadcrumb ? '<span class="st-option__meta">' + escapeHtml(breadcrumb) + '</span>' : '') +
                        '</div>' +
                        '<span class="st-option__badge st-badge--' + (item.data.content_type || 'tool') + '">' +
                            escapeHtml(item.data.content_type || '') +
                        '</span>';
                }

                option.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // prevent blur before click registers
                    selectItem(item);
                });

                option.addEventListener('mouseenter', function () {
                    setActive(idx);
                });

                dropdown.appendChild(option);
            });
        }

        function open() {
            dropdown.style.display = '';
            isOpen = true;
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            dropdown.style.display = 'none';
            isOpen = false;
            activeIndex = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function moveActive(delta) {
            var newIndex = activeIndex + delta;
            if (newIndex < 0) newIndex = flatItems.length - 1;
            if (newIndex >= flatItems.length) newIndex = 0;
            setActive(newIndex);
        }

        function setActive(idx) {
            // Deactivate previous
            var prevEl = dropdown.querySelector('.st-option--active');
            if (prevEl) {
                prevEl.classList.remove('st-option--active');
                prevEl.setAttribute('aria-selected', 'false');
            }

            activeIndex = idx;
            var el = document.getElementById(id + '-option-' + idx);
            if (el) {
                el.classList.add('st-option--active');
                el.setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', el.id);
                // Scroll into view
                el.scrollIntoView({ block: 'nearest' });
            }
        }

        function selectItem(item) {
            close();

            if (item.group === 'category') {
                // Check for callback
                var catCallback = input.getAttribute('data-typeahead-on-category');
                if (catCallback && typeof window[catCallback] === 'function') {
                    window[catCallback](item.data);
                } else {
                    input.dispatchEvent(new CustomEvent('typeahead:select-category', {
                        bubbles: true,
                        detail: item.data
                    }));
                }
            } else {
                var contentCallback = input.getAttribute('data-typeahead-on-content');
                if (contentCallback && typeof window[contentCallback] === 'function') {
                    window[contentCallback](item.data);
                } else {
                    input.dispatchEvent(new CustomEvent('typeahead:select-content', {
                        bubbles: true,
                        detail: item.data
                    }));
                }
            }
        }
    }

    // --- Helpers ---

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function getTypeBadge(type) {
        var badges = {
            video:       { icon: '&#127916;', color: '#3b82f6' },
            pdf:         { icon: '&#128196;', color: '#ef4444' },
            audiobook:   { icon: '&#127911;', color: '#8b5cf6' },
            interactive: { icon: '&#127918;', color: '#22c55e' },
            tool:        { icon: '&#128295;', color: '#6b7280' }
        };
        return badges[type] || badges.tool;
    }

    // --- Auto-init on DOM ready ---

    function initAll() {
        var inputs = document.querySelectorAll('[data-search-typeahead]');
        inputs.forEach(function (input) {
            if (!input._typeaheadInit) {
                input._typeaheadInit = true;
                initTypeahead(input);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Expose for dynamic init
    window.SearchTypeahead = { init: initAll, initInput: initTypeahead };
})();
