/* =====================================================================
   Practice mode: AI asks one question at a time; candidate speaks/types an answer; AI gives feedback.
   ===================================================================== */
(function () {
    'use strict';

    var App = window.App;
    var R = window.AnswerRender;
    var cfg = App.readJson('practice-config');
    var $ = function (id) { return document.getElementById(id); };
    if (!$('practice-stage')) { return; }

    var S = { sessionId: cfg.sessionId || null, question: null, rec: null, timer: null, started: 0 };

    var ui = {
        setup: $('practice-setup'), stage: $('practice-stage'),
        job: $('p-job'), focus: $('p-focus'), start: $('p-start'), resume: $('p-resume'),
        num: $('p-num'), end: $('p-end'),
        q: $('p-question'), meta: $('p-meta'), tip: $('p-tip'),
        speak: $('p-speak'), type: $('p-type'), skip: $('p-skip'), hint: $('p-hint'),
        recPanel: $('p-rec'), recStatus: $('p-rec-status'), timerEl: $('p-timer'), wave: $('p-wave'), stop: $('p-stop'),
        busy: $('p-busy'), busyText: $('p-busy-text'),
        form: $('p-answer-form'), answer: $('p-answer'), error: $('p-error'),
        approach: $('p-approach'), feedback: $('p-feedback'), next: $('p-next')
    };
    var bars = ui.wave.querySelectorAll('span');

    function busy(on, text) {
        ui.busy.hidden = !on;
        if (text) { ui.busyText.textContent = text; }
        [ui.speak, ui.type, ui.skip, ui.hint, ui.next].forEach(function (b) { b.disabled = on; });
    }
    function showError(msg) { ui.error.textContent = msg; ui.error.hidden = !msg; }

    function resetQuestionUI() {
        ui.form.hidden = true;
        ui.answer.value = '';
        ui.recPanel.hidden = true;
        ui.approach.hidden = true;
        ui.feedback.hidden = true;
        ui.next.hidden = true;
        showError('');
    }

    function startSession() {
        var restore = App.busy(ui.start, 'Starting…');
        App.api('api/start-session.php', { json: { job_id: ui.job.value || null, type: 'practice' }, timeout: 60000 })
            .then(function (d) {
                restore();
                S.sessionId = d.session.id;
                showStage();
                nextQuestion();
            }).catch(function (e) { restore(); App.toast(e.message, 'error'); });
    }

    function showStage() {
        ui.setup.hidden = true;
        ui.stage.hidden = false;
    }

    function nextQuestion() {
        resetQuestionUI();
        busy(true, 'Generating your next question…');
        ui.q.textContent = '';
        ui.meta.textContent = '';
        ui.tip.textContent = '';
        App.api('api/practice-question.php', { json: { session_id: S.sessionId, focus: ui.focus.value || null }, timeout: 60000 })
            .then(function (d) {
                busy(false);
                S.question = d;
                ui.num.textContent = d.number;
                ui.q.textContent = d.question;
                ui.meta.appendChild(App.el('span', { 'class': 'badge badge-primary', text: R.typeLabel(d.question_type) }));
                if (d.why_asked) { ui.meta.appendChild(App.el('span', { 'class': 'badge', text: d.why_asked })); }
                ui.tip.textContent = d.tip ? 'Tip: ' + d.tip : '';
                ui.q.setAttribute('tabindex', '-1');
                ui.q.focus();
            }).catch(function (e) {
                busy(false);
                showError(e.message);
                ui.next.hidden = false;
                if (e.status === 404 || e.status === 409) { ui.setup.hidden = false; ui.stage.hidden = true; }
            });
    }

    // ------------------------------------------------------------- speaking
    function speak() {
        App.ensureMicConsent(cfg.consented).then(function (ok) {
            if (!ok) { return; }
            cfg.consented = true;
            showError('');
            ui.form.hidden = true;
            S.rec = new window.RecordedTranscriber({
                autoStop: false,
                maxMs: 180000,
                maxBytes: cfg.maxAudioBytes,
                purpose: 'answer',
                sessionId: function () { return S.sessionId; },
                onLevel: function (level) {
                    for (var i = 0; i < bars.length; i++) {
                        bars[i].style.height = Math.min(28, 6 + Math.round(level * 26 * (0.6 + Math.random() * 0.6))) + 'px';
                    }
                },
                onState: function (state) {
                    if (state === 'listening') {
                        ui.recPanel.hidden = false;
                        S.started = Date.now();
                        tickTimer();
                        S.timer = setInterval(tickTimer, 500);
                        ui.stop.focus();
                    } else if (state === 'transcribing') {
                        stopTimer();
                        ui.recPanel.hidden = true;
                        busy(true, 'Transcribing your answer…');
                    }
                },
                onFinal: function (text) {
                    busy(false);
                    releaseRec();
                    ui.form.hidden = false;
                    ui.answer.value = text;
                    ui.answer.focus();
                    App.toast('Review your transcribed answer, then tap Analyse.', 'info');
                },
                onError: function (e) {
                    busy(false);
                    stopTimer();
                    releaseRec();
                    ui.recPanel.hidden = true;
                    showError(e.kind === 'no_audio' ? "We couldn't hear your answer clearly. Try again or type it instead." : e.message);
                }
            });
            busy(true, 'Starting microphone…');
            S.rec.start().then(function () { busy(false); ui.speak.disabled = true; ui.type.disabled = true; })
                .catch(function (e) { busy(false); releaseRec(); showError(e.message); });
        });
    }

    function tickTimer() {
        var s = Math.floor((Date.now() - S.started) / 1000);
        ui.timerEl.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }
    function stopTimer() { if (S.timer) { clearInterval(S.timer); S.timer = null; } }
    function releaseRec() {
        if (S.rec) { try { S.rec.destroy(); } catch (e) { /* ignore */ } S.rec = null; }
    }

    // ------------------------------------------------------------- feedback
    ui.form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = ui.answer.value.trim();
        if (text.length < 15) { showError('Your answer is too short to analyse.'); return; }
        showError('');
        var btn = ui.form.querySelector('button[type="submit"]');
        var restore = App.busy(btn, 'Analysing…');
        App.api('api/practice-feedback.php', { json: { question_id: S.question.question_id, answer_text: text }, timeout: 70000 })
            .then(function (d) {
                restore();
                ui.feedback.textContent = '';
                ui.feedback.appendChild(App.el('p', { 'class': 'answer-label', text: 'Coaching feedback' }));
                ui.feedback.appendChild(R.renderFeedback(d.feedback));
                ui.feedback.hidden = false;
                ui.next.hidden = false;
                ui.feedback.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }).catch(function (err) { restore(); showError(err.message); });
    });

    function showApproach() {
        var restore = App.busy(ui.hint, 'Preparing…');
        App.api('api/generate-answer.php', { json: { session_id: S.sessionId, question_id: S.question.question_id, mode: 'auto', source: 'typed' }, timeout: 70000 })
            .then(function (d) {
                restore();
                ui.approach.textContent = '';
                ui.approach.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Suggested approach' })]));
                ui.approach.appendChild(R.renderAnswer(d.answer));
                ui.approach.hidden = false;
                ui.next.hidden = false;
            }).catch(function (e) { restore(); showError(e.message); });
    }

    ui.start.addEventListener('click', startSession);
    if (ui.resume) {
        ui.resume.addEventListener('click', function () { showStage(); nextQuestion(); });
    }
    ui.speak.addEventListener('click', speak);
    ui.stop.addEventListener('click', function () { if (S.rec) { S.rec.stop(); } ui.speak.disabled = false; ui.type.disabled = false; });
    ui.type.addEventListener('click', function () { ui.form.hidden = false; ui.answer.focus(); });
    ui.skip.addEventListener('click', function () { releaseRec(); nextQuestion(); });
    ui.hint.addEventListener('click', showApproach);
    ui.next.addEventListener('click', nextQuestion);
    ui.end.addEventListener('click', function () {
        releaseRec();
        App.api('api/end-session.php', { json: { session_id: S.sessionId } }).then(function () {
            window.location.href = App.url('history.php') + '?id=' + encodeURIComponent(S.sessionId);
        }).catch(function (e) { App.toast(e.message, 'error'); });
    });
    window.addEventListener('pagehide', releaseRec);
})();
