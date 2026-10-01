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

    App.canStream = function () {
        return !!(window.ReadableStream && window.TextDecoder && window.fetch);
    };

    /**
     * POST JSON and read a Server-Sent Events reply. onEvent(name, data) is called for every event.
     * Resolves with the "done" event's data; rejects with ApiError on "error" events or HTTP errors.
     * If the server answers with plain JSON (e.g. a validation error), it is handled like App.api().
     */
    App.apiStream = function (path, json, onEvent, timeoutMs) {
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, timeoutMs || 90000) : null;
        var done = function () { if (timer) { clearTimeout(timer); } };

        return fetch(App.url(path), {
            method: 'POST',
            headers: { 'X-CSRF-Token': App.csrf, 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
            body: JSON.stringify(Object.assign({}, json, { stream: true })),
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (res) {
            var type = res.headers.get('Content-Type') || '';
            if (type.indexOf('text/event-stream') === -1 || !res.body) {
                return res.text().then(function (text) {
                    done();
                    var payload = null;
                    try { payload = JSON.parse(text); } catch (e) { payload = null; }
                    if (res.status === 401) { window.location.href = App.url('login.php'); }
                    if (!payload || payload.success !== true) {
                        throw new ApiError((payload && payload.message) || 'Request failed.', res.status, (payload && payload.error_kind) || '', payload);
                    }
                    return payload.data || {};
                });
            }
            var reader = res.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            var result = null;
            var failure = null;

            function handle(block) {
                var name = 'message', data = '';
                block.split('\n').forEach(function (line) {
                    if (line.indexOf('event:') === 0) { name = line.slice(6).trim(); }
                    else if (line.indexOf('data:') === 0) { data += line.slice(5).trim(); }
                });
                if (!data) { return; }
                var parsed;
                try { parsed = JSON.parse(data); } catch (e) { return; }
                if (name === 'done') { result = parsed; }
                else if (name === 'error') { failure = new ApiError(parsed.message || 'Request failed.', parsed.status || 500, parsed.error_kind || '', parsed); }
                if (onEvent) { onEvent(name, parsed); }
            }

            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        if (buffer.trim()) { handle(buffer); }
                        done();
                        if (failure) { throw failure; }
                        if (!result) { throw new ApiError('The connection closed before the answer finished. Please try again.', 0, 'network'); }
                        return result;
                    }
                    buffer += decoder.decode(chunk.value, { stream: true }).replace(/\r\n/g, '\n');
                    var idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        handle(buffer.slice(0, idx));
                        buffer = buffer.slice(idx + 2);
                    }
                    return pump();
                });
            }
            return pump();
        }, function (err) {
            done();
            if (err && err.name === 'AbortError') {
                throw new ApiError('The request timed out. Please check your connection and try again.', 0, 'timeout');
            }
            throw new ApiError('Network error. Please check your connection and try again.', 0, 'network');
        });
    };

    /** Best-effort parse of an incomplete JSON document (used to render answers while they stream). */
    App.parsePartialJson = function (text) {
        function close(s) {
            var stack = [], inStr = false, esc = false;
            for (var i = 0; i < s.length; i++) {
                var c = s[i];
                if (inStr) {
                    if (esc) { esc = false; } else if (c === '\\') { esc = true; } else if (c === '"') { inStr = false; }
                    continue;
                }
                if (c === '"') { inStr = true; }
                else if (c === '{' || c === '[') { stack.push(c === '{' ? '}' : ']'); }
                else if (c === '}' || c === ']') { stack.pop(); }
            }
            var out = s;
            if (esc) { out = out.slice(0, -1); }
            if (inStr) { out += '"'; }
            return out.replace(/[,:\s]+$/, '') + stack.reverse().join('');
        }
        var s = String(text || '').trim();
        for (var attempt = 0; attempt < 8 && s; attempt++) {
            try { return JSON.parse(close(s)); } catch (e) { /* trim and retry */ }
            var cut = Math.max(s.lastIndexOf(','), s.lastIndexOf('{'), s.lastIndexOf('['));
            if (cut <= 0) { return null; }
            s = s.slice(0, s[cut] === ',' ? cut : cut + 1);
        }
        return null;
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
