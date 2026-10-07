/* =====================================================================
   Scan screen controller
   Capture pages → extract every question → answer them one by one.
   ===================================================================== */
(function () {
    'use strict';

    var App = window.App;
    var R = window.AnswerRender;
    var cfg = App.readJson('scan-config');
    var $ = function (id) { return document.getElementById(id); };

    var ui = {
        capture: $('sc-capture'),
        job: $('sc-job'), mode: $('sc-mode'), modeHint: $('sc-mode-hint'),
        cam: $('sc-cam'), video: $('sc-video'), camHint: $('sc-cam-hint'),
        shoot: $('sc-shoot'), camClose: $('sc-cam-close'), camOpen: $('sc-cam-open'),
        pick: $('sc-pick'), file: $('sc-file'),
        pages: $('sc-pages'), pagesHint: $('sc-pages-hint'),
        error: $('sc-error'),
        hint: $('sc-hint'), ins: $('sc-ins'),
        scan: $('sc-scan'), clear: $('sc-clear'),
        busy: $('sc-busy'), busyText: $('sc-busy-text'),
        result: $('sc-result'),
        doc: $('sc-doc'), docTitle: $('sc-doc-title'), docMeta: $('sc-doc-meta'), docNote: $('sc-doc-note'),
        count: $('sc-count'), answerAll: $('sc-answer-all'), stopAll: $('sc-stop-all'),
        copyAll: $('sc-copy-all'), rescan: $('sc-rescan'), list: $('sc-list'), end: $('sc-end')
    };
    if (!ui.capture) { return; }

    var S = {
        sessionId: cfg.sessionId || null,
        jobId: cfg.jobId || null,
        pages: [],                 // {file, url}
        questions: (cfg.questions || []).map(function (q) { return normQ(q); }),
        doc: null,
        camera: null,
        scanning: false,
        preparing: false,
        runningAll: false,
        progress: '',
        abortAll: false
    };

    function normQ(q) {
        return {
            id: q.id || null,
            number: q.number || '',
            text: q.text || '',
            marks: q.marks || '',
            question_type: q.question_type || 'unknown',
            answer: q.answer || null,
            busy: false,
            error: ''
        };
    }

    // ================================================================ capture

    function showError(msg) {
        ui.error.textContent = msg || '';
        ui.error.hidden = !msg;
        if (msg) { ui.error.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }

    function busy(on, text) {
        ui.busy.hidden = !on;
        if (text) { ui.busyText.textContent = text; }
        ui.scan.disabled = on || S.pages.length === 0;
        ui.camOpen.disabled = on;
        ui.pick.disabled = on;
    }

    function renderPages() {
        ui.pages.textContent = '';
        S.pages.forEach(function (p, i) {
            var thumb = p.url
                ? App.el('img', { src: p.url, alt: 'Page ' + (i + 1) })
                : App.el('span', { 'class': 'sc-pdf', text: 'PDF' });
            var remove = App.el('button', { type: 'button', 'class': 'sc-page-x', 'aria-label': 'Remove page ' + (i + 1) });
            remove.innerHTML = '&times;';
            remove.addEventListener('click', function () { removePage(i); });
            ui.pages.appendChild(App.el('li', { 'class': 'sc-page' }, [
                thumb, App.el('span', { 'class': 'sc-page-n', text: String(i + 1) }), remove
            ]));
        });
        var n = S.pages.length;
        ui.pages.hidden = n === 0;
        ui.clear.hidden = n === 0;
        ui.pagesHint.hidden = n === 0;
        ui.pagesHint.textContent = n === 0 ? '' :
            n + ' page' + (n === 1 ? '' : 's') + ' ready · up to ' + cfg.maxPages + ' per scan. Pages are read together, in this order.';
        ui.scan.disabled = n === 0 || S.scanning || S.preparing;
        ui.shoot.disabled = n >= cfg.maxPages;
        ui.camHint.textContent = n >= cfg.maxPages
            ? 'Page limit reached — scan these ' + n + ' pages first.'
            : 'Hold the camera straight above the page so all four corners are inside the frame.';
    }

    function addPage(file) {
        if (S.pages.length >= cfg.maxPages) {
            showError('You can scan up to ' + cfg.maxPages + ' pages at a time.');
            return false;
        }
        if (file.size > cfg.maxPageBytes) {
            showError('"' + file.name + '" is too large (max ' + Math.round(cfg.maxPageBytes / 1048576) + ' MB per page).');
            return false;
        }
        S.pages.push({ file: file, url: /^image\//.test(file.type) ? URL.createObjectURL(file) : null });
        renderPages();
        return true;
    }

    function removePage(i) {
        var p = S.pages.splice(i, 1)[0];
        if (p && p.url) { URL.revokeObjectURL(p.url); }
        renderPages();
    }

    function clearPages() {
        S.pages.forEach(function (p) { if (p.url) { URL.revokeObjectURL(p.url); } });
        S.pages = [];
        renderPages();
        showError('');
    }

    ui.camOpen.addEventListener('click', function () {
        showError('');
        S.camera = S.camera || new window.PageCamera(ui.video);
        ui.cam.hidden = false;
        S.camera.start().then(function () {
            ui.shoot.focus();
        }).catch(function (e) {
            ui.cam.hidden = true;
            showError(e.message);
        });
    });

    function closeCamera() {
        if (S.camera) { S.camera.stop(); }
        ui.cam.hidden = true;
    }
    ui.camClose.addEventListener('click', closeCamera);

    ui.shoot.addEventListener('click', function () {
        if (!S.camera) { return; }
        var restore = App.busy(ui.shoot, 'Capturing…');
        S.camera.capture(S.pages.length + 1).then(function (file) {
            restore();
            showError('');
            if (addPage(file) && S.pages.length >= cfg.maxPages) { closeCamera(); }
        }).catch(function (e) { restore(); showError(e.message); });
    });

    ui.pick.addEventListener('click', function () { ui.file.click(); });
    ui.file.addEventListener('change', function () {
        var files = Array.prototype.slice.call(ui.file.files || []);
        ui.file.value = '';
        if (!files.length) { return; }
        showError('');
        // A PDF holds the whole paper, so it is scanned on its own.
        if (files.some(function (f) { return f.type === 'application/pdf'; })) {
            if (files.length > 1) { showError('Please add a PDF on its own — it already contains the whole paper.'); return; }
            clearPages();
            addPage(files[0]);
            return;
        }
        S.preparing = true;
        busy(true, 'Preparing pages…');
        var finish = function () { S.preparing = false; busy(false); renderPages(); };
        files.reduce(function (chain, f) {
            return chain.then(function () {
                return window.PageCamera.prepareFile(f).then(function (prepared) { addPage(prepared); });
            });
        }, Promise.resolve()).then(finish, finish);
    });

    ui.clear.addEventListener('click', clearPages);

    // ================================================================ scanning

    ui.scan.addEventListener('click', function () {
        if (S.scanning || !S.pages.length) { return; }
        S.scanning = true;
        showError('');
        closeCamera();
        busy(true, 'Reading the page' + (S.pages.length === 1 ? '' : 's') + ' and pulling out the questions…');

        var form = new FormData();
        S.pages.forEach(function (p, i) { form.append('pages[]', p.file, p.file.name || 'page-' + (i + 1) + '.jpg'); });
        if (ui.job.value) { form.append('job_id', ui.job.value); }
        if (ui.hint.value.trim()) { form.append('hint', ui.hint.value.trim()); }
        form.append('instructions', ui.ins.value.trim());

        App.api('api/scan-extract.php', { form: form, timeout: 240000 }).then(function (d) {
            S.scanning = false;
            busy(false);
            S.sessionId = d.session.id;
            S.jobId = ui.job.value ? parseInt(ui.job.value, 10) : null;
            S.doc = d.document;
            S.questions = (d.questions || []).map(normQ);
            if (d.document.written && ui.mode.value === 'auto') {
                ui.modeHint.textContent = 'This looks like a paper to write on, so Auto will produce full written paragraphs.';
            }
            clearPages();
            ui.end.hidden = false;
            renderDoc();
            renderList();
            ui.result.hidden = false;
            ui.result.scrollIntoView({ block: 'start', behavior: 'smooth' });
            if (!d.saved) { App.toast('History saving is off — these answers will be lost when you leave the page.', 'info'); }
        }).catch(function (e) {
            S.scanning = false;
            busy(false);
            showError(e.message + (e.data && e.data.notes ? ' (' + e.data.notes + ')' : ''));
        });
    });

    ui.rescan.addEventListener('click', function () {
        ui.result.hidden = true;
        ui.capture.scrollIntoView({ block: 'start', behavior: 'smooth' });
        ui.camOpen.focus();
    });

    // ================================================================ document header

    function renderDoc() {
        if (!S.doc) { ui.doc.hidden = true; return; }
        ui.doc.hidden = false;
        ui.docTitle.textContent = S.doc.title || S.doc.type_label;
        var bits = [S.doc.type_label, S.doc.pages + ' page' + (S.doc.pages === 1 ? '' : 's')];
        if (S.doc.instructions_text) { bits.push(S.doc.instructions_text); }
        ui.docMeta.textContent = bits.join(' · ');
        ui.docNote.hidden = !S.doc.notes;
        ui.docNote.textContent = S.doc.notes ? 'ⓘ ' + S.doc.notes : '';
    }

    // ================================================================ question list

    function answeredCount() {
        return S.questions.filter(function (q) { return !!q.answer; }).length;
    }

    function paintCount() {
        var n = S.questions.length;
        var done = answeredCount();
        ui.count.textContent = S.progress || (n === 0 ? 'No questions yet'
            : done + ' of ' + n + ' question' + (n === 1 ? '' : 's') + ' answered');
        ui.answerAll.hidden = S.runningAll || done >= n;
        ui.answerAll.textContent = done === 0 ? 'Answer all' : 'Answer the rest';
        ui.copyAll.hidden = done === 0;
    }

    /** One list item, rebuilt whenever that question changes. */
    function renderItem(q, index) {
        var li = App.el('li', { 'class': 'sc-item', id: 'sc-q-' + index });
        if (q.answer) { li.setAttribute('data-answered', ''); }

        var meta = App.el('p', { 'class': 'question-meta' });
        if (q.marks) { meta.appendChild(App.el('span', { 'class': 'badge', text: q.marks })); }
        meta.appendChild(App.el('span', { 'class': 'badge badge-primary', text: R.typeLabel(q.question_type) }));
        if (q.answer) { meta.appendChild(App.el('span', { 'class': 'badge', text: R.modeLabel(q.answer.answer_mode) + ' answer' })); }

        li.appendChild(App.el('div', { 'class': 'sc-item-head' }, [
            App.el('span', { 'class': 'sc-num', text: q.number || String(index + 1) }),
            App.el('div', { 'class': 'sc-q' }, [App.el('p', { 'class': 'question-text', text: q.text }), meta])
        ]));

        var answerBtn = App.el('button', { type: 'button', 'class': 'btn btn-primary btn-sm' });
        answerBtn.appendChild(document.createTextNode(q.answer ? 'Regenerate' : 'Answer this'));
        answerBtn.addEventListener('click', function () { answerOne(index, answerBtn); });

        var editBtn = App.el('button', { type: 'button', 'class': 'btn btn-ghost btn-sm', text: 'Edit question' });
        var actions = App.el('div', { 'class': 'sc-item-actions' }, [answerBtn, editBtn]);
        if (q.answer) {
            var copyBtn = App.el('button', { type: 'button', 'class': 'btn btn-ghost btn-sm', text: 'Copy' });
            copyBtn.addEventListener('click', function () {
                App.copyText(R.answerToText(q.text, q.answer)).then(function () { App.toast('Answer copied', 'success'); });
            });
            actions.appendChild(copyBtn);
        }
        li.appendChild(actions);

        // --- inline edit
        var edit = App.el('form', { 'class': 'sc-edit', hidden: 'hidden' });
        var ta = App.el('textarea', { rows: '3', maxlength: '1200', 'aria-label': 'Question text' });
        ta.value = q.text;
        var numIn = App.el('input', { type: 'text', maxlength: '20', 'class': 'sc-num-input', placeholder: 'No.', 'aria-label': 'Question number' });
        numIn.value = q.number;
        var save = App.el('button', { type: 'submit', 'class': 'btn btn-primary btn-sm', text: 'Save question' });
        var cancel = App.el('button', { type: 'button', 'class': 'btn btn-ghost btn-sm', text: 'Cancel' });
        edit.appendChild(App.el('div', { 'class': 'sc-edit-row' }, [numIn, ta]));
        edit.appendChild(App.el('div', { 'class': 'btn-row' }, [save, cancel]));
        cancel.addEventListener('click', function () { edit.hidden = true; editBtn.focus(); });
        editBtn.addEventListener('click', function () {
            edit.hidden = !edit.hidden;
            if (!edit.hidden) { ta.focus(); }
        });
        edit.addEventListener('submit', function (e) {
            e.preventDefault();
            saveQuestion(index, ta.value, numIn.value, save);
        });
        li.appendChild(edit);

        var box = App.el('div', { 'class': 'card answer-card sc-answer' });
        box.hidden = !q.answer && !q.busy && !q.error;
        if (q.error) {
            box.appendChild(App.el('p', { 'class': 'alert alert-error', text: q.error }));
        } else if (q.busy) {
            box.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Preparing answer…' })]));
            box.appendChild(R.skeleton());
        } else if (q.answer) {
            box.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Suggested answer' })]));
            box.appendChild(R.renderAnswer(q.answer));
        }
        li.appendChild(box);
        return li;
    }

    function renderList() {
        ui.list.textContent = '';
        S.questions.forEach(function (q, i) { ui.list.appendChild(renderItem(q, i)); });
        if (S.questions.length) { ui.list.appendChild(addQuestionRow()); }
        paintCount();
    }

    /** Replace one item in place, so answering question 7 doesn't rebuild the whole list. */
    function refreshItem(index) {
        var old = $('sc-q-' + index);
        if (old) { old.replaceWith(renderItem(S.questions[index], index)); }
        paintCount();
    }

    function addQuestionRow() {
        var li = App.el('li', { 'class': 'sc-item sc-add' });
        var form = App.el('form', { 'class': 'sc-edit' });
        var ta = App.el('textarea', { rows: '2', maxlength: '1200', placeholder: 'Type a question the scan missed…', 'aria-label': 'Add a question' });
        var numIn = App.el('input', { type: 'text', maxlength: '20', 'class': 'sc-num-input', placeholder: 'No.', 'aria-label': 'Question number' });
        var add = App.el('button', { type: 'submit', 'class': 'btn btn-outline btn-sm', text: 'Add question' });
        form.appendChild(App.el('div', { 'class': 'sc-edit-row' }, [numIn, ta]));
        form.appendChild(App.el('div', { 'class': 'btn-row' }, [add]));
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            saveQuestion(-1, ta.value, numIn.value, add);
        });
        li.appendChild(form);
        return li;
    }

    /** index -1 adds a new question; otherwise the question at that index is corrected. */
    function saveQuestion(index, text, number, btn) {
        text = (text || '').trim().replace(/\s+/g, ' ');
        if (text.length < 8) { App.toast('Please enter the full question.', 'error'); return; }
        var existing = index >= 0 ? S.questions[index] : null;
        if (existing && text === existing.text && (number || '') === existing.number) {
            renderList();
            return;
        }
        var restore = App.busy(btn, 'Saving…');
        var body = { session_id: S.sessionId, text: text, number: (number || '').trim() };
        if (existing && existing.id) { body.question_id = existing.id; }

        var done = function (id) {
            restore();
            if (existing) {
                existing.id = id;
                existing.text = text;
                existing.number = (number || '').trim();
                existing.answer = null;          // the stored answer no longer matches the question
                existing.error = '';
                refreshItem(index);
            } else {
                S.questions.push(normQ({ id: id, text: text, number: (number || '').trim() }));
                renderList();
                var last = $('sc-q-' + (S.questions.length - 1));
                if (last) { last.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
            }
        };

        if (!S.sessionId || !cfg.saveHistory) {
            done(existing ? existing.id : null);
            return;
        }
        App.api('api/scan-question.php', { json: body }).then(function (d) {
            done(d.id);
        }).catch(function (e) { restore(); App.toast(e.message, 'error'); });
    }

    // ================================================================ answering

    function answerOne(index, btn) {
        var q = S.questions[index];
        if (!q || q.busy || !S.sessionId) { return Promise.resolve(false); }
        q.busy = true;
        q.error = '';
        refreshItem(index);
        if (btn) { btn.disabled = true; }

        var body = { session_id: S.sessionId, mode: ui.mode.value, source: 'scan' };
        if (q.id) { body.question_id = q.id; } else { body.transcript = q.text; }

        var streamed = '';
        var frame = null;
        var onStream = function (name, data) {
            if (name !== 'delta') { return; }
            streamed += data.t || '';
            if (frame) { return; }
            frame = (window.requestAnimationFrame || setTimeout)(function () {
                frame = null;
                if (q.busy) { paintPartial(index, App.parsePartialJson(streamed)); }
            });
        };

        var call = App.canStream()
            ? App.apiStream('api/generate-answer.php', body, onStream, 150000)
            : App.api('api/generate-answer.php', { json: body, timeout: 150000 });

        return call.then(function (d) {
            q.busy = false;
            if (!d.is_question) {
                q.error = "That doesn't look like a question — edit it and try again.";
            } else {
                q.answer = d.answer;
                q.question_type = d.answer.question_type;
                if (d.question_id) { q.id = d.question_id; }
            }
            refreshItem(index);
            return !q.error;
        }).catch(function (e) {
            q.busy = false;
            q.error = e.message;
            refreshItem(index);
            if (e.status === 404 || e.status === 409) {
                S.sessionId = null;
                ui.end.hidden = true;
            }
            return false;
        });
    }

    /** Show an answer while it is still streaming in. */
    function paintPartial(index, partial) {
        if (!partial || partial.is_question !== true) { return; }
        if (!partial.key_message && !(partial.sections && partial.sections.length) && !(partial.points && partial.points.length)) { return; }
        var li = $('sc-q-' + index);
        var box = li && li.querySelector('.sc-answer');
        if (!box) { return; }
        box.hidden = false;
        box.setAttribute('data-streaming', '');
        box.textContent = '';
        box.appendChild(App.el('div', { 'class': 'answer-title' }, [App.el('h2', { text: 'Suggested answer' })]));
        box.appendChild(R.renderAnswer(partial));
    }

    ui.answerAll.addEventListener('click', function () {
        if (S.runningAll) { return; }
        S.runningAll = true;
        S.abortAll = false;
        ui.stopAll.hidden = false;
        paintCount();

        var pending = [];
        S.questions.forEach(function (q, i) { if (!q.answer) { pending.push(i); } });
        var failures = 0;

        pending.reduce(function (chain, i) {
            return chain.then(function () {
                if (S.abortAll) { return; }
                var li = $('sc-q-' + i);
                if (li) { li.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
                S.progress = 'Answering question ' + (i + 1) + ' of ' + S.questions.length + '…';
                paintCount();
                return answerOne(i, null).then(function (ok) { if (!ok) { failures++; } });
            });
        }, Promise.resolve()).then(function () {
            S.runningAll = false;
            S.progress = '';
            ui.stopAll.hidden = true;
            paintCount();
            if (S.abortAll) {
                App.toast('Stopped. ' + answeredCount() + ' of ' + S.questions.length + ' answered.', 'info');
            } else if (failures) {
                App.toast(failures + ' question' + (failures === 1 ? '' : 's') + ' could not be answered. Try those again.', 'error');
            } else {
                App.toast('All ' + S.questions.length + ' questions answered.', 'success');
            }
        });
    });

    ui.stopAll.addEventListener('click', function () {
        S.abortAll = true;
        ui.stopAll.disabled = true;
        S.progress = 'Finishing the current question…';
        paintCount();
        setTimeout(function () { ui.stopAll.disabled = false; }, 2000);
    });

    ui.copyAll.addEventListener('click', function () {
        var out = [];
        if (S.doc) { out.push((S.doc.title || S.doc.type_label).toUpperCase(), ''); }
        S.questions.forEach(function (q, i) {
            if (!q.answer) { return; }
            out.push('── ' + (q.number || String(i + 1)) + ' ' + (q.marks ? '(' + q.marks + ')' : ''), '');
            out.push(R.answerToText(q.text, q.answer), '');
        });
        App.copyText(out.join('\n').trim()).then(function () {
            App.toast(answeredCount() + ' answers copied', 'success');
        });
    });

    ui.end.addEventListener('click', function () {
        if (!S.sessionId) { return; }
        if (!window.confirm('Finish this paper? It will be saved to your history.')) { return; }
        var restore = App.busy(ui.end, 'Saving…');
        App.api('api/end-session.php', { json: { session_id: S.sessionId } }).then(function (d) {
            restore();
            S.sessionId = null;
            ui.end.hidden = true;
            App.toast(d.questions + ' question' + (d.questions === 1 ? '' : 's') + ' saved to history', 'success');
            window.location.href = App.url('history.php');
        }).catch(function (e) { restore(); App.toast(e.message, 'error'); });
    });

    ui.job.addEventListener('change', function () {
        if (!S.sessionId) { return; }
        App.toast('The new target job applies to the next paper you scan.', 'info');
    });

    window.addEventListener('pagehide', closeCamera);

    // ================================================================ init
    if (!window.PageCamera.supported()) {
        ui.camOpen.disabled = true;
        ui.camOpen.title = window.isSecureContext ? 'This browser cannot use the camera' : 'Camera access requires HTTPS';
    }
    renderPages();
    if (S.questions.length) {
        if (cfg.sessionTitle) {
            ui.doc.hidden = false;
            ui.docTitle.textContent = cfg.sessionTitle;
            ui.docMeta.textContent = 'Picking up where you left off · ' + S.questions.length + ' question' + (S.questions.length === 1 ? '' : 's');
        }
        renderList();
        ui.result.hidden = false;
    }
})();
