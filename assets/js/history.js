/* History page: delete a session or all history. */
(function () {
    'use strict';
    var App = window.App;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-delete-session]');
        if (!btn) { return; }
        if (!window.confirm('Delete this interview session and all its questions?')) { return; }
        var id = parseInt(btn.getAttribute('data-delete-session'), 10);
        var redirect = btn.getAttribute('data-redirect');
        var restore = App.busy(btn, '');
        App.api('api/delete-session.php', { json: { session_id: id } }).then(function () {
            if (redirect) { window.location.href = redirect; return; }
            var row = btn.closest('tr');
            if (row) { row.remove(); }
            App.toast('Session deleted', 'success');
            if (!document.querySelector('[data-session-id]')) { window.location.reload(); }
        }).catch(function (err) { restore(); App.toast(err.message, 'error'); });
    });

    var all = document.getElementById('delete-all-history');
    if (all) {
        all.addEventListener('click', function () {
            if (!window.confirm('Delete ALL interview history? This cannot be undone.')) { return; }
            var restore = App.busy(all, 'Deleting…');
            App.api('api/delete-session.php', { json: { all: true } }).then(function (d) {
                App.toast('Deleted ' + d.deleted + ' session(s)', 'success');
                window.location.href = App.url('history.php');
            }).catch(function (err) { restore(); App.toast(err.message, 'error'); });
        });
    }
})();
