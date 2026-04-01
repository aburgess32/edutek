/**
 * Teacher Profile Tab (FRE-13 Phase 6)
 *
 * Provides UI for:
 *  - Display name editing
 *  - Email display (read-only)
 *  - Change own password
 *  - Reset another teacher's password
 *  - Logout
 *
 * APIs:
 *  - POST /api/teacher/profile.php  (action: update_name, change_password)
 *  - POST /api/teacher/reset-password.php
 */
window.TeacherProfile = (function() {
    'use strict';

    var root = null;
    var csrfToken = '';

    function getCsrf() {
        if (csrfToken) return csrfToken;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) csrfToken = meta.getAttribute('content');
        var input = document.querySelector('input[name="_csrf_token"]');
        if (!csrfToken && input) csrfToken = input.value;
        return csrfToken;
    }

    function postApi(url, data) {
        var body = new FormData();
        body.append('_csrf_token', getCsrf());
        Object.keys(data).forEach(function(k) { body.append(k, data[k]); });

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(res) { return res.json(); });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function(k) {
                if (k === 'className') node.className = attrs[k];
                else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
                else node.setAttribute(k, attrs[k]);
            });
        }
        if (children) {
            (Array.isArray(children) ? children : [children]).forEach(function(c) {
                if (typeof c === 'string') node.appendChild(document.createTextNode(c));
                else if (c) node.appendChild(c);
            });
        }
        return node;
    }

    function showFeedback(container, message, isError) {
        var existing = container.querySelector('.profile-feedback');
        if (existing) existing.remove();

        var fb = el('div', { className: 'profile-feedback ' + (isError ? 'profile-feedback--error' : 'profile-feedback--success') }, message);
        container.insertBefore(fb, container.firstChild);

        setTimeout(function() { if (fb.parentNode) fb.remove(); }, 5000);
    }

    function renderNameSection() {
        var body = document.body;
        var currentName = body.getAttribute('data-teacher-name') || 'Teacher';

        var section = el('div', { className: 'profile-section' });
        var heading = el('h3', { className: 'profile-section__title' }, 'Display Name');

        var input = el('input', {
            type: 'text',
            className: 'profile-input',
            value: currentName,
            maxlength: '100',
            placeholder: 'Your display name'
        });
        input.value = currentName;

        var saveBtn = el('button', {
            className: 'profile-btn profile-btn--primary',
            onClick: function() {
                var newName = input.value.trim();
                if (newName.length < 2) {
                    showFeedback(section, 'Name must be at least 2 characters.', true);
                    return;
                }
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
                postApi('/api/teacher/profile.php', { action: 'update_name', display_name: newName })
                    .then(function(res) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save Name';
                        if (res.error) {
                            showFeedback(section, res.error, true);
                        } else {
                            showFeedback(section, 'Name updated successfully.');
                            body.setAttribute('data-teacher-name', res.display_name || newName);
                            // Update header
                            var headerName = document.querySelector('.teacher-header__name');
                            if (headerName) headerName.textContent = res.display_name || newName;
                            var headerAvatar = document.querySelector('.teacher-header__avatar');
                            if (headerAvatar) headerAvatar.textContent = (res.display_name || newName).charAt(0).toUpperCase();
                        }
                    })
                    .catch(function() {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save Name';
                        showFeedback(section, 'Failed to save. Please try again.', true);
                    });
            }
        }, 'Save Name');

        var row = el('div', { className: 'profile-field-row' }, [input, saveBtn]);
        section.appendChild(heading);
        section.appendChild(row);
        return section;
    }

    function renderEmailSection() {
        var section = el('div', { className: 'profile-section' });
        var heading = el('h3', { className: 'profile-section__title' }, 'Email Address');

        var email = document.body.getAttribute('data-teacher-email') || '';
        var emailDisplay = el('div', { className: 'profile-email-display' }, email || 'Not available');
        var hint = el('div', { className: 'profile-hint' }, 'Email cannot be changed at this time.');

        section.appendChild(heading);
        section.appendChild(emailDisplay);
        section.appendChild(hint);
        return section;
    }

    function renderChangePasswordSection() {
        var section = el('div', { className: 'profile-section' });
        var heading = el('h3', { className: 'profile-section__title' }, 'Change Password');

        var currentPw = el('input', { type: 'password', className: 'profile-input', placeholder: 'Current password', autocomplete: 'current-password' });
        var newPw = el('input', { type: 'password', className: 'profile-input', placeholder: 'New password (min 6 characters)', autocomplete: 'new-password', minlength: '6' });
        var confirmPw = el('input', { type: 'password', className: 'profile-input', placeholder: 'Confirm new password', autocomplete: 'new-password' });

        var saveBtn = el('button', {
            className: 'profile-btn profile-btn--primary',
            onClick: function() {
                if (!currentPw.value) {
                    showFeedback(section, 'Please enter your current password.', true);
                    return;
                }
                if (newPw.value.length < 6) {
                    showFeedback(section, 'New password must be at least 6 characters.', true);
                    return;
                }
                if (newPw.value !== confirmPw.value) {
                    showFeedback(section, 'New passwords do not match.', true);
                    return;
                }
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
                postApi('/api/teacher/profile.php', {
                    action: 'change_password',
                    current_password: currentPw.value,
                    new_password: newPw.value,
                    confirm_password: confirmPw.value
                }).then(function(res) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Change Password';
                    if (res.error) {
                        showFeedback(section, res.error, true);
                    } else {
                        showFeedback(section, 'Password changed successfully.');
                        currentPw.value = '';
                        newPw.value = '';
                        confirmPw.value = '';
                    }
                }).catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Change Password';
                    showFeedback(section, 'Failed to change password. Please try again.', true);
                });
            }
        }, 'Change Password');

        section.appendChild(heading);
        section.appendChild(el('div', { className: 'profile-field-stack' }, [currentPw, newPw, confirmPw, saveBtn]));
        return section;
    }

    function renderResetSection() {
        var section = el('div', { className: 'profile-section' });
        var heading = el('h3', { className: 'profile-section__title' }, 'Reset Another Teacher\'s Password');
        var hint = el('div', { className: 'profile-hint' }, 'Use this to help a colleague who forgot their password.');

        var emailInput = el('input', { type: 'email', className: 'profile-input', placeholder: 'Teacher\'s email address', autocomplete: 'off' });
        var newPw = el('input', { type: 'password', className: 'profile-input', placeholder: 'New password (min 6 characters)', autocomplete: 'new-password', minlength: '6' });

        var resetBtn = el('button', {
            className: 'profile-btn profile-btn--danger',
            onClick: function() {
                if (!emailInput.value || emailInput.value.indexOf('@') === -1) {
                    showFeedback(section, 'Please enter a valid email address.', true);
                    return;
                }
                if (newPw.value.length < 6) {
                    showFeedback(section, 'Password must be at least 6 characters.', true);
                    return;
                }
                resetBtn.disabled = true;
                resetBtn.textContent = 'Resetting...';
                postApi('/api/teacher/reset-password.php', {
                    target_email: emailInput.value,
                    new_password: newPw.value
                }).then(function(res) {
                    resetBtn.disabled = false;
                    resetBtn.textContent = 'Reset Password';
                    if (res.error) {
                        showFeedback(section, res.error, true);
                    } else {
                        showFeedback(section, 'Password has been reset successfully.');
                        emailInput.value = '';
                        newPw.value = '';
                    }
                }).catch(function() {
                    resetBtn.disabled = false;
                    resetBtn.textContent = 'Reset Password';
                    showFeedback(section, 'Failed to reset password. Please try again.', true);
                });
            }
        }, 'Reset Password');

        section.appendChild(heading);
        section.appendChild(hint);
        section.appendChild(el('div', { className: 'profile-field-stack' }, [emailInput, newPw, resetBtn]));
        return section;
    }

    function renderLogoutSection() {
        var section = el('div', { className: 'profile-section profile-section--logout' });
        var btn = el('a', { href: '/logout.php', className: 'profile-btn profile-btn--logout' }, 'Sign Out');
        section.appendChild(btn);
        return section;
    }

    function init(container) {
        root = container;
        root.innerHTML = '';

        var wrapper = el('div', { className: 'profile-wrapper' });

        var title = el('h2', { className: 'profile-page-title' }, 'Your Profile');
        wrapper.appendChild(title);
        wrapper.appendChild(renderNameSection());
        wrapper.appendChild(renderEmailSection());
        wrapper.appendChild(renderChangePasswordSection());
        wrapper.appendChild(renderResetSection());
        wrapper.appendChild(renderLogoutSection());

        root.appendChild(wrapper);
    }

    return { init: init };
})();
