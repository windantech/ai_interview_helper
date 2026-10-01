/* Jobs page: create/edit/delete/select target jobs. */
(function () {
    'use strict';
    var App = window.App;
    var alertBox = document.getElementById('jobs-alert');

    function showAlert(msg, type) {
        alertBox.className = 'alert alert-' + (type || 'error');
        alertBox.textContent = msg;
        alertBox.classList.toggle('is-hidden', !msg);
        if (msg) { alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }

    var form = document.getElementById('job-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            showAlert('');
            Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) { el.removeAttribute('aria-invalid'); });

            var data = {};
            new FormData(form).forEach(function (v, k) { data[k] = typeof v === 'string' ? v.trim() : v; });
            if (!data.title) { return invalid('title', 'Job title is required.'); }
            if (!data.description || data.description.length < 30) { return invalid('description', 'Please paste the job description (at least 30 characters).'); }

            var btn = document.getElementById('job-save');
            var restore = App.busy(btn, 'Saving…');
            App.api('api/save-job.php', { json: data }).then(function () {
                window.location.href = App.url('jobs.php');
            }).catch(function (err) {
                restore();
                var errors = err.data && err.data.errors;
                if (errors) {
                    Object.keys(errors).forEach(function (k) {
                        var el = form.querySelector('[name="' + k + '"]');
                        if (el) { el.setAttribute('aria-invalid', 'true'); }
                    });
                }
                showAlert(err.message);
            });
        });
    }

    function invalid(name, msg) {
        var el = form.querySelector('[name="' + name + '"]');
        if (el) { el.setAttribute('aria-invalid', 'true'); el.focus(); }
        showAlert(msg);
    }

    var sample = document.getElementById('add-sample');
    if (sample) {
        sample.addEventListener('click', function () {
            var restore = App.busy(sample, 'Adding…');
            App.api('api/save-job.php', { json: { sample: 1 } }).then(function () {
                window.location.href = App.url('jobs.php');
            }).catch(function (e) { restore(); showAlert(e.message); });
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.job-card [data-action]');
        if (!btn) { return; }
        var card = btn.closest('.job-card');
        var id = parseInt(card.getAttribute('data-job-id'), 10);
        var action = btn.getAttribute('data-action');

        if (action === 'select') {
            var restore = App.busy(btn, 'Selecting…');
            App.api('api/select-job.php', { json: { id: id } }).then(function () {
                window.location.reload();
            }).catch(function (err) { restore(); showAlert(err.message); });
        }
        if (action === 'delete') {
            var title = card.querySelector('h3').textContent;
            if (!window.confirm('Delete "' + title + '"? Interview history for this job is kept.')) { return; }
            var restoreDel = App.busy(btn, 'Deleting…');
            App.api('api/delete-job.php', { json: { id: id } }).then(function () {
                card.remove();
                App.toast('Job deleted', 'success');
                if (!document.querySelector('.job-card')) { window.location.reload(); }
            }).catch(function (err) { restoreDel(); showAlert(err.message); });
        }
    });
})();
