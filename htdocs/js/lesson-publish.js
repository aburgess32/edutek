/**
 * Lesson Plan Publish UI
 *
 * Provides publish-to-segment controls for the teacher playlist detail view.
 * - Fetches available segments from API
 * - Renders multi-select checkboxes
 * - Handles publish/unpublish via API calls
 * - Shows status badges on plan cards
 * - Optional icon + color picker
 *
 * @package EduPak
 * @version 1.0.0
 */

(function () {
    'use strict';

    var API_URL = 'api/lesson_plans_publish.php';

    // Color presets for plan tile appearance
    var COLOR_PRESETS = [
        '#4ECDC4', '#FF6B35', '#8B5CF6', '#E63946',
        '#14B8A6', '#F59E0B', '#3B82F6'
    ];

    // Common emoji icons for plan tiles
    var ICON_PRESETS = [
        '📚', '🎓', '🔬', '⚡', '🌍', '🧮', '📐',
        '🎨', '🎵', '🏥', '💻', '🌱', '📖', '⭐'
    ];

    // ---------------------------------------------------------------
    // Segment fetching
    // ---------------------------------------------------------------

    /**
     * Fetch available segments from API.
     * @param {Function} callback - Called with array of {key, label, icon}
     */
    function fetchSegments(callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', API_URL + '?action=segments', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        callback(null, JSON.parse(xhr.responseText));
                    } catch (e) {
                        callback(e, []);
                    }
                } else {
                    callback(new Error('Failed to fetch segments'), []);
                }
            }
        };
        xhr.send();
    }

    // ---------------------------------------------------------------
    // Publish API call
    // ---------------------------------------------------------------

    /**
     * Publish/unpublish a plan to segments.
     * @param {number} planId
     * @param {string[]} segmentKeys
     * @param {Object} opts - Optional: icon, color, description
     * @param {Function} callback - Called with (err, result)
     */
    function publishPlan(planId, segmentKeys, opts, callback) {
        var formData = new FormData();
        formData.append('action', 'publish');
        formData.append('plan_id', String(planId));
        formData.append('segments', JSON.stringify(segmentKeys));

        if (opts.icon) formData.append('icon', opts.icon);
        if (opts.color) formData.append('color', opts.color);
        if (opts.description !== undefined) formData.append('description', opts.description);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL + '?action=publish', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        callback(null, res);
                    } catch (e) {
                        callback(e);
                    }
                } else {
                    try {
                        var err = JSON.parse(xhr.responseText);
                        callback(new Error(err.error || 'Publish failed'));
                    } catch (e2) {
                        callback(new Error('Publish failed'));
                    }
                }
            }
        };
        xhr.send(formData);
    }

    // ---------------------------------------------------------------
    // UI Rendering
    // ---------------------------------------------------------------

    /**
     * Render the publish panel inside a container element.
     *
     * @param {HTMLElement} container - DOM element to render into
     * @param {Object} plan - Plan data: {id, title, published_segments, icon, color, description}
     * @param {Function} onUpdate - Called after publish/unpublish completes
     */
    function renderPublishPanel(container, plan, onUpdate) {
        container.innerHTML = '<p class="publish-loading">Loading segments…</p>';

        fetchSegments(function (err, segments) {
            if (err || !segments.length) {
                container.innerHTML = '<p class="publish-error">Could not load segments.</p>';
                return;
            }

            var currentSegments = [];
            try {
                currentSegments = JSON.parse(plan.published_segments || '[]') || [];
            } catch (e) {
                currentSegments = [];
            }
            if (!Array.isArray(currentSegments)) currentSegments = [];

            var currentIcon = plan.icon || '📚';
            var currentColor = plan.color || '#4ECDC4';
            var currentDesc = plan.description || '';

            var html = '';

            // Status badge
            html += '<div class="publish-status">';
            if (currentSegments.length > 0) {
                var segNames = currentSegments.map(function (key) {
                    for (var i = 0; i < segments.length; i++) {
                        if (segments[i].key === key) return segments[i].label;
                    }
                    return key;
                });
                html += '<span class="publish-badge publish-badge--published">📡 Published to ' +
                    segNames.join(', ') + '</span>';
            } else {
                html += '<span class="publish-badge publish-badge--private">🔒 Private</span>';
            }
            html += '</div>';

            // Segment checkboxes
            html += '<div class="publish-section">';
            html += '<h3 class="publish-section__title">Publish to Segments</h3>';
            html += '<div class="publish-checkboxes">';
            for (var i = 0; i < segments.length; i++) {
                var seg = segments[i];
                var checked = currentSegments.indexOf(seg.key) !== -1 ? ' checked' : '';
                html += '<label class="publish-checkbox">' +
                    '<input type="checkbox" name="seg" value="' + seg.key + '"' + checked + '>' +
                    '<span class="publish-checkbox__label">' + seg.icon + ' ' + seg.label + '</span>' +
                    '</label>';
            }
            html += '</div>';
            html += '</div>';

            // Description
            html += '<div class="publish-section">';
            html += '<h3 class="publish-section__title">Description</h3>';
            html += '<textarea class="publish-desc" maxlength="500" placeholder="Add a description for students…">' +
                escapeHtml(currentDesc) + '</textarea>';
            html += '</div>';

            // Icon picker
            html += '<div class="publish-section">';
            html += '<h3 class="publish-section__title">Icon</h3>';
            html += '<div class="publish-icons">';
            for (var j = 0; j < ICON_PRESETS.length; j++) {
                var iconCls = ICON_PRESETS[j] === currentIcon ? ' publish-icon--active' : '';
                html += '<button class="publish-icon' + iconCls + '" data-icon="' + ICON_PRESETS[j] + '">' +
                    ICON_PRESETS[j] + '</button>';
            }
            html += '</div>';
            html += '</div>';

            // Color picker
            html += '<div class="publish-section">';
            html += '<h3 class="publish-section__title">Color</h3>';
            html += '<div class="publish-colors">';
            for (var k = 0; k < COLOR_PRESETS.length; k++) {
                var colorCls = COLOR_PRESETS[k] === currentColor ? ' publish-color--active' : '';
                html += '<button class="publish-color' + colorCls + '" ' +
                    'data-color="' + COLOR_PRESETS[k] + '" ' +
                    'style="background:' + COLOR_PRESETS[k] + '"></button>';
            }
            html += '</div>';
            html += '</div>';

            container.innerHTML = html;

            // ---- Event Handlers ----

            var selectedIcon = currentIcon;
            var selectedColor = currentColor;

            // Checkbox change → publish immediately
            var checkboxes = container.querySelectorAll('input[name="seg"]');
            for (var c = 0; c < checkboxes.length; c++) {
                checkboxes[c].addEventListener('change', doPublish);
            }

            // Description blur → publish
            var descEl = container.querySelector('.publish-desc');
            if (descEl) {
                descEl.addEventListener('blur', doPublish);
            }

            // Icon click
            var iconBtns = container.querySelectorAll('.publish-icon');
            for (var ic = 0; ic < iconBtns.length; ic++) {
                iconBtns[ic].addEventListener('click', function () {
                    for (var x = 0; x < iconBtns.length; x++) {
                        iconBtns[x].classList.remove('publish-icon--active');
                    }
                    this.classList.add('publish-icon--active');
                    selectedIcon = this.getAttribute('data-icon');
                    doPublish();
                });
            }

            // Color click
            var colorBtns = container.querySelectorAll('.publish-color');
            for (var cl = 0; cl < colorBtns.length; cl++) {
                colorBtns[cl].addEventListener('click', function () {
                    for (var x = 0; x < colorBtns.length; x++) {
                        colorBtns[x].classList.remove('publish-color--active');
                    }
                    this.classList.add('publish-color--active');
                    selectedColor = this.getAttribute('data-color');
                    doPublish();
                });
            }

            function doPublish() {
                var selected = [];
                var boxes = container.querySelectorAll('input[name="seg"]:checked');
                for (var b = 0; b < boxes.length; b++) {
                    selected.push(boxes[b].value);
                }

                var desc = container.querySelector('.publish-desc');
                var descVal = desc ? desc.value : '';

                publishPlan(plan.id, selected, {
                    icon: selectedIcon,
                    color: selectedColor,
                    description: descVal
                }, function (err) {
                    if (err) {
                        console.error('Publish error:', err.message);
                        return;
                    }

                    // Update badge
                    var badgeEl = container.querySelector('.publish-status');
                    if (badgeEl) {
                        if (selected.length > 0) {
                            var names = selected.map(function (key) {
                                for (var s = 0; s < segments.length; s++) {
                                    if (segments[s].key === key) return segments[s].label;
                                }
                                return key;
                            });
                            badgeEl.innerHTML = '<span class="publish-badge publish-badge--published">📡 Published to ' +
                                names.join(', ') + '</span>';
                        } else {
                            badgeEl.innerHTML = '<span class="publish-badge publish-badge--private">🔒 Private</span>';
                        }
                    }

                    if (typeof onUpdate === 'function') {
                        onUpdate({
                            segments: selected,
                            icon: selectedIcon,
                            color: selectedColor,
                            description: descVal
                        });
                    }
                });
            }
        });
    }

    // ---------------------------------------------------------------
    // Status Badge Helper (for plan card lists)
    // ---------------------------------------------------------------

    /**
     * Create a status badge element for a plan.
     * @param {Object} plan - Plan with published_segments, teacher_name
     * @returns {HTMLElement}
     */
    function createStatusBadge(plan) {
        var el = document.createElement('span');
        var segs = [];
        try {
            segs = JSON.parse(plan.published_segments || '[]') || [];
        } catch (e) {
            segs = [];
        }

        if (segs.length > 0) {
            el.className = 'publish-badge publish-badge--published';
            el.textContent = '📡 Published';
        } else {
            el.className = 'publish-badge publish-badge--private';
            el.textContent = '🔒 Private';
        }

        return el;
    }

    // ---------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ---------------------------------------------------------------
    // Export to global scope
    // ---------------------------------------------------------------

    window.EduPakPublish = {
        fetchSegments: fetchSegments,
        publishPlan: publishPlan,
        renderPublishPanel: renderPublishPanel,
        createStatusBadge: createStatusBadge,
        COLOR_PRESETS: COLOR_PRESETS,
        ICON_PRESETS: ICON_PRESETS
    };

})();
