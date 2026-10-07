<article class="card prose">
    <p class="eyebrow">Legal</p>
    <h1>Privacy policy</h1>
    <p class="muted">This notice explains what <?= e(config('app.name')) ?> collects, why, and the controls you have. The operator of this installation should review and adapt it to their jurisdiction.</p>

    <h2>What we collect</h2>
    <ul>
        <li><strong>Account details</strong> — name, email address and a securely hashed password (never stored in plain text).</li>
        <li><strong>Your CV</strong> — the file you upload, the text extracted from it and a compact structured profile (skills, roles, achievements).</li>
        <li><strong>Job details</strong> — job titles, companies and job descriptions you enter.</li>
        <li><strong>Interview history</strong> — transcribed questions and the suggested answers (you can turn history off in Settings).</li>
        <li><strong>Scanned questions</strong> — the question text read off a page you scan, and the answers produced for it. The page images themselves are not kept.</li>
        <li><strong>Usage data</strong> — the number of AI tokens used per request for cost tracking, and security logs (e.g. failed sign-ins). Passwords, API keys and CV content are not written to logs.</li>
    </ul>

    <h2>Microphone and audio</h2>
    <ul>
        <li>Microphone access is used to transcribe interview questions (and practice answers in Practice Mode).</li>
        <li>The microphone is only active after you press <strong>Listen</strong>. A visible indicator and the text “Listening…” are shown the whole time. We never record secretly.</li>
        <li>Live mode streams audio directly from your browser to OpenAI for transcription using a short-lived token. Recorded mode uploads a short clip that is deleted from our server immediately after transcription.</li>
        <li>We do not store audio recordings.</li>
    </ul>

    <h2>Camera and scanned pages</h2>
    <ul>
        <li>Camera access is used only on the Scan page, and only while the camera preview is open. Nothing is captured until you press <strong>Capture page</strong>.</li>
        <li>Page images (captured or uploaded) are sent to OpenAI to read the questions printed on them. They are held only for the length of that request and are never written to our storage. A scanned PDF uploaded to OpenAI for reading is deleted immediately afterwards.</li>
        <li>We do not store page images or photographs.</li>
    </ul>

    <h2>How data is processed</h2>
    <p>CV content, job descriptions, questions, audio and scanned page images are sent to OpenAI to provide transcription and answer guidance. Requests are made with storage disabled where the API supports it. CV files uploaded to OpenAI for analysis are deleted when you delete or replace your CV. See OpenAI's own policies for how they handle API data.</p>

    <h2>Storage and security</h2>
    <ul>
        <li>CV files are stored outside the public web folder with random filenames and are only served to you after sign-in.</li>
        <li>Passwords are hashed with <code>password_hash()</code>; sessions are protected with secure cookies, CSRF tokens and inactivity timeouts.</li>
        <li>The OpenAI API key stays on the server and is never sent to your browser.</li>
    </ul>

    <h2>Your controls</h2>
    <ul>
        <li>Delete your CV, individual interview sessions or scanned papers, or all history at any time.</li>
        <li>Delete your account from Settings — this permanently removes your account, files and all associated records.</li>
        <li>Disable interview history saving in Settings.</li>
    </ul>

    <h2>Contact</h2>
    <p>For privacy questions, contact the operator of this service<?= config('app.mail.from') ? ' at ' . e(config('app.mail.from')) : '' ?>.</p>
</article>
