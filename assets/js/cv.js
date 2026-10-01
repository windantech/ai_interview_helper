/* CV upload page: drag & drop, client-side checks, upload, delete, retry. */
(function () {
    'use strict';
    var App = window.App;
    var $ = function (id) { return document.getElementById(id); };
    var input = $('cv-input');
    if (!input) { return; }

    var dz = $('dropzone');
    var progress = $('cv-progress');
    var alertBox = $('cv-alert');
    var maxSize = parseInt(input.getAttribute('data-max-size'), 10) || 10485760;
    var allowed = ['pdf', 'doc', 'docx', 'txt'];
    var uploading = false;

    function showAlert(msg) {
        alertBox.textContent = msg;
        alertBox.classList.toggle('is-hidden', !msg);
    }

    function setProgress(on, title, sub) {
        progress.classList.toggle('is-hidden', !on);
        if (title) { $('cv-progress-title').textContent = title; }
        if (sub) { $('cv-progress-sub').textContent = sub; }
    }

    function fmt(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
    }

    function upload(file) {
        if (uploading || !file) { return; }
        showAlert('');
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (allowed.indexOf(ext) === -1) {
            showAlert('Unsupported format. Please upload a PDF, DOC, DOCX or TXT file.');
            return;
        }
        if (file.size > maxSize) {
            showAlert('This file is ' + fmt(file.size) + '. The maximum size is ' + fmt(maxSize) + '.');
            return;
        }
        if (file.size === 0) { showAlert('This file is empty.'); return; }

        uploading = true;
        dz.setAttribute('aria-disabled', 'true');
        setProgress(true, 'Uploading ' + file.name + '…', 'Reading your CV and building your candidate profile. This can take up to a minute.');
        var form = new FormData();
        form.append('cv', file, file.name);
        var t = setTimeout(function () { setProgress(true, 'Analysing your CV…'); }, 2500);

        App.api('api/upload-cv.php', { form: form, timeout: 180000 }).then(function () {
            clearTimeout(t);
            setProgress(true, '✓ CV uploaded successfully', 'Refreshing…');
            window.location.reload();
        }).catch(function (e) {
            clearTimeout(t);
            uploading = false;
            dz.removeAttribute('aria-disabled');
            setProgress(false);
            showAlert(e.message);
            if (e.data && e.data.cv_saved) { setTimeout(function () { window.location.reload(); }, 2500); }
        });
    }

    input.addEventListener('change', function () {
        if (input.files && input.files[0]) { upload(input.files[0]); }
        input.value = '';
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
        dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-drag'); });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
        dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-drag'); });
    });
    dz.addEventListener('drop', function (e) {
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) { upload(f); }
    });
    // Keyboard: Enter/Space on the dropzone opens the file picker.
    dz.setAttribute('tabindex', '0');
    dz.setAttribute('role', 'button');
    dz.setAttribute('aria-label', 'Upload CV: drag and drop or choose a file');
    dz.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });

    var del = $('cv-delete');
    if (del) {
        del.addEventListener('click', function () {
            if (!window.confirm('Delete your CV and its extracted profile? This cannot be undone.')) { return; }
            var restore = App.busy(del, 'Deleting…');
            App.api('api/delete-cv.php', { json: {} }).then(function () {
                window.location.reload();
            }).catch(function (e) { restore(); showAlert(e.message); });
        });
    }

    var retry = $('cv-retry');
    if (retry) {
        retry.addEventListener('click', function () {
            var restore = App.busy(retry, 'Analysing…');
            setProgress(true, 'Analysing your CV…');
            App.api('api/reprocess-cv.php', { json: {}, timeout: 180000 }).then(function () {
                window.location.reload();
            }).catch(function (e) { restore(); setProgress(false); showAlert(e.message); });
        });
    }
})();
