<section class="hero">
    <div class="hero-copy">
        <p class="eyebrow"><?= icon('sparkles') ?> CV-aware interview guidance</p>
        <h1>Glanceable answer points, grounded in <span class="text-primary">your real experience</span>.</h1>
        <p class="lead">Upload your CV, add the job description, then press <strong>Listen</strong>. The interviewer's question is
            transcribed and turned into concise talking points you can read in seconds — never invented achievements.</p>
        <div class="btn-row">
            <a class="btn btn-primary btn-lg" href="<?= e(url('register.php')) ?>">Create free account <?= icon('arrow-right') ?></a>
            <a class="btn btn-outline btn-lg" href="<?= e(url('login.php')) ?>">Sign in</a>
        </div>
        <p class="hint">Use only where external assistance or transcription is permitted by the interviewer, employer, platform rules, or applicable requirements.</p>
    </div>
    <div class="hero-demo card" aria-hidden="true">
        <p class="answer-label">Question</p>
        <p class="demo-question">“Tell us about a time you managed a difficult team member.”</p>
        <div class="answer-section"><h3>Situation</h3><ul><li>Team member repeatedly missing agreed deadlines.</li></ul></div>
        <div class="answer-section"><h3>Action</h3><ul><li>Private one-to-one to understand blockers.</li><li>Agreed clear milestones + weekly check-ins.</li></ul></div>
        <div class="answer-section"><h3>Result</h3><ul><li>Delivery improved; project completed on time.</li></ul></div>
        <div class="chip-row"><span class="chip">Accountability</span><span class="chip">Feedback</span><span class="chip">Communication</span></div>
    </div>
</section>

<section class="features">
    <div class="feature card"><?= icon('file') ?><h2>Understands your CV</h2><p>Your CV is converted into a compact profile, so answers cite real roles, skills and results.</p></div>
    <div class="feature card"><?= icon('briefcase') ?><h2>Matches the job</h2><p>Questions are mapped against required skills and competencies in the job description.</p></div>
    <div class="feature card"><?= icon('zap') ?><h2>Built for speed</h2><p>Live transcription, instant question detection and short structured points — STAR, technical or leadership.</p></div>
    <div class="feature card"><?= icon('shield') ?><h2>Private by design</h2><p>Your API usage stays server-side, CV files are never publicly accessible, and the microphone is always visibly on or off.</p></div>
</section>
