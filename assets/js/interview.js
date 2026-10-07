/* =====================================================================
   Interview screen controller
   States: ready → (connecting) → listening → processing → answer | error
   ===================================================================== */
(function () {
    'use strict';

    var App = window.App;
    var R = window.AnswerRender;
    var cfg = App.readJson('interview-config');
    var $ = function (id) { return document.getElementById(id); };

    var ui = {
        root: $('interview'),
        card: $('listen-card'),
        status: $('listen-status'),
        sub: $('listen-sub'),
        mic: $('mic-btn'),
        micLabel: $('mic-label'),
        wave: $('wave'),
        indicator: $('mic-indicator'),
        engineLabel: $('engine-label'),
        tBox: $('transcript-box'),
        tText: $('transcript-text'),
        errBox: $('error-box'),
        errTitle: $('error-title'),
        errDetail: $('error-detail'),
        retry: $('retry-btn'),
        done: $('done-btn'),
        cancel: $('cancel-btn'),
        typedForm: $('typed-form'),
        typedInput: $('typed-question'),
        typedClose: $('typed-close'),
        qCard: $('question-card'),
        qLabel: $('question-label'),
        qText: $('question-text'),
        qMeta: $('question-meta'),
        aCard: $('answer-card'),
        placeholder: $('placeholder-card'),
        resultCol: $('result-col'),
        actions: $('result-actions'),
        listenAgain: $('listen-again'),
        newQuestion: $('new-question'),
        copy: $('copy-answer'),
        sessionPill: $('session-pill'),
        qCount: $('q-count'),
        endBtn: $('end-session'),
        jobSelect: $('iv-job'),
        sessionList: $('session-list'),
        sessionQs: $('session-questions'),
        sessionCount: $('session-count')
    };
    if (!ui.root) { return; }

    var S = {
        state: 'ready',
        sessionId: cfg.sessionId || null,
        jobId: cfg.jobId || null,
        mode: cfg.mode || 'auto',
        engine: null,
        engineType: null,
        processing: false,
        current: null,                 // {id, question, answer}
        questions: cfg.questions || [],
        prefix: null,                  // {text, at} previous non-question speech (for split questions)
        noSpeechTimer: null,
        liveFailed: false,
        instructions: cfg.instructions || '',
        manualStop: false
    };

    var bars = ui.wave ? ui.wave.querySelectorAll('span') : [];

    // ================================================================ UI state

    function setState(state, status, sub) {
        S.state = state;
        ui.card.setAttribute('data-state', state);
        if (status != null) { ui.status.textContent = status; }
        if (sub != null) { ui.sub.textContent = sub; }
        var listening = state === 'listening';
        var busy = state === 'connecting' || state === 'processing';
        ui.micLabel.textContent = listening ? 'Stop' : busy ? '' : state === 'answer' ? 'Listen' : state === 'error' ? 'Retry' : 'Listen';
        ui.mic.setAttribute('aria-label', listening ? 'Stop listening — question finished' : busy ? 'Working' : 'Listen for interview question');
        ui.mic.setAttribute('aria-pressed', listening ? 'true' : 'false');
        ui.mic.disabled = busy;
        ui.wave.classList.toggle('is-active', listening);
        ui.done.hidden = !listening;
        ui.cancel.hidden = !(listening || state === 'connecting');
        ui.errBox.hidden = state !== 'error';
        ui.resultCol.setAttribute('aria-busy', state === 'processing' ? 'true' : 'false');
        if (!listening) { resetBars(); }
    }

    function setMicIndicator(on, label) {
        ui.indicator.hidden = !on;
        if (on) { ui.engineLabel.textContent = label || (S.engineType === 'live' ? 'Live transcription' : 'Recording'); }
    }

    function showError(title, detail) {
        ui.errTitle.textContent = title || "We couldn't hear the question clearly.";
        ui.errDetail.textContent = detail || '';
        setState('error', 'Error', '');
        ui.retry.focus();
    }

    function resetBars() {
        for (var i = 0; i < bars.length; i++) { bars[i].style.height = '6px'; }
    }

    function onLevel(level) {
        for (var i = 0; i < bars.length; i++) {
            var shape = 1 - Math.abs(i - (bars.length - 1) / 2) / bars.length;
            var h = 6 + Math.round(level * 26 * shape * (0.7 + Math.random() * 0.6));
            bars[i].style.height = Math.min(28, h) + 'px';
        }
    }

    function showTranscript(text) {
        if (!cfg.showTranscript) { return; }
        ui.tBox.hidden = false;
        ui.tText.textContent = text || '';
        ui.tText.scrollTop = ui.tText.scrollHeight;
    }

    function hideTranscript() {
        ui.tBox.hidden = true;
        ui.tText.textContent = '';
    }

    function showQuestionPending(text) {
        ui.placeholder.hidden = true;
        ui.qCard.hidden = false;
        ui.qCard.setAttribute('data-detected', 'pending');
        ui.qLabel.textContent = 'Question detected';
        ui.qText.textContent = '“' + text + '”';
        ui.qMeta.textContent = '';
        ui.aCard.hidden = false;
        ui.aCard.textContent = '';
        ui.aCard.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Preparing answer…' })]));
        ui.aCard.appendChild(R.skeleton());
        ui.actions.hidden = true;
        ui.root.classList.add('has-result');
        scrollToResult();
    }

    function renderResult(item) {
        var a = item.answer;
        ui.placeholder.hidden = true;
        ui.qCard.hidden = false;
        ui.qCard.removeAttribute('data-detected');
        ui.qLabel.textContent = 'Question';
        ui.qText.textContent = item.question;
        ui.qMeta.textContent = '';
        ui.qMeta.appendChild(App.el('span', { 'class': 'badge badge-primary', text: R.typeLabel(a.question_type) }));
        ui.qMeta.appendChild(App.el('span', { 'class': 'badge', text: R.modeLabel(a.answer_mode) + ' answer' }));

        ui.aCard.hidden = false;
        ui.aCard.textContent = '';
        ui.aCard.removeAttribute('data-streaming');
        ui.aCard.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Suggested approach' })]));
        ui.aCard.appendChild(R.renderAnswer(a));
        ui.actions.hidden = false;
        ui.root.classList.add('has-result');
    }

    /** Render a partially streamed answer (called at most once per animation frame). */
    function renderPartial(p) {
        if (!p || p.is_question !== true) { return; }
        if (typeof p.question === 'string' && p.question.length > 8) {
            ui.qCard.removeAttribute('data-detected');
            ui.qLabel.textContent = 'Question';
            ui.qText.textContent = p.question;
        }
        if (!p.key_message && !(p.sections && p.sections.length) && !(p.points && p.points.length)) { return; }
        ui.placeholder.hidden = true;
        ui.aCard.hidden = false;
        ui.aCard.setAttribute('data-streaming', '');
        ui.aCard.textContent = '';
        ui.aCard.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Suggested approach' })]));
        ui.aCard.appendChild(R.renderAnswer(p));
    }

    function clearResult() {
        ui.qCard.hidden = true;
        ui.aCard.hidden = true;
        ui.actions.hidden = true;
        ui.placeholder.hidden = false;
        ui.root.classList.remove('has-result');
        S.current = null;
    }

    function scrollToResult() {
        if (window.matchMedia('(max-width: 1023.98px)').matches) {
            var top = ui.qCard.getBoundingClientRect().top + window.pageYOffset - 70;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }
    }

    function renderSessionList() {
        var n = S.questions.length;
        ui.sessionCount.textContent = n;
        ui.qCount.textContent = n;
        ui.sessionList.hidden = n === 0;
        ui.sessionQs.textContent = '';
        S.questions.forEach(function (q) {
            var b = App.el('button', { type: 'button', text: q.question });
            b.addEventListener('click', function () {
                if (!q.answer) { return; }
                S.current = q;
                renderResult(q);
                setState('answer', 'Answer ready', 'Showing an earlier question from this session.');
                scrollToResult();
            });
            ui.sessionQs.appendChild(App.el('li', null, [b]));
        });
    }

    function showSessionActive(on) {
        ui.sessionPill.hidden = !on;
        ui.endBtn.hidden = !on;
    }

    // ================================================================ session

    function ensureSession() {
        if (S.sessionId) { return Promise.resolve(S.sessionId); }
        setState('connecting', 'Preparing interview…', 'Setting up your session and analysing the job description.');
        syncInstructionsFromBox();
        return App.api('api/start-session.php', { json: { job_id: S.jobId, type: 'live', instructions: S.instructions }, timeout: 60000 }).then(function (d) {
            S.sessionId = d.session.id;
            S.questions = [];
            renderSessionList();
            showSessionActive(true);
            return S.sessionId;
        });
    }

    // ================================================================ interview instructions

    var ins = {
        card: $('instructions-card'), form: $('ins-form'), text: $('ins-text'), save: $('ins-save'),
        badge: $('ins-badge'), preview: $('ins-preview'), count: $('ins-count'), hint: $('ins-hint')
    };
    var insSaved = S.instructions;

    function syncInstructionsFromBox() {
        if (ins.text) { S.instructions = ins.text.value.trim(); }
    }

    function paintInstructions() {
        var t = S.instructions;
        ins.badge.hidden = !t;
        ins.preview.textContent = t ? (t.replace(/\s+/g, ' ').slice(0, 90) + (t.length > 90 ? '…' : '')) : 'Add experience your CV leaves out, or say which examples to use';
        ins.count.textContent = ins.text.value.length + ' / ' + (cfg.maxInstructions || 2000);
    }

    /** Save to the active session (or keep locally until the session starts). */
    function saveInstructions(quiet) {
        syncInstructionsFromBox();
        if (S.instructions === insSaved) { paintInstructions(); return Promise.resolve(); }
        if (!S.sessionId) {
            insSaved = S.instructions;
            paintInstructions();
            if (!quiet) { App.toast('Instructions saved — they will be used when the interview starts.', 'success'); }
            return Promise.resolve();
        }
        var restore = quiet ? function () {} : App.busy(ins.save, 'Saving…');
        return App.api('api/session-instructions.php', { json: { session_id: S.sessionId, instructions: S.instructions } })
            .then(function () {
                restore();
                insSaved = S.instructions;
                paintInstructions();
                if (!quiet) { App.toast('Instructions saved for this interview.', 'success'); }
            })
            .catch(function (e) { restore(); App.toast(e.message, 'error'); });
    }

    if (ins.form) {
        ins.form.addEventListener('submit', function (e) { e.preventDefault(); saveInstructions(false); });
        ins.text.addEventListener('input', function () { ins.count.textContent = ins.text.value.length + ' / ' + (cfg.maxInstructions || 2000); });
        ins.text.addEventListener('blur', function () { saveInstructions(true); });
        paintInstructions();
    }

    // ================================================================ listening

    function engineOpts() {
        return {
            onState: onEngineState,
            onLevel: onLevel,
            onPartial: function (t) { showTranscript(t); },
            onFinal: onTranscript,
            onError: onEngineError,
            onTick: onTick,
            autoStop: cfg.autoDetect,
            autoCommit: cfg.autoDetect,
            silenceMs: 1200,
            maxMs: 120000,
            maxBytes: cfg.maxAudioBytes,
            sessionId: function () { return S.sessionId; },
            purpose: 'question'
        };
    }

    function wantLive() {
        if (S.liveFailed || !cfg.realtimeEnabled || cfg.transcriptionMode === 'recorded') { return false; }
        return window.LiveTranscriber && window.LiveTranscriber.supported();
    }

    function startListening() {
        if (S.state === 'listening' || S.state === 'connecting' || S.state === 'processing') { return; }
        App.ensureMicConsent(cfg.consented).then(function (ok) {
            if (!ok) { return; }
            cfg.consented = true;
            hideTypedForm();
            S.prefix = null;
            S.manualStop = false;
            ensureSession().then(beginEngine).catch(function (e) {
                showError('Could not start the interview session.', e.message);
            });
        });
    }

    function beginEngine() {
        destroyEngine();
        var live = wantLive();
        if (!live && !window.RecordedTranscriber.supported()) {
            showError('Audio capture is not supported in this browser.', 'Please type the question instead, or use the latest Chrome, Edge or Safari.');
            return;
        }
        S.engineType = live ? 'live' : 'recorded';
        S.engine = live ? new window.LiveTranscriber(engineOpts()) : new window.RecordedTranscriber(engineOpts());
        setState('connecting', live ? 'Connecting…' : 'Starting microphone…', 'Allow microphone access if your browser asks.');
        S.engine.start().catch(function (e) {
            if (live && e.kind === 'realtime_failed' && cfg.transcriptionMode !== 'live') {
                fallbackToRecorded(e);
                return;
            }
            handleStartError(e);
        });
    }

    function fallbackToRecorded(e) {
        var stream = S.engine && S.engine.takeStream ? S.engine.takeStream() : null;
        destroyEngine();
        S.liveFailed = true;
        App.toast('Live transcription is unavailable. Switching to recorded-question mode.', 'info');
        if (window.console) { console.warn('Realtime fallback:', e && e.message); }
        S.engineType = 'recorded';
        var rec = new window.RecordedTranscriber(engineOpts());
        if (stream) { rec.stream = stream; }
        S.engine = rec;
        rec.start().catch(handleStartError);
    }

    function handleStartError(e) {
        destroyEngine();
        setMicIndicator(false);
        if (e && (e.kind === 'denied' || e.kind === 'no_device' || e.kind === 'busy' || e.kind === 'unsupported')) {
            showError('Microphone unavailable', e.message);
        } else {
            showError("We couldn't start listening.", (e && e.message) || 'Please try again or type the question.');
        }
    }

    function onEngineState(state) {
        if (state === 'listening') {
            setMicIndicator(true);
            setState('listening', 'Listening...', S.engineType === 'live'
                ? 'Listening for the interviewer’s question...'
                : (cfg.autoDetect ? 'Listening for the interviewer’s question... stops automatically when they pause.' : 'Tap Stop when the interviewer finishes the question.'));
            showTranscript('');
            armNoSpeechHint();
            ui.mic.focus({ preventScroll: true });
        } else if (state === 'committed') {
            ui.sub.textContent = 'Transcribing…';
        } else if (state === 'transcribing') {
            setMicIndicator(false);
            setState('processing', 'Transcribing…', 'Converting the question to text.');
        } else if (state === 'released') {
            setMicIndicator(false);
        }
    }

    function onTick(elapsed, meter) {
        if (meter && meter.speechMs > 200 && S.noSpeechTimer) {
            clearTimeout(S.noSpeechTimer);
            S.noSpeechTimer = null;
            if (S.state === 'listening') { ui.sub.textContent = 'Hearing speech… waiting for the question to finish.'; }
        }
    }

    function armNoSpeechHint() {
        if (S.noSpeechTimer) { clearTimeout(S.noSpeechTimer); }
        S.noSpeechTimer = setTimeout(function () {
            if (S.state === 'listening') { ui.sub.textContent = 'No audio detected yet — check your microphone is not muted.'; }
        }, 15000);
    }

    function onEngineError(e) {
        if (S.state === 'answer') { return; }
        var kind = e && e.kind;
        if (kind === 'no_audio' && !S.manualStop && S.engine && S.engineType === 'recorded' && cfg.autoDetect && S.engine.stream && S.state !== 'error') {
            // Silence only: keep listening quietly.
            S.engine.restart().catch(handleStartError);
            return;
        }
        if (S.engineType === 'live' && (kind === 'realtime_closed' || kind === 'realtime_error' || kind === 'realtime_timeout') && cfg.transcriptionMode !== 'live') {
            fallbackToRecorded(e);
            return;
        }
        destroyEngine();
        setMicIndicator(false);
        if (kind === 'no_audio') {
            showError("We couldn't hear the question clearly.", 'Make sure your microphone can hear the interviewer, then try again.');
        } else {
            showError("We couldn't hear the question clearly.", (e && e.message) || '');
        }
    }

    function stopListening() {
        if (!S.engine) { return; }
        S.manualStop = true;
        S.engine.stop();
    }

    function cancelListening() {
        destroyEngine();
        setMicIndicator(false);
        hideTranscript();
        setState(S.current ? 'answer' : 'ready', S.current ? 'Answer ready' : 'Ready', 'Press Listen when the interviewer starts asking a question.');
    }

    function destroyEngine() {
        if (S.noSpeechTimer) { clearTimeout(S.noSpeechTimer); S.noSpeechTimer = null; }
        if (S.engine) {
            var eng = S.engine;
            S.engine = null;
            try { eng.destroy(); } catch (e) { /* ignore */ }
        }
    }

    // ================================================================ transcript → answer

    function onTranscript(text, info) {
        if (S.processing) { return; }
        text = (text || '').trim();
        if (!text) {
            if (S.engineType === 'live' && S.engine) { ui.sub.textContent = 'Listening for the interviewer’s question...'; return; }
            onEngineError({ kind: 'no_audio' });
            return;
        }
        // Join with recent non-question speech in case the question was split by a pause.
        var full = text;
        if (S.prefix && Date.now() - S.prefix.at < 15000) { full = (S.prefix.text + ' ' + text).trim(); }
        showTranscript(full);
        requestAnswer(full, S.engineType === 'live' ? 'live' : 'recorded', null);
    }

    function requestAnswer(text, source, questionId) {
        S.processing = true;
        // Be explicit about the microphone: a live stream keeps listening, a recorded stream is paused.
        setMicIndicator(!!S.engine, S.engineType === 'live' ? 'Live transcription' : 'Paused');
        setState('processing', 'Processing...', 'AI is preparing your answer...');
        if (!questionId) { showQuestionPending(text); }

        var body = { session_id: S.sessionId, transcript: text, mode: S.mode, source: source };
        if (questionId) { body.question_id = questionId; }

        var streamed = '';
        var frame = null;
        var onStream = function (name, data) {
            if (name !== 'delta') { return; }
            streamed += data.t || '';
            if (frame) { return; }
            frame = (window.requestAnimationFrame || setTimeout)(function () {
                frame = null;
                if (S.processing) { renderPartial(App.parsePartialJson(streamed)); }
            });
        };

        var pendingIns = ins.text && ins.text.value.trim() !== insSaved ? saveInstructions(true) : Promise.resolve();
        return pendingIns.then(ensureSession).then(function (sid) {
            body.session_id = sid;
            if (S.state !== 'processing') { setState('processing', 'Processing...', 'AI is preparing your answer...'); }
            return App.canStream()
                ? App.apiStream('api/generate-answer.php', body, onStream, 90000)
                : App.api('api/generate-answer.php', { json: body, timeout: 70000 });
        }).then(function (d) {
            S.processing = false;
            if (!d.is_question) {
                S.prefix = { text: text, at: Date.now() };
                return continueListening(source);
            }
            S.prefix = null;
            destroyEngine();
            setMicIndicator(false);
            hideTranscript();
            var item = { id: d.question_id, question: d.question, answer: d.answer };
            if (questionId) {
                S.questions = S.questions.map(function (q) { return q.id === questionId ? item : q; });
            } else {
                S.questions.push(item);
            }
            S.current = item;
            renderResult(item);
            renderSessionList();
            setState('answer', 'Answer ready', 'Press Listen for the next question.');
            scrollToResult();
            ui.qText.setAttribute('tabindex', '-1');
        }).catch(function (e) {
            S.processing = false;
            destroyEngine();
            setMicIndicator(false);
            if (S.current) { renderResult(S.current); } else { clearResult(); }
            if (e.status === 409 || e.status === 404) {
                S.sessionId = null;
                showSessionActive(false);
            }
            showError(e.kind === 'quota' || e.kind === 'auth' || e.kind === 'not_configured' ? 'AI service unavailable' : 'Could not prepare an answer', e.message);
            ui.typedInput.value = text;
        });
    }

    /** Transcript wasn't a question: go back to listening. */
    function continueListening(source) {
        if (S.current) { renderResult(S.current); } else { clearResult(); }
        if (source === 'typed') {
            showError('That doesn’t look like an interview question.', 'Try rephrasing it as a question.');
            return;
        }
        if (S.engine && S.engineType === 'live' && S.engine.active) {
            setState('listening', 'Listening...', 'Listening for the interviewer’s question...');
            setMicIndicator(true);
            showTranscript('');
            return;
        }
        if (S.engine && S.engineType === 'recorded' && S.engine.stream && !S.manualStop) {
            S.engine.restart().then(function () {
                ui.sub.textContent = 'No question detected yet — still listening…';
            }).catch(handleStartError);
            return;
        }
        setState('ready', 'Ready', 'No interview question detected. Press Listen to try again.');
    }

    // ================================================================ typed questions

    function openTypedForm() {
        if (S.state === 'listening' || S.state === 'connecting') { cancelListening(); }
        ui.typedForm.hidden = false;
        ui.typedInput.focus();
    }

    function hideTypedForm() {
        ui.typedForm.hidden = true;
    }

    ui.typedForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var q = ui.typedInput.value.trim();
        if (q.length < 5) {
            App.toast('Please enter the interview question.', 'error');
            ui.typedInput.focus();
            return;
        }
        if (S.state === 'processing') { return; }
        hideTypedForm();
        destroyEngine();
        requestAnswer(q, 'typed', null).then(function () {
            if (S.state === 'answer') { ui.typedInput.value = ''; }
        });
    });
    ui.typedClose.addEventListener('click', hideTypedForm);
    Array.prototype.forEach.call(document.querySelectorAll('[data-open-typed]'), function (b) {
        b.addEventListener('click', openTypedForm);
    });

    // ================================================================ scanned questions
    /*
     * A third way to get a question in, next to Listen and Type: photograph the question.
     * This pulls it into the interview already in progress (extract_only, so no scan session is
     * opened and nothing is stored until the answer is generated). Whole papers go to scan.php.
     */

    var sc = {
        modal: $('scan-modal'), sub: $('iv-scan-sub'), cam: $('iv-cam'), video: $('iv-video'),
        pages: $('iv-pages'), error: $('iv-scan-error'), picks: $('iv-scan-picks'),
        busy: $('iv-scan-busy'), busyText: $('iv-scan-busy-text'),
        shoot: $('iv-shoot'), pick: $('iv-pick'), read: $('iv-scan-read'),
        retake: $('iv-scan-retake'), close: $('iv-scan-close'), file: $('iv-file')
    };
    var scan = { camera: null, page: null, url: null, closeModal: null, busyNow: false };

    function scanError(msg) {
        sc.error.textContent = msg || '';
        sc.error.hidden = !msg;
    }

    function scanBusy(on, text) {
        scan.busyNow = on;
        sc.busy.hidden = !on;
        if (text) { sc.busyText.textContent = text; }
        [sc.shoot, sc.pick, sc.read, sc.retake].forEach(function (b) { b.disabled = on; });
    }

    /** No page captured yet → capture controls; one captured → preview + Read. */
    function paintScan() {
        var has = !!scan.page;
        sc.pages.textContent = '';
        sc.pages.hidden = !has;
        if (has) {
            sc.pages.appendChild(App.el('li', { 'class': 'sc-page' }, [
                scan.url ? App.el('img', { src: scan.url, alt: 'Captured question' }) : null
            ]));
        }
        sc.shoot.hidden = has || !window.PageCamera.supported();
        sc.pick.hidden = has;
        sc.read.hidden = !has;
        sc.retake.hidden = !has;
        sc.sub.textContent = has
            ? 'Check the question is readable, then press Read question.'
            : 'Point the camera at the question, or choose a photo. The whole question must be inside the frame.';
    }

    function clearScanPage() {
        if (scan.url) { URL.revokeObjectURL(scan.url); }
        scan.page = null;
        scan.url = null;
        sc.picks.hidden = true;
        sc.picks.textContent = '';
        paintScan();
    }

    function stopScanCamera() {
        if (scan.camera) { scan.camera.stop(); }
        sc.cam.hidden = true;
    }

    function openScan() {
        if (S.state === 'listening' || S.state === 'connecting') { cancelListening(); }
        hideTypedForm();
        scanError('');
        scanBusy(false);
        clearScanPage();
        scan.closeModal = App.modal(sc.modal, function () {
            stopScanCamera();
            clearScanPage();
        });
        if (!window.PageCamera.supported()) {
            scanError(window.isSecureContext
                ? 'This browser cannot use the camera. Choose a photo instead.'
                : 'Camera access requires HTTPS. Choose a photo instead.');
            return;
        }
        scan.camera = scan.camera || new window.PageCamera(sc.video);
        sc.cam.hidden = false;
        scan.camera.start().catch(function (e) {
            sc.cam.hidden = true;
            scanError(e.message);
        });
    }

    function closeScan() {
        if (scan.closeModal) { scan.closeModal(); scan.closeModal = null; }
    }

    sc.shoot.addEventListener('click', function () {
        if (!scan.camera || !scan.camera.active()) { scanError('The camera is not running. Choose a photo instead.'); return; }
        var restore = App.busy(sc.shoot, 'Capturing…');
        scan.camera.capture(1).then(function (file) {
            restore();
            scanError('');
            scan.page = file;
            scan.url = URL.createObjectURL(file);
            stopScanCamera();
            paintScan();
            sc.read.focus();
        }).catch(function (e) { restore(); scanError(e.message); });
    });

    sc.pick.addEventListener('click', function () { sc.file.click(); });
    sc.file.addEventListener('change', function () {
        var file = (sc.file.files || [])[0];
        sc.file.value = '';
        if (!file) { return; }
        scanError('');
        scanBusy(true, 'Preparing the photo…');
        window.PageCamera.prepareFile(file).then(function (prepared) {
            scanBusy(false);
            if (prepared.size > (cfg.maxScanPageBytes || 8388608)) {
                scanError('That photo is too large. Take it again with the camera, or use a smaller image.');
                return;
            }
            scan.page = prepared;
            scan.url = URL.createObjectURL(prepared);
            stopScanCamera();
            paintScan();
        }, function () { scanBusy(false); scanError('That photo could not be read.'); });
    });

    sc.retake.addEventListener('click', function () {
        scanError('');
        clearScanPage();
        if (window.PageCamera.supported()) {
            scan.camera = scan.camera || new window.PageCamera(sc.video);
            sc.cam.hidden = false;
            scan.camera.start().catch(function (e) { sc.cam.hidden = true; scanError(e.message); });
        }
    });

    sc.read.addEventListener('click', function () {
        if (!scan.page || scan.busyNow) { return; }
        scanError('');
        sc.picks.hidden = true;
        sc.picks.textContent = '';
        scanBusy(true, 'Reading the question…');

        var form = new FormData();
        form.append('pages[]', scan.page, scan.page.name || 'question.jpg');
        form.append('extract_only', '1');
        if (S.jobId) { form.append('job_id', String(S.jobId)); }

        App.api('api/scan-extract.php', { form: form, timeout: 120000 }).then(function (d) {
            scanBusy(false);
            var qs = d.questions || [];
            if (qs.length === 1) {
                useScannedQuestion(qs[0].text);
            } else {
                offerScannedQuestions(qs);
            }
        }).catch(function (e) {
            scanBusy(false);
            scanError(e.message + (e.data && e.data.notes ? ' (' + e.data.notes + ')' : ''));
        });
    });

    /** Several questions on the page: let the candidate pick the one being asked. */
    function offerScannedQuestions(qs) {
        sc.picks.textContent = '';
        qs.forEach(function (q) {
            var b = App.el('button', { type: 'button', 'class': 'iv-scan-pick' }, [
                q.number ? App.el('span', { 'class': 'sc-num', text: q.number }) : null,
                App.el('span', { text: q.text })
            ]);
            b.addEventListener('click', function () { useScannedQuestion(q.text); });
            sc.picks.appendChild(App.el('li', null, [b]));
        });
        sc.picks.hidden = false;
        sc.sub.textContent = qs.length + ' questions on that page — tap the one being asked.';
        sc.read.hidden = true;
    }

    function useScannedQuestion(text) {
        closeScan();
        destroyEngine();
        requestAnswer(text, 'scan', null);
    }

    sc.close.addEventListener('click', closeScan);
    Array.prototype.forEach.call(document.querySelectorAll('[data-open-scan]'), function (b) {
        b.addEventListener('click', openScan);
    });
    window.addEventListener('pagehide', stopScanCamera);

    // ================================================================ controls

    ui.mic.addEventListener('click', function () {
        if (S.state === 'listening') { stopListening(); return; }
        if (S.state === 'connecting' || S.state === 'processing') { return; }
        startListening();
    });
    ui.done.addEventListener('click', stopListening);
    ui.cancel.addEventListener('click', cancelListening);
    ui.retry.addEventListener('click', function () { setState('ready'); startListening(); });
    ui.listenAgain.addEventListener('click', startListening);
    ui.newQuestion.addEventListener('click', function () {
        clearResult();
        setState('ready', 'Ready', 'Press Listen when the interviewer starts asking a question.');
        ui.mic.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    ui.copy.addEventListener('click', function () {
        if (!S.current) { return; }
        App.copyText(R.answerToText(S.current.question, S.current.answer)).then(function () {
            App.toast('Answer copied', 'success');
        });
    });

    // Answer style: changing it after an answer regenerates that answer in the new style.
    Array.prototype.forEach.call(document.querySelectorAll('input[name="answer-mode"]'), function (r) {
        r.addEventListener('change', function () {
            S.mode = r.value;
            App.api('api/preferences.php', { json: { default_answer_mode: S.mode } }).catch(function () {});
            if (S.state === 'answer' && S.current) {
                requestAnswer(S.current.question, 'typed', S.current.id || null);
            }
        });
    });

    ui.jobSelect.addEventListener('change', function () {
        var id = ui.jobSelect.value;
        if (S.questions.length && !window.confirm('Switch job? Your current interview session will be closed and a new one started.')) {
            ui.jobSelect.value = S.jobId ? String(S.jobId) : '';
            return;
        }
        destroyEngine();
        var go = function () { window.location.href = App.url('interview.php') + (id ? '?job=' + encodeURIComponent(id) : ''); };
        if (S.sessionId) {
            App.api('api/end-session.php', { json: { session_id: S.sessionId } }).then(go, go);
        } else {
            go();
        }
    });

    ui.endBtn.addEventListener('click', function () {
        if (!S.sessionId) { return; }
        if (!window.confirm('End this interview session?')) { return; }
        destroyEngine();
        var restore = App.busy(ui.endBtn, 'Ending…');
        App.api('api/end-session.php', { json: { session_id: S.sessionId } }).then(function (d) {
            restore();
            S.sessionId = null;
            S.questions = [];
            renderSessionList();
            showSessionActive(false);
            clearResult();
            setState('ready', 'Interview ended', d.questions + ' question' + (d.questions === 1 ? '' : 's') + ' saved to history. Press Listen to start a new interview.');
            App.toast('Interview saved to history', 'success');
        }).catch(function (e) { restore(); App.toast(e.message, 'error'); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && (S.state === 'listening' || S.state === 'connecting')) { cancelListening(); }
    });

    window.addEventListener('pagehide', destroyEngine);
    window.addEventListener('beforeunload', destroyEngine);

    // ================================================================ init
    renderSessionList();
    showSessionActive(!!S.sessionId);
    if (S.questions.length) {
        var last = S.questions[S.questions.length - 1];
        if (last.answer) {
            S.current = last;
            renderResult(last);
            setState('answer', 'Answer ready', 'Resumed your active session. Press Listen for the next question.');
        }
    } else {
        setState('ready', 'Ready', 'Press Listen when the interviewer starts asking a question.');
    }
    if (!window.isSecureContext) {
        App.toast('Microphone access requires HTTPS or localhost.', 'error');
    }
})();
