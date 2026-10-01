/* =====================================================================
   AI Interview Copilot — shared front-end helpers (no dependencies)
   ===================================================================== */
(function () {
    'use strict';

    var meta = function (name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : '';
    };

    var App = {
        base: meta('app-base') || '',
        csrf: meta('csrf-token') || ''
    };

    App.url = function (path) {
        return App.base + '/' + String(path).replace(/^\//, '');
    };

    /** Error thrown for failed API calls. */
    function ApiError(message, status, kind, data) {
        this.name = 'ApiError';
        this.message = message;
        this.status = status || 0;
        this.kind = kind || '';
        this.data = data || null;
    }
    ApiError.prototype = Object.create(Error.prototype);
    App.ApiError = ApiError;

    /**
     * Call an API endpoint. Returns `data` from {success:true,data}; throws ApiError otherwise.
     * opts: {method, json, form (FormData), timeout (ms), signal}
     */
    App.api = function (path, opts) {
        opts = opts || {};
        var headers = { 'X-CSRF-Token': App.csrf, 'Accept': 'application/json' };
        var body;
        if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(opts.json);
        } else if (opts.form) {
            body = opts.form;
        }
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = null;
        if (controller && opts.timeout) {
            timer = setTimeout(function () { controller.abort(); }, opts.timeout);
        }
        if (controller && opts.signal) {
            opts.signal.addEventListener('abort', function () { controller.abort(); });
        }

        return fetch(App.url(path), {
            method: opts.method || (body ? 'POST' : 'GET'),
            headers: headers,
            body: body,
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (res) {
            if (timer) { clearTimeout(timer); }
            return res.text().then(function (text) {
                var payload = null;
                try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = null; }
                if (!payload || typeof payload !== 'object') {
                    throw new ApiError(res.status >= 500 ? 'The server encountered an error. Please try again.'
                        : 'Unexpected response from the server.', res.status, 'invalid_response');
                }
                if (!res.ok || payload.success !== true) {
                    if (res.status === 401) {
                        App.toast('Your session has ended. Redirecting to sign in…', 'error');
                        setTimeout(function () { window.location.href = App.url('login.php'); }, 1500);
                    }
                    throw new ApiError(payload.message || 'Request failed.', res.status, payload.error_kind || '', payload);
                }
                return payload.data || {};
            });
        }, function (err) {
            if (timer) { clearTimeout(timer); }
            if (err && err.name === 'AbortError') {
                throw new ApiError(opts.signal && opts.signal.aborted ? 'Cancelled.' : 'The request timed out. Please check your connection and try again.', 0, opts.signal && opts.signal.aborted ? 'aborted' : 'timeout');
            }
            throw new ApiError(navigator.onLine === false ? 'You appear to be offline. Check your connection and try again.'
                : 'Network error. Please check your connection and try again.', 0, 'network');
        });
    };

    /** Small toast notifications. type: info|success|error */
    App.toast = function (message, type) {
        var region = document.getElementById('toast-region');
        if (!region) {
            region = document.createElement('div');
            region.id = 'toast-region';
            region.className = 'toast-region';
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }
        var t = document.createElement('div');
        t.className = 'toast' + (type ? ' toast-' + type : '');
        t.setAttribute('role', type === 'error' ? 'alert' : 'status');
        t.textContent = message;
        region.appendChild(t);
        setTimeout(function () { t.remove(); }, type === 'error' ? 6000 : 3500);
    };

    App.escape = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    App.el = function (tag, attrs, children) {
        var el = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'text') { el.textContent = attrs[k]; }
                else if (k === 'class') { el.className = attrs[k]; }
                else { el.setAttribute(k, attrs[k]); }
            });
        }
        (children || []).forEach(function (c) {
            if (c == null) { return; }
            el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return el;
    };

    App.readJson = function (id) {
        var el = document.getElementById(id);
        if (!el) { return {}; }
        try { return JSON.parse(el.textContent || '{}'); } catch (e) { return {}; }
    };

    App.storage = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
    };

    /** Set a button into a busy state and return a restore function. */
    App.busy = function (btn, label) {
        if (!btn) { return function () {}; }
        var html = btn.innerHTML;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.innerHTML = '<span class="spinner spinner-light" aria-hidden="true" style="width:16px;height:16px;border-width:2px"></span> ' + App.escape(label || 'Working…');
        return function () { btn.disabled = false; btn.removeAttribute('aria-busy'); btn.innerHTML = html; };
    };

    /** Modal helper with focus trap + Escape. */
    App.modal = function (modal, onClose) {
        var lastFocus = document.activeElement;
        modal.hidden = false;
        var focusables = function () {
            return Array.prototype.slice.call(modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
                .filter(function (el) { return !el.disabled && el.offsetParent !== null; });
        };
        var first = focusables()[0];
        if (first) { first.focus(); }
        function onKey(e) {
            if (e.key === 'Escape') { close(); }
            if (e.key === 'Tab') {
                var f = focusables();
                if (!f.length) { return; }
                if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
            }
        }
        function close() {
            modal.hidden = true;
            document.removeEventListener('keydown', onKey);
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
            if (onClose) { onClose(); }
        }
        document.addEventListener('keydown', onKey);
        return close;
    };

    /**
     * Microphone consent flow shared by interview + practice pages.
     * Resolves true when the user consents (remembered server-side and locally).
     */
    App.ensureMicConsent = function (alreadyConsented) {
        if (alreadyConsented || App.storage.get('icp_mic_consent') === '1') {
            return Promise.resolve(true);
        }
        var modal = document.getElementById('consent-modal');
        if (!modal) { return Promise.resolve(true); }
        return new Promise(function (resolve) {
            var check = modal.querySelector('#consent-check');
            var ok = modal.querySelector('#consent-ok');
            var cancel = modal.querySelector('#consent-cancel');
            var settled = false;
            check.checked = false;
            ok.disabled = true;
            var close = App.modal(modal, function () { if (!settled) { settled = true; resolve(false); } });
            check.onchange = function () { ok.disabled = !check.checked; };
            cancel.onclick = function () { close(); };
            ok.onclick = function () {
                settled = true;
                App.storage.set('icp_mic_consent', '1');
                App.api('api/preferences.php', { json: { mic_consent: true } }).catch(function () {});
                close();
                resolve(true);
            };
        });
    };

    App.copyText = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } finally { ta.remove(); }
        return Promise.resolve();
    };

    // ------------------------------------------------------------- global UI wiring
    document.addEventListener('DOMContentLoaded', function () {
        // Mobile menu
        var menu = document.getElementById('mobile-menu');
        var toggle = document.querySelector('[data-toggle-menu]');
        if (menu && toggle) {
            var closeMenu = null;
            toggle.addEventListener('click', function () {
                toggle.setAttribute('aria-expanded', 'true');
                closeMenu = App.modal(menu, function () { toggle.setAttribute('aria-expanded', 'false'); });
            });
            menu.addEventListener('click', function (e) {
                if (e.target === menu || e.target.closest('[data-close-menu]')) { if (closeMenu) { closeMenu(); } }
            });
        }

        // Dismissable alerts
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-dismiss-alert]');
            if (btn) { btn.closest('.alert').remove(); }
        });

        // Confirm before submitting destructive forms
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var msg = form.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) { e.preventDefault(); }
        });
    });

    window.App = App;
})();
