/**
 * Lesson Plan Builder (FRE-13 Phase 4)
 *
 * Manages the Playlists tab in teacher.php:
 * - List view: shows all plans with create/edit/delete
 * - Detail view: inline editing, drag-reorder, add/remove items
 * - Builder flow: search → preview → select → save
 *
 * Vanilla JS only. No frameworks, no build tools.
 */
(function () {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────────
    // State
    // ─────────────────────────────────────────────────────────────────────────
    var state = {
        view: 'list',           // 'list' | 'detail' | 'builder'
        plans: [],              // all fetched plans
        currentPlan: null,      // plan being viewed/edited
        selectedItems: [],      // items selected in builder [{id, title, ...}]
        searchResults: [],      // search results from API
        searchTerm: '',
        builderMode: 'new',     // 'new' | 'add' (adding to existing plan)
        matchType: '',          // 'exact', 'prefix', 'alias', 'fuzzy', 'soundex'
        csrfToken: '',
        dragState: null,        // drag-reorder state
        debounceTimer: null,
        logTimer: null
    };

    // Grab the CSRF token from the page meta tag or hidden input
    function initCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            state.csrfToken = meta.getAttribute('content');
            return;
        }
        var input = document.querySelector('input[name="_csrf_token"]');
        if (input) {
            state.csrfToken = input.value;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API helpers
    // ─────────────────────────────────────────────────────────────────────────
    function apiGet(action, params) {
        var url = '/api/lesson_plans.php?action=' + encodeURIComponent(action);
        if (params) {
            Object.keys(params).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            });
        }
        return fetch(url).then(function (r) { return r.json(); });
    }

    function apiPost(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('_csrf_token', state.csrfToken);
        if (data) {
            Object.keys(data).forEach(function (k) {
                body.append(k, data[k]);
            });
        }
        return fetch('/api/lesson_plans.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); });
    }

    function searchApi(term, shouldLog) {
        var logParam = shouldLog ? '' : '&log=0';
        return fetch('/api/search.php?q=' + encodeURIComponent(term) + logParam)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Support both old array format and new {results, total, query} format
                if (Array.isArray(data)) {
                    return { results: data, total: data.length, query: term };
                }
                return data;
            })
            .catch(function () { return { results: [], total: 0, query: term }; });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOM helpers
    // ─────────────────────────────────────────────────────────────────────────
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'className') node.className = attrs[k];
                else if (k === 'innerHTML') node.innerHTML = attrs[k];
                else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
                else node.setAttribute(k, attrs[k]);
            });
        }
        if (children) {
            if (typeof children === 'string') node.textContent = children;
            else if (Array.isArray(children)) {
                children.forEach(function (c) { if (c) node.appendChild(c); });
            }
        }
        return node;
    }

    function getContainer() {
        return document.getElementById('playlists-content');
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return d.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function showLoading(container) {
        container.innerHTML = '<div class="lb-loading"><div class="lb-spinner"></div></div>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LIST VIEW
    // ─────────────────────────────────────────────────────────────────────────
    function renderListView() {
        state.view = 'list';
        state.currentPlan = null;
        state.selectedItems = [];
        var container = getContainer();
        if (!container) return;

        showLoading(container);

        apiGet('list').then(function (plans) {
            state.plans = plans;
            container.innerHTML = '';

            // Header with New Playlist button
            var header = el('div', { className: 'lb-list-header' }, [
                el('h2', { className: 'lb-title' }, 'Lesson Plans'),
                el('button', {
                    className: 'lb-btn lb-btn-primary',
                    onClick: function () { openBuilder('new'); }
                }, '+ New Lesson Plan')
            ]);
            container.appendChild(header);

            if (!plans || plans.length === 0) {
                container.appendChild(el('div', { className: 'lb-empty-state' }, [
                    el('div', { className: 'lb-empty-icon', innerHTML: '&#128218;' }),
                    el('h3', null, 'No Lesson Plans Yet'),
                    el('p', null, 'Create your first lesson plan to organize content for your students.'),
                    el('button', {
                        className: 'lb-btn lb-btn-primary lb-btn-lg',
                        onClick: function () { openBuilder('new'); }
                    }, 'Create Your First Lesson Plan')
                ]));
                return;
            }

            var grid = el('div', { className: 'lb-plan-grid' });
            plans.forEach(function (plan) {
                var publishBadge = null;
                if (typeof window.EduPakPublish !== 'undefined') {
                    publishBadge = window.EduPakPublish.createStatusBadge(plan);
                }
                var assignBtn = el('button', {
                    className: 'lb-btn lb-btn-accent lb-btn-sm lb-plan-card-assign',
                    onClick: function (e) { e.stopPropagation(); openAssignModal(plan); },
                    title: 'Assign to students'
                }, 'Assign');
                var card = el('div', {
                    className: 'lb-plan-card',
                    onClick: function () { openDetail(plan.id); }
                }, [
                    el('div', { className: 'lb-plan-card-body' }, [
                        el('h3', { className: 'lb-plan-card-title' }, truncate(plan.title, 60)),
                        publishBadge,
                        el('div', { className: 'lb-plan-card-meta' }, [
                            el('span', { className: 'lb-plan-card-count' }, plan.item_count + ' item' + (plan.item_count !== 1 ? 's' : '')),
                            el('span', { className: 'lb-plan-card-dot' }, '\u00B7'),
                            el('span', { className: 'lb-plan-card-creator' }, plan.creator_name)
                        ]),
                        el('div', { className: 'lb-plan-card-footer' }, [
                            el('span', { className: 'lb-plan-card-date' }, formatDate(plan.created_at)),
                            assignBtn
                        ])
                    ])
                ]);
                grid.appendChild(card);
            });
            container.appendChild(grid);
        }).catch(function () {
            container.innerHTML = '<div class="lb-error">Failed to load lesson plans. Please try again.</div>';
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DETAIL VIEW
    // ─────────────────────────────────────────────────────────────────────────
    function openDetail(planId) {
        state.view = 'detail';
        var container = getContainer();
        if (!container) return;
        showLoading(container);

        apiGet('detail', { plan_id: planId }).then(function (plan) {
            if (plan.error) {
                container.innerHTML = '<div class="lb-error">' + plan.error + '</div>';
                return;
            }
            state.currentPlan = plan;
            renderDetailView();
        }).catch(function () {
            container.innerHTML = '<div class="lb-error">Failed to load lesson plan details.</div>';
        });
    }

    function renderDetailView() {
        var container = getContainer();
        if (!container || !state.currentPlan) return;
        var plan = state.currentPlan;
        container.innerHTML = '';

        // Back button
        container.appendChild(el('button', {
            className: 'lb-btn lb-btn-text lb-back-btn',
            onClick: renderListView
        }, '\u2190 Back to Lesson Plans'));

        // Title (editable inline)
        var titleRow = el('div', { className: 'lb-detail-header' });
        var titleInput = el('input', {
            className: 'lb-detail-title-input',
            type: 'text',
            value: plan.title,
            maxlength: '255'
        });
        titleInput.value = plan.title;
        var saveStatus = el('span', { className: 'lb-save-status' });
        titleInput.addEventListener('change', function () {
            var newTitle = titleInput.value.trim();
            if (!newTitle || newTitle === plan.title) return;
            saveStatus.textContent = 'Saving...';
            apiPost('update', { plan_id: plan.id, title: newTitle }).then(function (res) {
                if (res.ok) {
                    plan.title = newTitle;
                    saveStatus.textContent = 'Saved';
                    setTimeout(function () { saveStatus.textContent = ''; }, 2000);
                } else {
                    saveStatus.textContent = res.error || 'Error';
                }
            });
        });
        titleRow.appendChild(titleInput);
        titleRow.appendChild(saveStatus);
        container.appendChild(titleRow);

        // Meta info
        container.appendChild(el('div', { className: 'lb-detail-meta' }, [
            el('span', null, plan.item_count + ' item' + (plan.item_count !== 1 ? 's' : '')),
            el('span', { className: 'lb-plan-card-dot' }, '\u00B7'),
            el('span', null, 'Created by ' + plan.creator_name),
            el('span', { className: 'lb-plan-card-dot' }, '\u00B7'),
            el('span', null, formatDate(plan.created_at))
        ]));

        // Action buttons
        var actions = el('div', { className: 'lb-detail-actions' }, [
            el('button', {
                className: 'lb-btn lb-btn-secondary',
                onClick: function () { openBuilder('add'); }
            }, '+ Add Content'),
            el('button', {
                className: 'lb-btn lb-btn-accent',
                onClick: function () { openAssignModal(plan); }
            }, 'Assign'),
            el('button', {
                className: 'lb-btn lb-btn-danger',
                onClick: function () { confirmDeletePlan(plan.id); }
            }, 'Delete Lesson Plan')
        ]);
        container.appendChild(actions);

        // Publish panel (from lesson-publish.js)
        if (typeof window.EduPakPublish !== 'undefined') {
            var publishContainer = el('div', { className: 'lb-publish-section' });
            window.EduPakPublish.renderPublishPanel(publishContainer, plan, function (updated) {
                // Refresh plan data after publish changes
                if (updated && updated.segments) {
                    plan.published_segments = JSON.stringify(updated.segments);
                }
                if (updated && updated.icon) plan.icon = updated.icon;
                if (updated && updated.color) plan.color = updated.color;
            });
            container.appendChild(publishContainer);
        }

        // Content items list
        var items = plan.content_ids || [];
        if (items.length === 0) {
            container.appendChild(el('div', { className: 'lb-empty-items' }, [
                el('p', null, 'This lesson plan is empty.'),
                el('button', {
                    className: 'lb-btn lb-btn-primary',
                    onClick: function () { openBuilder('add'); }
                }, 'Add Content')
            ]));
        } else {
            var list = el('div', { className: 'lb-items-list', id: 'lb-items-list' });
            items.forEach(function (item, idx) {
                list.appendChild(renderDetailItem(item, idx, items.length));
            });
            container.appendChild(list);
            initDragReorder(list);
        }
    }

    function renderDetailItem(item, idx, total) {
        var title = (typeof item === 'object') ? item.title : String(item);
        var isUnavailable = (typeof item === 'object') && item.unavailable;
        var displayTitle = isUnavailable ? '[Removed content]' : truncate(title, 60);
        var row = el('div', {
            className: 'lb-item-row' + (isUnavailable ? ' lb-item-row--unavailable' : ''),
            'data-index': String(idx)
        }, [
            el('div', { className: 'lb-item-drag', innerHTML: '&#9776;' }),
            el('div', { className: 'lb-item-thumb' }),
            el('div', { className: 'lb-item-title' }, displayTitle),
            el('div', { className: 'lb-item-arrows' }, [
                idx > 0 ? el('button', {
                    className: 'lb-btn lb-btn-icon',
                    title: 'Move up',
                    onClick: function (e) { e.stopPropagation(); moveItem(idx, idx - 1); }
                }, '\u2191') : null,
                idx < total - 1 ? el('button', {
                    className: 'lb-btn lb-btn-icon',
                    title: 'Move down',
                    onClick: function (e) { e.stopPropagation(); moveItem(idx, idx + 1); }
                }, '\u2193') : null
            ]),
            el('button', {
                className: 'lb-btn lb-btn-icon lb-item-remove',
                title: 'Remove',
                onClick: function (e) { e.stopPropagation(); removeItem(idx); }
            }, '\u2715')
        ]);
        return row;
    }

    function moveItem(fromIdx, toIdx) {
        var plan = state.currentPlan;
        if (!plan) return;
        var items = plan.content_ids.slice();
        var moved = items.splice(fromIdx, 1)[0];
        items.splice(toIdx, 0, moved);
        plan.content_ids = items;
        plan.item_count = items.length;

        apiPost('reorder_items', {
            plan_id: plan.id,
            content_ids: JSON.stringify(items)
        });
        renderDetailView();
    }

    function removeItem(idx) {
        var plan = state.currentPlan;
        if (!plan) return;
        var items = plan.content_ids.slice();
        items.splice(idx, 1);
        plan.content_ids = items;
        plan.item_count = items.length;

        apiPost('update', {
            plan_id: plan.id,
            content_ids: JSON.stringify(items)
        });
        renderDetailView();
    }

    function confirmDeletePlan(planId) {
        var overlay = el('div', { className: 'lb-modal-overlay' });
        var modal = el('div', { className: 'lb-modal' }, [
            el('h3', null, 'Delete Lesson Plan?'),
            el('p', null, 'This will permanently delete this lesson plan. This action cannot be undone.'),
            el('div', { className: 'lb-modal-actions' }, [
                el('button', {
                    className: 'lb-btn lb-btn-secondary',
                    onClick: function () { overlay.remove(); }
                }, 'Cancel'),
                el('button', {
                    className: 'lb-btn lb-btn-danger',
                    onClick: function () {
                        overlay.remove();
                        apiPost('delete', { plan_id: planId }).then(function (res) {
                            if (res.ok) {
                                renderListView();
                            }
                        });
                    }
                }, 'Delete')
            ])
        ]);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DRAG REORDER (pointer events)
    // ─────────────────────────────────────────────────────────────────────────
    function initDragReorder(listEl) {
        var handles = listEl.querySelectorAll('.lb-item-drag');
        handles.forEach(function (handle) {
            handle.style.touchAction = 'none';
            handle.addEventListener('pointerdown', onDragStart);
        });
    }

    function onDragStart(e) {
        var row = e.target.closest('.lb-item-row');
        if (!row) return;
        e.preventDefault();

        var list = document.getElementById('lb-items-list');
        if (!list) return;

        var rect = row.getBoundingClientRect();
        var listRect = list.getBoundingClientRect();

        state.dragState = {
            row: row,
            startY: e.clientY,
            offsetY: e.clientY - rect.top,
            rowHeight: rect.height,
            fromIndex: parseInt(row.getAttribute('data-index'), 10),
            listTop: listRect.top
        };

        row.classList.add('lb-item-dragging');
        row.setPointerCapture(e.pointerId);
        row.addEventListener('pointermove', onDragMove);
        row.addEventListener('pointerup', onDragEnd);
    }

    function onDragMove(e) {
        if (!state.dragState) return;
        var ds = state.dragState;
        var dy = e.clientY - ds.startY;
        ds.row.style.transform = 'translateY(' + dy + 'px)';
    }

    function onDragEnd(e) {
        if (!state.dragState) return;
        var ds = state.dragState;
        ds.row.classList.remove('lb-item-dragging');
        ds.row.style.transform = '';
        ds.row.removeEventListener('pointermove', onDragMove);
        ds.row.removeEventListener('pointerup', onDragEnd);

        var dy = e.clientY - ds.startY;
        var moved = Math.round(dy / ds.rowHeight);
        var toIndex = Math.max(0, Math.min(ds.fromIndex + moved, (state.currentPlan.content_ids.length - 1)));

        state.dragState = null;

        if (toIndex !== ds.fromIndex) {
            moveItem(ds.fromIndex, toIndex);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUILDER FLOW
    // ─────────────────────────────────────────────────────────────────────────
    function openBuilder(mode) {
        state.view = 'builder';
        state.builderMode = mode;
        state.selectedItems = [];
        state.searchResults = [];
        state.searchTerm = '';
        state.expandedPreview = null;
        renderBuilderView();
    }

    function renderBuilderView() {
        var container = getContainer();
        if (!container) return;
        container.innerHTML = '';

        // Back button
        container.appendChild(el('button', {
            className: 'lb-btn lb-btn-text lb-back-btn',
            onClick: function () {
                if (state.builderMode === 'add' && state.currentPlan) {
                    renderDetailView();
                } else {
                    renderListView();
                }
            }
        }, '\u2190 Back'));

        container.appendChild(el('h2', { className: 'lb-title' },
            state.builderMode === 'new' ? 'Create New Lesson Plan' : 'Add Content to Lesson Plan'));

        // Search input with typeahead
        var searchWrap = el('div', { className: 'lb-search-wrap' });
        var searchInput = el('input', {
            className: 'lb-search-input',
            type: 'search',
            placeholder: 'Search videos, topics, keywords\u2026',
            autocomplete: 'off',
            'data-search-typeahead': ''
        });
        searchInput.addEventListener('input', function () {
            var term = searchInput.value.trim();
            state.searchTerm = term;
            clearTimeout(state.debounceTimer);
            clearTimeout(state.logTimer);
            if (term.length < 2) {
                state.searchResults = [];
                state.matchType = '';
                renderSearchResults();
                return;
            }
            // Fast debounce for showing results (no logging)
            state.debounceTimer = setTimeout(function () {
                performSearch(term, false);
            }, 300);
            // Longer idle timer — if user stops typing for 2s, log the final query
            state.logTimer = setTimeout(function () {
                searchApi(term, true);
            }, 2000);
        });
        // Handle typeahead category selection — trigger filtered search
        searchInput.addEventListener('typeahead:select-category', function (e) {
            var cat = e.detail;
            if (cat && cat.name) {
                searchInput.value = cat.name;
                state.searchTerm = cat.name;
                clearTimeout(state.debounceTimer);
                clearTimeout(state.logTimer);
                performSearch(cat.name, true);
            }
        });
        // Handle typeahead content selection — trigger full search with that term
        searchInput.addEventListener('typeahead:select-content', function (e) {
            var item = e.detail;
            if (item && item.title) {
                searchInput.value = item.title;
                state.searchTerm = item.title;
                clearTimeout(state.debounceTimer);
                clearTimeout(state.logTimer);
                performSearch(item.title, true);
            }
        });
        // Enter key — commit the search immediately with logging
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var term = searchInput.value.trim();
                if (term.length >= 2) {
                    clearTimeout(state.debounceTimer);
                    clearTimeout(state.logTimer);
                    performSearch(term, true);
                }
            }
        });
        searchWrap.appendChild(searchInput);
        container.appendChild(searchWrap);

        // Init typeahead on the new input
        if (window.SearchTypeahead) {
            window.SearchTypeahead.initInput(searchInput);
        }

        // Results container
        container.appendChild(el('div', { id: 'lb-search-results', className: 'lb-search-results' }));

        // Selection bar (sticky bottom)
        container.appendChild(renderSelectionBar());
    }

    function performSearch(term, shouldLog) {
        var resultsEl = document.getElementById('lb-search-results');
        if (resultsEl) resultsEl.innerHTML = '<div class="sr-loading"><div class="sr-spinner"></div> Searching\u2026</div>';

        searchApi(term, shouldLog).then(function (data) {
            state.searchResults = Array.isArray(data.results) ? data.results : [];
            state.searchTotal = data.total || state.searchResults.length;
            state.searchQuery = data.query || term;
            // Capture match type from first result (all share same type per query)
            state.matchType = (state.searchResults.length > 0 && state.searchResults[0].match_type)
                ? state.searchResults[0].match_type : '';
            renderSearchResults();
        });
    }

    // Category color map for placeholders
    var categoryColors = {
        'Science':     '#3b82f6',
        'Math':        '#ef4444',
        'History':     '#f59e0b',
        'English':     '#8b5cf6',
        'Art':         '#ec4899',
        'Music':       '#06b6d4',
        'Technology':  '#10b981',
        'Health':      '#f97316',
        'Geography':   '#6366f1',
        'Language':    '#14b8a6'
    };

    function getCategoryColor(category) {
        if (!category) return '#6b7280';
        // Check exact match first
        if (categoryColors[category]) return categoryColors[category];
        // Check partial match
        var lower = category.toLowerCase();
        var keys = Object.keys(categoryColors);
        for (var i = 0; i < keys.length; i++) {
            if (lower.indexOf(keys[i].toLowerCase()) !== -1) return categoryColors[keys[i]];
        }
        // Hash-based fallback color
        var hash = 0;
        for (var j = 0; j < category.length; j++) {
            hash = category.charCodeAt(j) + ((hash << 5) - hash);
        }
        var hue = Math.abs(hash) % 360;
        return 'hsl(' + hue + ', 55%, 50%)';
    }

    function renderSearchResults() {
        var resultsEl = document.getElementById('lb-search-results');
        if (!resultsEl) return;
        resultsEl.innerHTML = '';

        if (state.searchTerm.length < 2) {
            resultsEl.innerHTML = '<div class="sr-hint">' +
                '<div class="sr-hint__icon">&#128270;</div>' +
                '<div class="sr-hint__text">Start typing to search content\u2026</div>' +
                '</div>';
            return;
        }

        if (state.searchResults.length === 0) {
            resultsEl.innerHTML = '<div class="sr-empty">' +
                '<div class="sr-empty__icon">&#128270;</div>' +
                '<div class="sr-empty__text">No content found for \u2018' + escapeHtml(state.searchTerm) + '\u2019.</div>' +
                '<div class="sr-empty__hint">Try a different spelling or browse by category.</div>' +
                '</div>';
            return;
        }

        // Result count
        var countText = 'Showing ' + state.searchResults.length + ' result' +
            (state.searchResults.length !== 1 ? 's' : '') +
            ' for \u2018' + escapeHtml(state.searchQuery || state.searchTerm) + '\u2019';
        resultsEl.appendChild(el('div', { className: 'sr-result-count' }, countText));

        // Fuzzy/alias/soundex notice
        if (state.matchType && state.matchType !== 'exact' && state.matchType !== 'prefix') {
            var notice = el('div', { className: 'sr-fuzzy-notice' });
            notice.innerHTML = 'Showing similar results for \u2018<strong>' + escapeHtml(state.searchTerm) + '</strong>\u2019';
            resultsEl.appendChild(notice);
        }

        // Figure out which items are already in the current plan
        var existingIds = {};
        if (state.currentPlan && state.currentPlan.content_ids) {
            state.currentPlan.content_ids.forEach(function (item) {
                var id = (typeof item === 'object') ? item.id : item;
                existingIds[id] = true;
            });
        }

        // Visual card grid
        var grid = el('div', { className: 'sr-grid' });

        state.searchResults.forEach(function (result) {
            var contentId = result.content_id || result.id || '';
            var isInPlan = existingIds[contentId];
            var isSelected = state.selectedItems.some(function (s) {
                return (s.id || s.content_id) === contentId;
            });

            var cardClass = 'sr-card';
            if (isSelected) cardClass += ' sr-card--selected';
            if (isInPlan) cardClass += ' sr-card--in-plan';

            var card = el('div', {
                className: cardClass,
                'data-id': contentId,
                tabindex: '0',
                role: 'button',
                'aria-label': 'Preview ' + (result.title || contentId) + (isInPlan ? ' (already in lesson plan)' : '')
            });

            // Thumbnail — category-colored placeholders
            var thumb = el('div', { className: 'sr-card__thumb' });
            if (result.thumbnail_path) {
                thumb.appendChild(el('img', {
                    src: result.thumbnail_path,
                    alt: '',
                    loading: 'lazy'
                }));
            } else {
                var catColor = getCategoryColor(result.category);
                var catInitial = (result.category || 'V').charAt(0).toUpperCase();
                var placeholder = el('div', { className: 'sr-card__thumb-cat' });
                placeholder.style.backgroundColor = catColor;
                placeholder.textContent = catInitial;
                thumb.appendChild(placeholder);
            }
            card.appendChild(thumb);

            // Info section
            var breadcrumb = '';
            if (result.category) {
                breadcrumb = result.category;
                if (result.subcategory) {
                    breadcrumb += ' \u203A ' + result.subcategory;
                }
            }

            var metaChildren = [];
            if (result.content_type) {
                metaChildren.push(el('span', {
                    className: 'sr-card__type-badge sr-card__type-badge--' + result.content_type
                }, result.content_type));
            }
            if (result.duration_seconds) {
                metaChildren.push(el('span', { className: 'sr-card__duration' }, formatDuration(result.duration_seconds)));
            }

            var info = el('div', { className: 'sr-card__info' }, [
                el('div', { className: 'sr-card__title' }, result.title || contentId),
                breadcrumb ? el('div', { className: 'sr-card__breadcrumb' }, breadcrumb) : null,
                metaChildren.length > 0 ? el('div', { className: 'sr-card__meta' }, metaChildren) : null
            ]);
            card.appendChild(info);

            // Action area: quick-add button or in-plan label
            if (isInPlan) {
                card.appendChild(el('span', { className: 'sr-card__in-plan-label' }, 'In plan'));
            } else {
                var addBtn = el('button', {
                    className: 'sr-card__add-btn' + (isSelected ? ' sr-card__add-btn--added' : ''),
                    onClick: function (e) {
                        e.stopPropagation();
                        toggleSelectItem(result);
                    },
                    title: isSelected ? 'Remove from selection' : 'Quick add to lesson plan',
                    'aria-label': isSelected ? 'Remove ' + (result.title || '') : 'Add ' + (result.title || '')
                }, isSelected ? '\u2713' : '+');
                card.appendChild(addBtn);
            }

            // Click card body to open preview modal
            card.addEventListener('click', function (e) {
                // Don't open preview if clicking the add button
                if (e.target.closest('.sr-card__add-btn') || e.target.closest('.sr-card__in-plan-label')) return;
                openPreviewModal(result, isInPlan);
            });

            // Keyboard: Enter to preview, Space to quick-add
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    openPreviewModal(result, isInPlan);
                } else if (e.key === ' ' && !isInPlan) {
                    e.preventDefault();
                    toggleSelectItem(result);
                }
            });

            grid.appendChild(card);
        });

        resultsEl.appendChild(grid);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VIDEO PREVIEW MODAL
    // ─────────────────────────────────────────────────────────────────────────
    function openPreviewModal(result, isInPlan) {
        var contentId = result.content_id || result.id || '';

        // Create overlay
        var overlay = el('div', { className: 'sr-preview-overlay' });

        // Build modal
        var modal = el('div', { className: 'sr-preview-modal' });

        // Close button
        var closeBtn = el('button', {
            className: 'sr-preview-close',
            onClick: function () { closePreview(); },
            'aria-label': 'Close preview',
            title: 'Close'
        }, '\u2715');
        modal.appendChild(closeBtn);

        // Video player section
        var playerSection = el('div', { className: 'sr-preview-player' });
        var videoEl = null;

        var videoPath = result.file_path || result.content_id || '';
        if (result.content_type === 'video' && videoPath) {
            videoEl = document.createElement('video');
            videoEl.className = 'sr-preview-video';
            videoEl.controls = true;
            videoEl.preload = 'metadata';
            videoEl.setAttribute('controlsList', 'nodownload');
            videoEl.src = '/' + videoPath.replace(/^\/+/, '');
            playerSection.appendChild(videoEl);

            // Playback speed controls
            var speedBar = el('div', { className: 'sr-preview-speeds' });
            ['0.5', '1', '1.5', '2'].forEach(function (speed) {
                var btn = el('button', {
                    className: 'sr-preview-speed-btn' + (speed === '1' ? ' sr-preview-speed-btn--active' : ''),
                    onClick: function () {
                        if (videoEl) videoEl.playbackRate = parseFloat(speed);
                        speedBar.querySelectorAll('.sr-preview-speed-btn').forEach(function (b) {
                            b.classList.remove('sr-preview-speed-btn--active');
                        });
                        btn.classList.add('sr-preview-speed-btn--active');
                    }
                }, speed + 'x');
                speedBar.appendChild(btn);
            });
            playerSection.appendChild(speedBar);
        } else {
            // Non-video or no file path — show placeholder
            var catColor = getCategoryColor(result.category);
            var pholder = el('div', { className: 'sr-preview-placeholder' });
            pholder.style.backgroundColor = catColor;
            pholder.innerHTML = getContentPlaceholder(result.content_type);
            playerSection.appendChild(pholder);
        }
        modal.appendChild(playerSection);

        // Info panel
        var breadcrumb = '';
        if (result.category) {
            breadcrumb = result.category;
            if (result.subcategory) breadcrumb += ' \u203A ' + result.subcategory;
        }

        var infoPanel = el('div', { className: 'sr-preview-info' }, [
            el('h2', { className: 'sr-preview-title' }, result.title || contentId),
            breadcrumb ? el('div', { className: 'sr-preview-breadcrumb' }, breadcrumb) : null,
            result.content_type ? el('span', {
                className: 'sr-card__type-badge sr-card__type-badge--' + result.content_type
            }, result.content_type) : null,
            result.duration_seconds ? el('div', { className: 'sr-preview-duration' }, 'Duration: ' + formatDuration(result.duration_seconds)) : null,
            result.file_path ? el('div', { className: 'sr-preview-filepath' }, result.file_path) : null
        ]);
        modal.appendChild(infoPanel);

        // Action buttons
        var actions = el('div', { className: 'sr-preview-actions' });
        if (!isInPlan) {
            var isSelected = state.selectedItems.some(function (s) {
                return (s.id || s.content_id) === contentId;
            });
            actions.appendChild(el('button', {
                className: 'lb-btn lb-btn-primary sr-preview-add-btn',
                onClick: function () {
                    if (!isSelected) {
                        toggleSelectItem(result);
                    }
                    closePreview();
                }
            }, 'Add to Lesson Plan'));
        } else {
            actions.appendChild(el('div', { className: 'sr-preview-in-plan' }, 'Already in lesson plan'));
        }
        actions.appendChild(el('button', {
            className: 'lb-btn lb-btn-secondary',
            onClick: function () { closePreview(); }
        }, 'Close'));
        modal.appendChild(actions);

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        // Animate in
        requestAnimationFrame(function () {
            overlay.classList.add('sr-preview-overlay--visible');
        });

        // Close handlers
        function closePreview() {
            if (videoEl) {
                videoEl.pause();
                videoEl.src = '';
            }
            overlay.classList.remove('sr-preview-overlay--visible');
            setTimeout(function () { overlay.remove(); }, 200);
        }

        // Click outside modal to close
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closePreview();
        });

        // Escape key to close
        function onEsc(e) {
            if (e.key === 'Escape') {
                closePreview();
                document.removeEventListener('keydown', onEsc);
            }
        }
        document.addEventListener('keydown', onEsc);
    }

    function getContentPlaceholder(contentType) {
        var placeholders = {
            video:       '&#127916;',
            pdf:         '&#128196;',
            audiobook:   '&#127911;',
            interactive: '&#127918;',
            tool:        '&#128295;'
        };
        return placeholders[contentType] || '&#128196;';
    }

    function toggleSelectItem(result) {
        var contentId = result.content_id || result.id || '';
        var idx = state.selectedItems.findIndex(function (s) {
            return (s.id || s.content_id) === contentId;
        });

        if (idx >= 0) {
            state.selectedItems.splice(idx, 1);
        } else {
            // Check max 50 items
            var currentCount = (state.currentPlan && state.currentPlan.content_ids)
                ? state.currentPlan.content_ids.length : 0;
            var totalAfter = currentCount + state.selectedItems.length + 1;
            if (totalAfter > 50) {
                alert('Maximum 50 items per lesson plan.');
                return;
            }
            if (totalAfter >= 45) {
                var remaining = 50 - totalAfter;
                var warningEl = document.querySelector('.lb-limit-warning');
                if (!warningEl) {
                    var bar = document.querySelector('.lb-selection-bar');
                    if (bar) {
                        warningEl = el('div', { className: 'lb-limit-warning' });
                        bar.insertBefore(warningEl, bar.firstChild);
                    }
                }
                if (warningEl) {
                    warningEl.textContent = 'Approaching limit: ' + remaining + ' more item' + (remaining !== 1 ? 's' : '') + ' allowed';
                }
            }
            state.selectedItems.push({
                id: contentId,
                title: result.title || contentId
            });
        }

        renderSearchResults();
        updateSelectionBar();
    }

    function renderSelectionBar() {
        var bar = el('div', {
            className: 'lb-selection-bar',
            id: 'lb-selection-bar',
            style: state.selectedItems.length > 0 ? '' : 'display:none'
        });
        updateSelectionBarContent(bar);
        return bar;
    }

    function updateSelectionBar() {
        var bar = document.getElementById('lb-selection-bar');
        if (!bar) return;
        bar.style.display = state.selectedItems.length > 0 ? '' : 'none';
        updateSelectionBarContent(bar);
    }

    function updateSelectionBarContent(bar) {
        bar.innerHTML = '';
        if (state.selectedItems.length === 0) return;

        var count = el('div', { className: 'lb-selection-count' },
            state.selectedItems.length + ' selected');
        bar.appendChild(count);

        var chips = el('div', { className: 'lb-selection-chips' });
        state.selectedItems.forEach(function (item, idx) {
            var chip = el('span', { className: 'lb-chip' }, [
                el('span', { className: 'lb-chip-text' }, truncate(item.title, 20)),
                el('button', {
                    className: 'lb-chip-remove',
                    onClick: function () {
                        state.selectedItems.splice(idx, 1);
                        renderSearchResults();
                        updateSelectionBar();
                    }
                }, '\u2715')
            ]);
            chips.appendChild(chip);
        });
        bar.appendChild(chips);

        var btnLabel = state.builderMode === 'new' ? 'Save as Lesson Plan' : 'Add to Lesson Plan';
        bar.appendChild(el('button', {
            className: 'lb-btn lb-btn-primary lb-selection-save',
            onClick: function () {
                if (state.builderMode === 'new') {
                    showNameModal();
                } else {
                    addToPlan();
                }
            }
        }, btnLabel));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NAME & SAVE MODAL
    // ─────────────────────────────────────────────────────────────────────────
    function showNameModal() {
        var overlay = el('div', { className: 'lb-modal-overlay' });
        var nameInput = el('input', {
            className: 'lb-modal-input',
            type: 'text',
            placeholder: 'Lesson plan name\u2026',
            maxlength: '255',
            autofocus: 'true'
        });
        var errorEl = el('div', { className: 'lb-modal-error' });

        var modal = el('div', { className: 'lb-modal' }, [
            el('h3', null, 'Name Your Lesson Plan'),
            el('p', null, state.selectedItems.length + ' item' + (state.selectedItems.length !== 1 ? 's' : '') + ' selected'),
            nameInput,
            errorEl,
            el('div', { className: 'lb-modal-actions' }, [
                el('button', {
                    className: 'lb-btn lb-btn-secondary',
                    onClick: function () { overlay.remove(); }
                }, 'Cancel'),
                el('button', {
                    className: 'lb-btn lb-btn-primary',
                    onClick: function () {
                        var title = nameInput.value.trim();
                        if (!title) {
                            errorEl.textContent = 'Please enter a name for the lesson plan.';
                            return;
                        }
                        saveNewPlan(title, overlay);
                    }
                }, 'Save')
            ])
        ]);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        setTimeout(function () { nameInput.focus(); }, 100);
    }

    function saveNewPlan(title, overlay) {
        var contentIds = state.selectedItems.map(function (item) {
            return { id: item.id, title: item.title };
        });

        apiPost('create', {
            title: title,
            content_ids: JSON.stringify(contentIds)
        }).then(function (res) {
            overlay.remove();
            if (res.ok) {
                renderListView();
            } else {
                alert(res.error || 'Failed to create lesson plan');
            }
        }).catch(function () {
            overlay.remove();
            alert('Failed to create lesson plan. Please try again.');
        });
    }

    function addToPlan() {
        var plan = state.currentPlan;
        if (!plan) return;

        var existing = plan.content_ids || [];
        var newItems = state.selectedItems.map(function (item) {
            return { id: item.id, title: item.title };
        });
        var merged = existing.concat(newItems);

        if (merged.length > 50) {
            alert('Cannot add items: lesson plan would exceed 50 item limit.');
            return;
        }

        apiPost('update', {
            plan_id: plan.id,
            content_ids: JSON.stringify(merged)
        }).then(function (res) {
            if (res.ok) {
                plan.content_ids = merged;
                plan.item_count = merged.length;
                state.selectedItems = [];
                renderDetailView();
            } else {
                alert(res.error || 'Failed to add items');
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────────────────
    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function truncate(s, max) {
        if (!s) return '';
        return s.length > max ? s.substring(0, max) + '\u2026' : s;
    }

    function formatDuration(secs) {
        secs = parseInt(secs, 10);
        if (isNaN(secs) || secs <= 0) return '';
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ASSIGN MODAL (FRE-50)
    // ─────────────────────────────────────────────────────────────────────────
    function openAssignModal(plan) {
        var overlay = el('div', { className: 'lb-modal-overlay assign-modal-overlay' });
        var modal = el('div', { className: 'lb-modal assign-modal' });

        modal.appendChild(el('h3', null, 'Assign: ' + truncate(plan.title, 40)));

        // Mode toggle
        var modeGroup = el('div', { className: 'assign-field' });
        modeGroup.appendChild(el('label', { className: 'assign-label' }, 'Mode'));
        var modeSelect = el('select', { className: 'assign-select', id: 'assign-mode' });
        modeSelect.appendChild(el('option', { value: 'individual' }, 'Individual (self-paced)'));
        modeSelect.appendChild(el('option', { value: 'guided' }, 'Guided (teacher-led)'));
        modeGroup.appendChild(modeSelect);
        modal.appendChild(modeGroup);

        // Assign-to segmented control
        var assignToGroup = el('div', { className: 'assign-field' });
        assignToGroup.appendChild(el('label', { className: 'assign-label' }, 'Assign to'));

        var segmentedRow = el('div', { className: 'assign-segmented' });
        var segments = [
            { value: 'all', label: 'All Students' },
            { value: 'groups', label: 'Groups' },
            { value: 'individuals', label: 'Individuals' }
        ];
        segments.forEach(function (seg) {
            var btn = el('button', {
                className: 'assign-seg-btn' + (seg.value === 'all' ? ' assign-seg-btn--active' : ''),
                'data-target': seg.value,
                type: 'button'
            }, seg.label);
            btn.addEventListener('click', function () {
                segmentedRow.querySelectorAll('.assign-seg-btn').forEach(function (b) {
                    b.classList.remove('assign-seg-btn--active');
                });
                btn.classList.add('assign-seg-btn--active');
                allPanel.style.display = seg.value === 'all' ? 'block' : 'none';
                groupsPanel.style.display = seg.value === 'groups' ? 'block' : 'none';
                studentList.style.display = seg.value === 'individuals' ? 'block' : 'none';
            });
            segmentedRow.appendChild(btn);
        });
        assignToGroup.appendChild(segmentedRow);

        // Panel: All Students
        var allPanel = el('div', { className: 'assign-target-panel', id: 'assign-panel-all' });
        allPanel.innerHTML = '<div class="assign-panel-msg">This lesson plan will be assigned to all students.</div>';
        assignToGroup.appendChild(allPanel);

        // Panel: Groups
        var groupsPanel = el('div', { className: 'assign-target-panel', id: 'assign-panel-groups' });
        groupsPanel.style.display = 'none';
        groupsPanel.innerHTML = '<div class="assign-loading">Loading groups...</div>';
        assignToGroup.appendChild(groupsPanel);

        // Panel: Individuals
        var studentList = el('div', { className: 'assign-student-list', id: 'assign-students' });
        studentList.style.display = 'none';
        studentList.innerHTML = '<div class="assign-loading">Loading students...</div>';
        assignToGroup.appendChild(studentList);

        modal.appendChild(assignToGroup);

        // Due date (individual only)
        var dueDateGroup = el('div', { className: 'assign-field', id: 'assign-due-group' });
        dueDateGroup.appendChild(el('label', { className: 'assign-label' }, 'Due date (optional)'));
        var dueDateInput = document.createElement('input');
        dueDateInput.type = 'date';
        dueDateInput.className = 'assign-input';
        dueDateInput.id = 'assign-due-date';
        dueDateGroup.appendChild(dueDateInput);
        modal.appendChild(dueDateGroup);

        modeSelect.addEventListener('change', function () {
            dueDateGroup.style.display = modeSelect.value === 'individual' ? 'block' : 'none';
        });

        // Notes
        var notesGroup = el('div', { className: 'assign-field' });
        notesGroup.appendChild(el('label', { className: 'assign-label' }, 'Notes (optional)'));
        var notesInput = document.createElement('textarea');
        notesInput.className = 'assign-input assign-textarea';
        notesInput.id = 'assign-notes';
        notesInput.rows = 3;
        notesInput.placeholder = 'Instructions for students...';
        notesGroup.appendChild(notesInput);
        modal.appendChild(notesGroup);

        // Actions
        var statusMsg = el('div', { className: 'assign-status', id: 'assign-status' });
        var actions = el('div', { className: 'lb-modal-actions' }, [
            el('button', {
                className: 'lb-btn lb-btn-secondary',
                onClick: function () { overlay.remove(); }
            }, 'Cancel'),
            el('button', {
                className: 'lb-btn lb-btn-accent',
                id: 'assign-submit-btn',
                onClick: function () { submitAssignment(plan, overlay); }
            }, 'Assign')
        ]);
        modal.appendChild(statusMsg);
        modal.appendChild(actions);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        // Load students and groups
        loadStudentsForAssign();
        loadGroupsForAssign();
    }

    function loadStudentsForAssign() {
        var listEl = document.getElementById('assign-students');
        if (!listEl) return;

        fetch('/api/teacher/students.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var students = data.students || [];
                if (students.length === 0) {
                    listEl.innerHTML = '<div class="assign-empty">No students assigned to you yet. Add students in the Students tab.</div>';
                    return;
                }
                listEl.innerHTML = '';
                students.forEach(function (s) {
                    var row = el('label', { className: 'assign-student-row' });
                    var cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = s.id;
                    cb.className = 'assign-student-cb';
                    row.appendChild(cb);
                    var avatar = el('span', { className: 'assign-student-avatar' });
                    avatar.style.backgroundColor = s.avatar_color || '#333';
                    avatar.textContent = (s.display_name || s.avatar_name || '?').charAt(0).toUpperCase();
                    row.appendChild(avatar);
                    row.appendChild(el('span', { className: 'assign-student-name' }, s.display_name || s.avatar_name || 'Unknown'));
                    listEl.appendChild(row);
                });
            })
            .catch(function () {
                listEl.innerHTML = '<div class="assign-empty">Failed to load students.</div>';
            });
    }

    function loadGroupsForAssign() {
        var panelEl = document.getElementById('assign-panel-groups');
        if (!panelEl) return;

        fetch('/api/teacher/groups.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var groups = data.groups || [];
                if (groups.length === 0) {
                    panelEl.innerHTML = '<div class="assign-empty">No groups created yet. Create groups in the Students tab.</div>';
                    return;
                }
                panelEl.innerHTML = '';
                groups.forEach(function (g) {
                    var row = el('label', { className: 'assign-group-row' });
                    var cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = g.id;
                    cb.className = 'assign-group-cb';
                    row.appendChild(cb);
                    var swatch = el('span', { className: 'assign-group-swatch' });
                    swatch.style.backgroundColor = g.color || '#4ECDC4';
                    row.appendChild(swatch);
                    row.appendChild(el('span', { className: 'assign-group-name' }, g.name));
                    row.appendChild(el('span', { className: 'assign-group-count' }, g.member_count + (g.member_count === 1 ? ' student' : ' students')));
                    panelEl.appendChild(row);
                });
            })
            .catch(function () {
                panelEl.innerHTML = '<div class="assign-empty">Failed to load groups.</div>';
            });
    }

    function submitAssignment(plan, overlay) {
        var btn = document.getElementById('assign-submit-btn');
        var statusEl = document.getElementById('assign-status');
        if (btn) { btn.disabled = true; btn.textContent = 'Assigning...'; }

        var mode = document.getElementById('assign-mode').value;
        var dueDate = document.getElementById('assign-due-date').value;
        var notes = document.getElementById('assign-notes').value;

        // Determine which assignment target is active
        var activeBtn = document.querySelector('.assign-seg-btn--active');
        var assignTarget = activeBtn ? activeBtn.getAttribute('data-target') : 'all';

        var studentIds = [];
        var groupIds = [];

        if (assignTarget === 'individuals') {
            var checkboxes = document.querySelectorAll('.assign-student-cb:checked');
            checkboxes.forEach(function (cb) { studentIds.push(cb.value); });
            if (studentIds.length === 0) {
                if (statusEl) statusEl.textContent = 'Select at least one student.';
                if (btn) { btn.disabled = false; btn.textContent = 'Assign'; }
                return;
            }
        } else if (assignTarget === 'groups') {
            var groupCheckboxes = document.querySelectorAll('.assign-group-cb:checked');
            groupCheckboxes.forEach(function (cb) { groupIds.push(cb.value); });
            if (groupIds.length === 0) {
                if (statusEl) statusEl.textContent = 'Select at least one group.';
                if (btn) { btn.disabled = false; btn.textContent = 'Assign'; }
                return;
            }
        }

        var body = new FormData();
        body.append('action', 'create');
        body.append('_csrf_token', state.csrfToken);
        body.append('lesson_plan_id', plan.id);
        body.append('mode', mode);
        body.append('due_date', dueDate);
        body.append('notes', notes);
        body.append('student_ids', JSON.stringify(studentIds));
        body.append('group_ids', JSON.stringify(groupIds));

        fetch('/api/lesson_assignments.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    if (statusEl) {
                        statusEl.textContent = 'Assigned successfully!';
                        statusEl.className = 'assign-status assign-status--success';
                    }
                    document.dispatchEvent(new CustomEvent('assignment-created'));
                    setTimeout(function () { overlay.remove(); }, 1000);
                } else {
                    if (statusEl) statusEl.textContent = data.error || 'Failed to assign';
                    if (btn) { btn.disabled = false; btn.textContent = 'Assign'; }
                }
            })
            .catch(function () {
                if (statusEl) statusEl.textContent = 'Network error. Try again.';
                if (btn) { btn.disabled = false; btn.textContent = 'Assign'; }
            });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────────────────────
    function init() {
        initCsrf();
        var container = getContainer();
        if (!container) return;

        // If the Playlists tab is visible on init, render list view
        if (container.offsetParent !== null || container.style.display !== 'none') {
            renderListView();
        }

        // Listen for tab switches — PR #11 may emit a custom event or use data attrs
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('[data-tab="playlists"]');
            if (tab) {
                setTimeout(renderListView, 50);
            }
        });
    }

    // Expose for external tab switching
    window.LessonBuilder = {
        init: init,
        renderListView: renderListView,
        openBuilder: openBuilder,
        openDetail: openDetail,
        openAssignModal: openAssignModal
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
