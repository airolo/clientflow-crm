/**
 * ClientFlow CRM - front-end behaviour (vanilla JS only).
 */
(function () {
    'use strict';

    /**
     * Confirmation dialogs.
     * Any element with data-confirm shows a Bootstrap modal before its action:
     *   <button data-confirm="Delete this client?">Delete</button>
     * Works for buttons (form submit) and links (href is used as the target).
     */
    var modalEl = document.getElementById('confirmModal');
    var pendingAction = null;

    function showConfirm(message, action) {
        if (!modalEl || !window.bootstrap) {
            if (window.confirm(message)) {
                perform(action);
            }
            return;
        }
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('confirmModalMessage').textContent = message;
        pendingAction = action;
        modal.show();
    }

    function perform(action) {
        if (!action) return;
        if (action.type === 'submit' && action.form) {
            action.form.submit();
        } else if (action.type === 'href' && action.href) {
            window.location.href = action.href;
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-confirm]');
        if (!trigger) return;

        event.preventDefault();
        var message = trigger.getAttribute('data-confirm') || 'Are you sure?';

        if (trigger.tagName === 'A') {
            showConfirm(message, { type: 'href', href: trigger.getAttribute('href') });
        } else if (trigger.tagName === 'BUTTON') {
            var form = trigger.closest('form');
            showConfirm(message, form ? { type: 'submit', form: form } : null);
        }
    });

    var acceptBtn = document.getElementById('confirmModalAccept');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function () {
            var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.hide();
            var action = pendingAction;
            pendingAction = null;
            setTimeout(function () { perform(action); }, 180);
        });
    }

    // Clear the pending action when the modal is dismissed (Cancel / backdrop / Esc).
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () { pendingAction = null; });
    }

    /**
     * Auto-dismiss success alerts after a few seconds.
     */
    document.querySelectorAll('.alert-success[role="alert"]').forEach(function (alert) {
        setTimeout(function () {
            var instance = window.bootstrap && window.bootstrap.Alert.getOrCreateInstance(alert);
            instance ? instance.close() : alert.remove();
        }, 6000);
    });

    /**
     * Reopen a Bootstrap modal that the page asked to be shown again.
     * forms.php sets data-reopen-modal on <body> when a submitted form inside a
     * modal failed validation - without this the error message is invisible
     * because the dialog is closed.
     */
    var reopen = document.body.getAttribute('data-reopen-modal');
    if (reopen && window.bootstrap) {
        var target = document.getElementById(reopen);
        if (target) {
            window.bootstrap.Modal.getOrCreateInstance(target).show();
        }
    }

    /**
     * Character counter for textarea fields with data-counter.
     */
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var target = document.getElementById(field.getAttribute('data-counter'));
        if (!target) return;

        function update() {
            var length = field.value.length;
            target.textContent = length + ' character' + (length === 1 ? '' : 's');
        }
        field.addEventListener('input', update);
        update();
    });

    /**
     * Optional client-side required check so empty forms do not round-trip.
     * Server-side validation remains the real guard.
     */
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                form.classList.add('was-validated');
            }
        });
    });

    /**
     * Show/hide password toggle, used on the sign-in and change-password screens.
     * Lives here rather than in an inline <script> so the Content-Security-Policy
     * can keep script-src 'self' with no inline exceptions.
     */
    var togglePassword = document.getElementById('togglePassword');
    if (togglePassword) {
        togglePassword.addEventListener('click', function () {
            var input = document.getElementById('password');
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            this.innerHTML = '<i class="bi bi-eye' + (showing ? '' : '-slash') + '"></i>';
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });
    }

    /**
     * Filter chips on the task board: click a status to filter instantly.
     */
    var taskFilterSelect = document.getElementById('taskQuickFilter');
    if (taskFilterSelect) {
        taskFilterSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            if (this.value) {
                url.searchParams.set('status', this.value);
            } else {
                url.searchParams.delete('status');
            }
            url.searchParams.delete('page');
            window.location.href = url.toString();
        });
    }
})();