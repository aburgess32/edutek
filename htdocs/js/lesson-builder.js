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
        expandedPreview: null,  // id of expanded preview card
        csrfToken: '',
        dragState: null,        // drag-reorder state
        debounceTimer: null
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

    function searchApi(term) {
        return fetch('/api/search.php?q=' + encodeURIComponent(term))
            .then(function (r) { return r.json(); })
            .catch(function () { return []; });
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
                el('h2', { className: 'lb-title' }, 'Playlists'),
                el('button', {
                    className: 'lb-btn lb-btn-primary',
                    onClick: function () { openBuilder('new'); }
                }, '+ New Playlist')
            ]);
            container.appendChild(header);

            if (!plans || plans.length === 0) {
                container.appendChild(el('div', { className: 'lb-empty-state' }, [
                    el('div', { className: 'lb-empty-icon', innerHTML: '&#128218;' }),
                    el('h3', null, 'No Playlists Yet'),
                    el('p', null, 'Create your first playlist to organize content for your students.'),
                    el('button', {
                        className: 'lb-btn lb-btn-primary lb-btn-lg',
                        onClick: function () { openBuilder('new'); }
                    }, 'Create Your First Playlist')
                ]));
                return;
            }

            var grid = el('div', { className: 'lb-plan-grid' });
            plans.forEach(function (plan) {
                var publishBadge = null;
                if (typeof window.EduPakPublish !== 'undefined') {
                    publishBadge = window.EduPakPublish.createStatusBadge(plan);
                }
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
                        el('div', { className: 'lb-plan-card-date' }, formatDate(plan.created_at))
                    ])
                ]);
                grid.appendChild(card);
            });
            container.appendChild(grid);
        }).catch(function () {
            container.innerHTML = '<div class="lb-error">Failed to load playlists. Please try again.</div>';
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
            container.innerHTML = '<div class="lb-error">Failed to load playlist details.</div>';
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
        }, '\u2190 Back to Playlists'));

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
                className: 'lb-btn lb-btn-danger',
                onClick: function () { confirmDeletePlan(plan.id); }
            }, 'Delete Playlist')
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
                el('p', null, 'This playlist is empty.'),
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
            el('h3', null, 'Delete Playlist?'),
            el('p', null, 'This will permanently delete this playlist. This action cannot be undone.'),
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
            state.builderMode === 'new' ? 'Create New Playlist' : 'Add Content to Playlist'));

        // Search input
        var searchWrap = el('div', { className: 'lb-search-wrap' });
        var searchInput = el('input', {
            className: 'lb-search-input',
            type: 'search',
            placeholder: 'Search videos, topics, keywords\u2026',
            autocomplete: 'off'
        });
        searchInput.addEventListener('input', function () {
            var term = searchInput.value.trim();
            state.searchTerm = term;
            clearTimeout(state.debounceTimer);
            if (term.length < 2) {
                state.searchResults = [];
                renderSearchResults();
                return;
            }
            state.debounceTimer = setTimeout(function () {
                performSearch(term);
            }, 300);
        });
        searchWrap.appendChild(searchInput);
        container.appendChild(searchWrap);

        // Results container
        container.appendChild(el('div', { id: 'lb-search-results', className: 'lb-search-results' }));

        // Selection bar (sticky bottom)
        container.appendChild(renderSelectionBar());
    }

    function performSearch(term) {
        var resultsEl = document.getElementById('lb-search-results');
        if (resultsEl) resultsEl.innerHTML = '<div class="lb-loading"><div class="lb-spinner"></div></div>';

        searchApi(term).then(function (results) {
            state.searchResults = Array.isArray(results) ? results : [];
            renderSearchResults();
        });
    }

    function renderSearchResults() {
        var resultsEl = document.getElementById('lb-search-results');
        if (!resultsEl) return;
        resultsEl.innerHTML = '';

        if (state.searchTerm.length < 2) {
            resultsEl.innerHTML = '<div class="lb-search-hint">Type at least 2 characters to search</div>';
            return;
        }

        if (state.searchResults.length === 0) {
            resultsEl.innerHTML = '<div class="lb-search-empty">' +
                '<div class="lb-search-empty__icon">&#128270;</div>' +
                '<div class="lb-search-empty__text">No content found. Try different keywords.</div>' +
                '</div>';
            return;
        }

        // Figure out which items are already in the current plan
        var existingIds = {};
        if (state.currentPlan && state.currentPlan.content_ids) {
            state.currentPlan.content_ids.forEach(function (item) {
                var id = (typeof item === 'object') ? item.id : item;
                existingIds[id] = true;
            });
        }

        state.searchResults.forEach(function (result) {
            var contentId = result.content_id || result.id || '';
            var isInPlan = existingIds[contentId];
            var isSelected = state.selectedItems.some(function (s) {
                return (s.id || s.content_id) === contentId;
            });

            var card = el('div', {
                className: 'lb-result-card' + (isSelected ? ' lb-selected' : '') + (isInPlan ? ' lb-in-plan' : ''),
                'data-id': contentId
            });

            // Checkbox
            var checkbox = el('div', {
                className: 'lb-result-checkbox' + (isSelected ? ' lb-checked' : ''),
                onClick: function (e) {
                    e.stopPropagation();
                    toggleSelectItem(result);
                }
            }, isSelected ? '\u2713' : '');
            card.appendChild(checkbox);

            // Thumbnail
            var thumb = el('div', { className: 'lb-result-thumb' });
            if (result.thumbnail_path) {
                thumb.appendChild(el('img', {
                    src: result.thumbnail_path,
                    alt: '',
                    loading: 'lazy'
                }));
            }
            card.appendChild(thumb);

            // Info
            var info = el('div', { className: 'lb-result-info' }, [
                el('div', { className: 'lb-result-title' }, result.title || contentId),
                el('div', { className: 'lb-result-badges' }, [
                    result.category ? el('span', { className: 'lb-badge lb-badge-category' }, result.category) : null,
                    result.source ? el('span', { className: 'lb-badge lb-badge-source' }, result.source) : null,
                    result.content_type ? el('span', { className: 'lb-badge lb-badge-type' }, result.content_type) : null
                ])
            ]);
            card.appendChild(info);

            if (isInPlan) {
                card.appendChild(el('span', { className: 'lb-in-plan-label' }, 'Already in playlist'));
            }

            // Click card (not checkbox) → expand preview
            card.addEventListener('click', function () {
                togglePreview(contentId, result, card);
            });

            resultsEl.appendChild(card);

            // If this is the expanded preview, render it
            if (state.expandedPreview === contentId) {
                resultsEl.appendChild(renderPreviewPanel(result));
            }
        });
    }

    function togglePreview(contentId, result, card) {
        if (state.expandedPreview === contentId) {
            state.expandedPreview = null;
        } else {
            state.expandedPreview = contentId;
        }
        renderSearchResults();
    }

    function renderPreviewPanel(result) {
        var contentId = result.content_id || result.id || '';
        var isSelected = state.selectedItems.some(function (s) {
            return (s.id || s.content_id) === contentId;
        });

        var panel = el('div', { className: 'lb-preview-panel' }, [
            el('div', { className: 'lb-preview-thumb' }, result.thumbnail_path
                ? [el('img', { src: result.thumbnail_path, alt: result.title || '' })]
                : [el('div', { className: 'lb-preview-placeholder' })]
            ),
            el('div', { className: 'lb-preview-info' }, [
                el('h3', null, result.title || contentId),
                el('div', { className: 'lb-preview-meta' }, [
                    result.category ? el('div', null, 'Category: ' + result.category) : null,
                    result.subcategory ? el('div', null, 'Subcategory: ' + result.subcategory) : null,
                    result.source ? el('div', null, 'Source: ' + result.source) : null,
                    result.content_type ? el('div', null, 'Type: ' + result.content_type) : null,
                    result.duration_seconds ? el('div', null, 'Duration: ' + formatDuration(result.duration_seconds)) : null
                ]),
                el('div', { className: 'lb-preview-actions' }, [
                    el('button', {
                        className: 'lb-btn ' + (isSelected ? 'lb-btn-secondary' : 'lb-btn-primary'),
                        onClick: function () {
                            toggleSelectItem(result);
                            state.expandedPreview = null;
                            renderSearchResults();
                        }
                    }, isSelected ? 'Deselect' : 'Select'),
                    el('button', {
                        className: 'lb-btn lb-btn-text',
                        onClick: function () {
                            state.expandedPreview = null;
                            renderSearchResults();
                        }
                    }, 'Back to Results')
                ])
            ])
        ]);
        return panel;
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
                alert('Maximum 50 items per playlist.');
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

        var btnLabel = state.builderMode === 'new' ? 'Save as Playlist' : 'Add to Playlist';
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
            placeholder: 'Playlist name\u2026',
            maxlength: '255',
            autofocus: 'true'
        });
        var errorEl = el('div', { className: 'lb-modal-error' });

        var modal = el('div', { className: 'lb-modal' }, [
            el('h3', null, 'Name Your Playlist'),
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
                            errorEl.textContent = 'Please enter a name for the playlist.';
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
                alert(res.error || 'Failed to create playlist');
            }
        }).catch(function () {
            overlay.remove();
            alert('Failed to create playlist. Please try again.');
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
            alert('Cannot add items: playlist would exceed 50 item limit.');
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
        openDetail: openDetail
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
