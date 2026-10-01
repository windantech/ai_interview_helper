/* =====================================================================
   Answer + feedback renderers (DOM-built, never innerHTML of AI text).
   Mirrors views/components/answer-card.php
   ===================================================================== */
(function () {
    'use strict';
    var el = function () { return window.App.el.apply(null, arguments); };

    var TYPE_LABELS = {
        behavioural: 'Behavioural', technical: 'Technical', situational: 'Situational', leadership: 'Leadership',
        competency: 'Competency', motivation: 'Motivation', career_history: 'Career history', salary_hr: 'Salary / HR',
        problem_solving: 'Problem solving', management: 'Management', communication: 'Communication', general: 'General', unknown: 'Unknown'
    };
    var MODE_LABELS = { quick: 'Quick', star: 'STAR', technical: 'Technical', leadership: 'Leadership', general: 'General', auto: 'Auto' };

    function list(items, cls) {
        var ul = el('ul', cls ? { 'class': cls } : null);
        (items || []).forEach(function (t) { ul.appendChild(el('li', { text: t })); });
        return ul;
    }

    /** Build the answer DOM. */
    function renderAnswer(a) {
        var root = el('div', { 'class': 'answer' });
        if (a.key_message) {
            root.appendChild(el('div', { 'class': 'key-message' }, [
                el('p', { 'class': 'answer-label', text: 'Main point' }),
                el('p', { text: a.key_message })
            ]));
        }
        if (a.sections && a.sections.length) {
            var secs = el('div', { 'class': 'answer-sections' });
            a.sections.forEach(function (s) {
                secs.appendChild(el('div', { 'class': 'answer-section' }, [el('h3', { text: s.label }), list(s.bullets)]));
            });
            root.appendChild(secs);
        } else if (a.points && a.points.length) {
            root.appendChild(list(a.points, 'answer-points'));
        }
        if (a.cv_evidence && a.cv_evidence.length) {
            root.appendChild(el('div', { 'class': 'evidence' }, [
                el('p', { 'class': 'answer-label', text: 'From your CV' }),
                list(a.cv_evidence)
            ]));
        }
        if (a.evidence_note) {
            root.appendChild(el('p', { 'class': 'evidence-note', text: 'ⓘ ' + a.evidence_note }));
        }
        if (a.closing_line) {
            root.appendChild(el('div', { 'class': 'closing' }, [
                el('p', { 'class': 'answer-label', text: 'Close with' }),
                el('p', { text: '“' + a.closing_line + '”' })
            ]));
        }
        if (a.keywords && a.keywords.length) {
            var chips = el('div', { 'class': 'chip-row' });
            a.keywords.forEach(function (k) { chips.appendChild(el('span', { 'class': 'chip chip-key', text: k })); });
            root.appendChild(el('div', { 'class': 'keywords' }, [el('p', { 'class': 'answer-label', text: 'Keywords' }), chips]));
        }
        return root;
    }

    /** Plain-text version for copying. */
    function answerToText(question, a) {
        var out = [];
        if (question) { out.push('Q: ' + question, ''); }
        if (a.key_message) { out.push('MAIN POINT: ' + a.key_message, ''); }
        if (a.sections && a.sections.length) {
            a.sections.forEach(function (s) {
                out.push(s.label.toUpperCase());
                s.bullets.forEach(function (b) { out.push('• ' + b); });
                out.push('');
            });
        } else {
            (a.points || []).forEach(function (p) { out.push('• ' + p); });
            out.push('');
        }
        if (a.cv_evidence && a.cv_evidence.length) {
            out.push('FROM YOUR CV');
            a.cv_evidence.forEach(function (p) { out.push('• ' + p); });
            out.push('');
        }
        if (a.closing_line) { out.push('CLOSE WITH: "' + a.closing_line + '"'); }
        if (a.keywords && a.keywords.length) { out.push('KEYWORDS: ' + a.keywords.join(', ')); }
        return out.join('\n').trim();
    }

    function renderFeedback(f) {
        var root = el('div', { 'class': 'feedback' });
        root.appendChild(el('div', { 'class': 'fb-score' }, [
            el('span', { 'class': 'score-num', text: String(f.score) }),
            el('span', { 'class': 'muted', text: '/10' }),
            el('p', { text: f.summary || '' })
        ]));
        var block = function (title, items, cls) {
            if (!items || !items.length) { return; }
            root.appendChild(el('div', { 'class': 'fb-block ' + (cls || '') }, [el('h3', { text: title }), list(items)]));
        };
        block('Strengths', f.strengths, 'fb-good');
        block('Missing points', f.missing_points, 'fb-miss');
        block('Better structure', f.better_structure);
        if (f.improved_answer) {
            root.appendChild(el('div', { 'class': 'fb-block' }, [el('h3', { text: 'Example improved answer' }), el('p', { 'class': 'pre-line', text: f.improved_answer })]));
        }
        return root;
    }

    function skeleton() {
        return el('div', { 'class': 'skeleton', 'aria-hidden': 'true' }, [el('span'), el('span'), el('span'), el('span')]);
    }

    window.AnswerRender = {
        renderAnswer: renderAnswer,
        renderFeedback: renderFeedback,
        answerToText: answerToText,
        skeleton: skeleton,
        typeLabel: function (t) { return TYPE_LABELS[t] || t; },
        modeLabel: function (m) { return MODE_LABELS[m] || m; }
    };
})();
