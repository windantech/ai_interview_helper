# AI Interview Copilot

A mobile-first PHP + MySQL web app that answers interview questions from your own CV. It can **listen** to an interviewer and show **concise, CV-grounded talking points** you can read in 5–10 seconds (STAR, technical, leadership or quick bullets), or **scan a whole question paper with your camera**, pull every question off it and answer each one.

> **Interview-use notice:** Use this assistant only where external assistance or transcription is permitted by the interviewer, employer, platform rules, or applicable requirements.

---

## Contents

1. [Features](#features)
2. [Scanning a question paper](#scanning-a-question-paper)
3. [How it works](#how-it-works)
4. [Requirements](#requirements)
5. [Local installation](#local-installation)
6. [Configuration (.env)](#configuration-env)
7. [Adding your OpenAI API key](#adding-your-openai-api-key)
8. [cPanel / shared hosting deployment](#cpanel--shared-hosting-deployment)
9. [HTTPS, camera and microphone access](#https-camera-and-microphone-access)
10. [Directory structure](#directory-structure)
11. [API endpoints](#api-endpoints)
12. [Security](#security)
13. [Testing](#testing)
14. [Troubleshooting](#troubleshooting)
15. [Known limitations](#known-limitations)

---

## Features

- **Accounts:** register, login, logout, forgot/reset password, session timeout, CSRF protection, rate limiting.
- **CV upload (PDF, DOC, DOCX, TXT, up to 10 MB):** drag and drop, replace, delete, download, and a view of the extracted profile. CV files are stored privately in `/storage/users/{id}/cv/`. They are never placed in a public folder.
- **CV understanding:** text is extracted, then OpenAI builds a compact candidate profile (`cv_profile_json`) with roles, skills, achievements and leadership. Interviews send this profile instead of the full CV, which saves tokens.
- **Target jobs:** title, company, industry, location, job description, main skills, interview type and seniority. You can save several jobs and select one. The job description is analysed once and cached.
- **Live interview screen:**
  - A large **Listen** button with clear states: Ready → Listening (pulsing red with waveform) → Processing → Answer ready → Error.
  - **Live transcription:** OpenAI Realtime (`gpt-live-transcribe`) over WebRTC, using a short-lived ephemeral key.
  - **Automatic fallback** to recorded mode (MediaRecorder → `gpt-transcribe`) if live transcription is unavailable.
  - **Question detection:** small talk ("Okay, thank you very much.") and company background are ignored, and the app keeps listening. A question split by a pause is joined back together.
  - **Answer modes:** Auto, Quick, STAR, Technical and Leadership. Answers follow a senior speaking format: open with the approach, 3–4 signposted points ("First… Second… Finally…"), one named example from your CV and how you'd validate it, with no repetition or filler words. Technical questions use a fitting framework (debugging: reproduce → measure → isolate → fix → validate; system design: architecture → security → scalability → observability; code review: correctness → security → maintainability → tests). Auto picks the structure from the question type (13 types).
  - **CV evidence vs. approach:** facts from your CV are shown separately from the suggested approach, and the model is instructed never to invent experience.
  - The transcript is shown first, then the answer **streams in section by section** while it is written. Every line is a complete first-person sentence you can read aloud; job keywords are bold and any `[fill-in]` gaps are highlighted.
  - **Your instructions for this interview:** an optional panel where you tell the AI what to use, e.g. *"When asked for a sample project, use finKAP — I built the loan module and integrated M-Pesa."* They are sent with every question, can be edited mid-interview, are carried over to your next interview for the same job, and are shown in History.
  - **Type question instead** uses the same pipeline. You can ask several questions per session, change the answer mode to regenerate, copy the answer, or end the interview.
- **Scan a question paper (camera or file):** photograph a sheet of questions — a printed interview question list, an application form, an essay or exam paper, an assignment — and the app reads every question off it and answers them. See [Scanning a question paper](#scanning-a-question-paper).
- **History:** sessions with date, job, company, question count and duration. You can search and filter by job, date or type (interview, practice or scanned paper), open a session to see every Q&A, and delete one session or all history.
- **Practice mode:** the AI asks tailored questions one at a time. Speak or type your answer, or skip. You get a score, strengths, missing points, a better structure, delivery tips (repetition, opening with your approach, precision), the filler words you used with counts, likely mis-heard phrases (a sign you're speaking too fast), and an improved example answer. "Show approach" shows the suggested answer for that question.
- **Settings:** profile, password, default answer style, short/medium detail, default interview type, transcription mode, auto-detect, show transcript, save history, AI usage and estimated cost, and deleting your CV, your history or your account (which removes all rows and files).
- **Privacy & Terms pages**, a consent dialog before the microphone is first used, and a visible "Microphone on" indicator whenever it is.

## Scanning a question paper

Instead of listening to one question at a time, you can point your camera at a page of questions. `scan.php` captures the pages, one OpenAI vision call reads **all of them together**, and every question it finds becomes an answerable item.

**What it handles**

| Kind of paper | What you get |
|---|---|
| A printed list of interview questions | Spoken talking points, the same as the live interview screen |
| An application form ("Why do you want to work here?") | Written paragraphs you can paste into the form |
| An essay or exam paper | Written paragraphs, sized to the marks or word limit printed with each question |
| An assignment brief | Written paragraphs |

**Flow**

1. **Capture.** Use the rear camera (live preview, one tap per page) or choose image files or a PDF. Up to 8 pages per scan (`MAX_SCAN_PAGES`); camera frames are downscaled to 2000 px and re-encoded as JPEG in the browser, so uploads stay small and the text stays readable.
2. **Scan.** All pages go to `POST /v1/responses` in a single call with `input_image` data URLs (a PDF goes through the Files API as an `input_file` and is deleted again straight away). Multi-page papers keep their numbering and any question continued across a page break. The model returns strict JSON: the document type, its title, paper-wide directions ("Answer any three questions"), notes, and the questions with their printed numbers and marks.
3. **Review.** Every question is listed with its number, marks and type. OCR is not perfect, so you can **edit** any question or **add** one the scan missed. Editing a question clears the answer stored for it.
4. **Answer.** Answer one question, or **Answer all** and watch each answer stream in; **Stop** halts after the current one. Answers reuse the same engine as the live interview — your CV profile, the target job, your own instructions, and the questions already answered on this paper (so it does not reuse the same example twice).
5. **Keep.** A scan is its own session in History, named after the paper. **Copy all** puts every answer on the clipboard; **Finish paper** closes the session. A part-answered paper is picked up where you left off next time you open the scan page.

**Answer style** — `Auto` reads the paper and decides: spoken talking points for an interview question list, written prose for anything you have to write on. You can force it with `Written`, `Quick`, `STAR` or `Technical`.

**Written answers** differ from spoken ones: each section is a full paragraph of flowing first-person prose rather than lines to read aloud, paragraphs are not clipped to the spoken length limit, and the answer respects a printed word limit or mark allocation (roughly 120–150 words per 10 marks). The same anti-fabrication rules apply — nothing is invented beyond your CV and your own instructions.

**Privacy** — page images are held in memory for the request, sent to OpenAI to be read, and never written to `storage/`. Only the extracted question text and its answer are stored.

> The interview-use notice applies here too: scan only what you are permitted to.

## How it works

```
Browser (mobile/desktop)                     PHP (server)                         OpenAI
────────────────────────                     ────────────                         ──────
[Listen] ──POST /api/realtime-session.php──▶ mints ephemeral key ───────────────▶ POST /v1/realtime/client_secrets
         ◀── { client_secret: "ek_..." } ──  (OPENAI_API_KEY stays here)
WebRTC SDP offer ─────────────────────────────────────────────────────────────▶ POST /v1/realtime/calls  (ek_ only)
mic audio ═══════════════════════════════ WebRTC ═════════════════════════════▶ gpt-live-transcribe
local VAD detects end of question → data channel: input_audio_buffer.commit
         ◀════════════ "…transcription.delta" / ".completed" events ═══════════
transcript ──POST /api/generate-answer.php─▶ profile + job + recent Qs ──────────▶ POST /v1/responses (strict JSON schema)
         ◀── validated answer JSON ───────── detect question, validate, save ◀──
(fallback) MediaRecorder WebM/M4A ──POST /api/transcribe.php─▶ ─────────────────▶ POST /v1/audio/transcriptions (gpt-transcribe)

[Scan paper] camera frames / files ──POST /api/scan-extract.php─▶ validate pages ─▶ POST /v1/responses  (input_image × N, strict JSON)
         ◀── { questions: [...] } ──── open a "scan" session, store questions ◀──
then, per question ───POST /api/generate-answer.php (question_id) ──────────────▶ POST /v1/responses  (same answer engine)
```

The OpenAI endpoints, the model names (`gpt-live-transcribe`, `gpt-transcribe`) and the Realtime commit flow were checked against the current OpenAI docs at developers.openai.com while this was built. `gpt-live-transcribe` sessions use `turn_detection: null`, so the browser decides when the question has ended. It commits the turn after about 1.2 s of silence, or when you tap **Stop**.

## Requirements

| Component | Version |
|---|---|
| PHP | **8.2 or newer** (tested on 8.3 and 8.5) |
| MySQL | **8.0 or newer** (tested on 8.4). MariaDB 10.6+ should also work. |
| Web server | Apache with `mod_rewrite` (cPanel), or PHP's built-in server for local use |
| PHP extensions | `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `json`, `openssl`, `session`. Optional: `zip` (local DOCX text extraction; OpenAI is used without it) |
| Composer | Optional. The app includes its own autoloader and has **no third-party dependencies**. |
| HTTPS | Required in production for microphone access (`localhost` is exempt) |

Check your extensions with `php -m`.

## Local installation

```bash
git clone <your-repo-url> interview-copilot
cd interview-copilot

# Optional: Composer is not required, but supported (it generates vendor/autoload.php)
composer install

cp .env.example .env          # then edit .env (DB credentials + OPENAI_API_KEY)
```

### Create the database and import the schema

```bash
mysql -u root -p -e "CREATE DATABASE interview_copilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p interview_copilot < database/schema.sql
```

Optional demo data adds a sample "Project Manager" job to an account. Register first, change the email at the top of the file, then run:

```bash
mysql -u root -p interview_copilot < database/demo-data.sql
```

You can also click **Add sample job** on the Jobs page. No users or passwords are created by any SQL file.

### Upgrading an existing install

If your database was created before a feature was added, run the files in `database/migrations/` once, in date order (or paste them into phpMyAdmin → SQL):

```bash
mysql -u USER -p DBNAME < database/migrations/2026_10_01_add_session_instructions.sql
mysql -u USER -p DBNAME < database/migrations/2026_10_07_add_scan_sessions.sql
```

### Run it

```bash
php -S localhost:8000 router.php
```

Open **http://localhost:8000**. Use `router.php`: it applies the `.htaccess` protections (blocking `/storage`, `/app`, `.env` and so on) because the built-in server ignores `.htaccess`. Plain `php -S localhost:8000` also runs the app, but without those protections.

> Microphone access works on `http://localhost` and `http://127.0.0.1`. On any other host name you need HTTPS.

Make sure `storage/` is writable by PHP:

```bash
chmod -R 775 storage
```

## Configuration (.env)

| Key | Default | Purpose |
|---|---|---|
| `APP_NAME` | AI Interview Copilot | Display name |
| `APP_ENV` | development | Set to `production` to hide error details. In development, password-reset links are shown on screen when mail is off. |
| `APP_URL` | http://localhost:8000 | Public base URL with no trailing slash. Sub-folders are supported, e.g. `https://example.com/interview-copilot`. |
| `APP_TIMEZONE` | UTC | Display time zone. Data is stored in UTC. |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | | MySQL connection |
| `OPENAI_API_KEY` | | **Server-side only**, never sent to the browser |
| `OPENAI_ANSWER_MODEL` | gpt-6-luna | Question analysis and answers (fast, low cost) |
| `OPENAI_ANSWER_REASONING` | none | Reasoning effort `none\|low\|medium\|high`. Leave empty for models without reasoning. |
| `OPENAI_CV_MODEL` / `OPENAI_CV_REASONING` | gpt-6-luna / low | CV profiling |
| `OPENAI_SCAN_MODEL` / `OPENAI_SCAN_REASONING` | gpt-6-luna / low | Reading questions off a scanned page (needs a vision-capable model) |
| `OPENAI_SCAN_MAX_OUTPUT_TOKENS` | 4000 | Output budget for the question list of one scan |
| `OPENAI_TRANSCRIBE_MODEL` | gpt-transcribe | Recorded fallback transcription |
| `OPENAI_REALTIME_TRANSCRIBE_MODEL` | gpt-live-transcribe | Live WebRTC transcription |
| `OPENAI_REALTIME_ENABLED` | true | Set to `false` to always use recorded mode |
| `OPENAI_TRANSCRIBE_LANGUAGE` | en | ISO-639-1 hint. Leave empty to auto-detect. |
| `OPENAI_TIMEOUT` / `OPENAI_CONNECT_TIMEOUT` | 45 / 10 | API timeouts in seconds |
| `OPENAI_PRICING` | `gpt-6-luna:0.10:0.50` | USD per 1M input/output tokens, used for cost estimates in Settings |
| `STORAGE_PATH` | ./storage | Optional absolute path for private files (CVs, temp audio, logs). Use it to keep them outside `public_html`. |
| `MAX_CV_SIZE_MB` | 10 | CV upload limit |
| `MAX_AUDIO_SIZE_MB` | 20 | Recorded-audio upload limit (OpenAI's maximum is 25 MB) |
| `MAX_SCAN_PAGE_MB` | 8 | Per-page limit for a scanned question paper (JPEG, PNG, WebP or PDF) |
| `MAX_SCAN_PAGES` | 8 | Pages per scan, 1–12. All pages of one scan are read in a single call. |
| `MAX_SCAN_TOTAL_MB` | 20 | Combined size of one scan's pages (keep it under `post_max_size`) |
| `SESSION_TIMEOUT_MINUTES` | 120 | Sign-out after this much inactivity |
| `DEFAULT_ANSWER_LENGTH` | short | Default detail level for new users |
| `MAIL_ENABLED` / `MAIL_FROM` / `MAIL_FROM_NAME` | false | Password-reset emails via PHP `mail()` |

Real environment variables take precedence over `.env`. `.env` is git-ignored and blocked from web access.

## Adding your OpenAI API key

1. Create a key at <https://platform.openai.com/api-keys>. A project key with access to the models above is fine.
2. Put it in `.env`:
   ```
   OPENAI_API_KEY=sk-...your key...
   ```
3. Reload the app. The dashboard warns you if the key is missing.

The key is only read by `app/Services/OpenAIClient.php` on the server. The browser only ever receives short-lived `ek_…` Realtime client secrets, which expire after 10 minutes.

## cPanel / shared hosting deployment

1. **Set PHP 8.2+:** cPanel → *Select PHP Version* (or *MultiPHP Manager*). Enable `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `openssl`, and ideally `zip`.
2. **Upload files:** upload the project, for example to `public_html/interview-copilot/` or a subdomain's document root, with File Manager (zip, then extract) or Git.
3. **Create the database:** cPanel → *MySQL® Databases*. Create a database and a user, then add the user to the database with **ALL PRIVILEGES**. Note the prefixed names, e.g. `cpuser_icp`.
4. **Import the schema:** cPanel → *phpMyAdmin* → select the database → *Import* → `database/schema.sql`.
5. **Configure the environment:** copy `.env.example` to `.env` in File Manager and set `APP_ENV=production`, `APP_URL=https://yourdomain.com/interview-copilot`, the DB credentials and `OPENAI_API_KEY`. Set `MAIL_ENABLED=true` and a `MAIL_FROM` address on your domain to send reset emails.
6. **Permissions:** files `644`, folders `755`, and `storage/` plus its subfolders `755` (or `775` if PHP runs as a different user). `.env` should be `640` or `600`.
7. **Protection:** the included `.htaccess` files deny web access to `.env`, `/storage`, `/app`, `/config`, `/database`, `/views` and `/tests`. Confirm it works: visiting `https://yourdomain.com/interview-copilot/.env` and `/storage/` must return **403 Forbidden**. Even better, set `STORAGE_PATH` to a folder outside `public_html` (e.g. `/home/cpuser/icp-storage`) so private files never sit under the web root.
8. **Upload limits:** `.user.ini` (PHP-FPM) and `.htaccess` (mod_php) set `upload_max_filesize=25M` and `post_max_size=26M`. If uploads still fail, set these in *MultiPHP INI Editor*.
9. **HTTPS:** cPanel → *SSL/TLS Status* → run AutoSSL (Let's Encrypt). Then uncomment the HTTPS redirect at the top of `.htaccess`.
10. Visit the site, register, upload your CV, add a job and press **Listen**.

## HTTPS, camera and microphone access

Browsers only allow `getUserMedia` (the microphone and the camera) in a **secure context**: `https://` or `http://localhost`. On plain HTTP the interview page shows an error and you can only type questions, and the scan page disables **Use camera** — *Choose files* still works, so you can upload photos taken with the phone's own camera app. Mobile Safari and Android Chrome also require HTTPS, and iOS asks for microphone and camera permission per site. If you denied it, re-enable it under *Settings → Safari → Camera / Microphone* or *aA → Website Settings*.

## Directory structure

```
/
├── index.php               landing page (redirects to dashboard when signed in)
├── login.php register.php logout.php forgot-password.php reset-password.php
├── dashboard.php interview.php scan.php practice.php jobs.php cv.php history.php settings.php
├── privacy.php terms.php
├── cv-file.php             authenticated CV download route
├── router.php              dev-server router (enforces .htaccess rules)
├── api/                    JSON endpoints ({success,data} / {success:false,message})
│   ├── realtime-session.php  transcribe.php  generate-answer.php
│   ├── scan-extract.php  scan-question.php
│   ├── upload-cv.php  delete-cv.php  reprocess-cv.php  cv-profile.php
│   ├── save-job.php  delete-job.php  select-job.php
│   ├── start-session.php  end-session.php  history.php  delete-session.php
│   ├── practice-question.php  practice-feedback.php  preferences.php
├── app/
│   ├── bootstrap.php  helpers.php
│   ├── Core/       Api Auth Config Csrf Database Env HttpException Logger RateLimiter Response Session Validator View
│   ├── Services/   OpenAIClient OpenAIException CVService InterviewService ScanService FileUploadService Mailer
│   └── Models/     User CV Job InterviewSession Settings UsageLog PasswordReset
├── assets/
│   ├── css/app.css
│   ├── js/         app.js recorder.js realtime.js interview.js answer-render.js
│   │                page-capture.js scan.js practice.js cv.js jobs.js history.js
│   └── images/favicon.svg
├── config/         app.php database.php openai.php
├── database/       schema.sql demo-data.sql migrations/
├── storage/        users/{id}/cv/ (private CVs), audio/ (temporary), logs/
├── views/          layouts/ partials/ components/ pages/ auth/ errors/
├── tests/          run.php (unit), integration.php, browser/ui-test.mjs, mock-openai.php, run-all.sh, run-browser.sh
├── .env.example .gitignore .htaccess .user.ini composer.json README.md
```

## API endpoints

All endpoints require a signed-in session. POST requests require the `X-CSRF-Token` header (or a `_csrf` field). Responses look like:

```json
{ "success": true, "data": { ... } }
{ "success": false, "message": "Human-friendly error" }
```

| Endpoint | Method | Body | Notes |
|---|---|---|---|
| `api/realtime-session.php` | POST | — | Returns `{client_secret, expires_at, model, calls_url}` |
| `api/transcribe.php` | POST multipart | `audio`, `session_id` | webm/wav/mp3/m4a, validated with finfo |
| `api/generate-answer.php` | POST JSON | `session_id, transcript, mode, source[, question_id, stream]` | Answers one question, spoken or written. `mode`: auto, quick, star, technical, leadership, written. `source`: live, recorded, typed, practice, scan. Returns `{is_question, question, answer, question_id}`. With `stream:true` it replies with Server-Sent Events: `delta` text chunks, then `done` |
| `api/scan-extract.php` | POST multipart | `pages[]` (1–8 images, or one PDF), `job_id`, `hint`, `instructions` | Reads a question paper. Returns `{session, document, questions:[{id, number, text, marks, question_type}], saved}` and opens a `scan` session |
| `api/scan-question.php` | POST JSON | `session_id, text[, question_id, number]` | Corrects a mis-read question or adds one the scan missed. Editing clears that question's stored answer |
| `api/start-session.php` / `end-session.php` | POST JSON | `job_id, type` / `session_id` | `type`: live or practice (a `scan` session is opened by `scan-extract.php`) |
| `api/upload-cv.php` / `delete-cv.php` / `reprocess-cv.php` | POST | multipart `cv` / — | |
| `api/cv-profile.php` | GET | — | CV metadata + extracted profile |
| `api/save-job.php` / `delete-job.php` / `select-job.php` | POST JSON | job fields / `id` | `{sample:1}` adds the demo job |
| `api/history.php` | GET | `?q=&job_id=&from=&to=&page=` or `?id=` | |
| `api/delete-session.php` | POST JSON | `session_id` or `all:true` | |
| `api/practice-question.php` / `practice-feedback.php` | POST JSON | `session_id, focus` / `question_id, answer_text` | |
| `api/session-instructions.php` | POST JSON | `session_id, instructions` | Your own instructions for the interview (max 2000 chars) |
| `api/preferences.php` | POST JSON | `default_answer_mode`, `transcription_mode`, `mic_consent` | |

Answer JSON returned by `generate-answer`:

```json
{
  "question": "Tell me about a difficult project you managed.",
  "question_type": "behavioural",
  "answer_mode": "star",
  "key_message": "Demonstrate planning, communication and problem solving.",
  "points": ["Choose a project with a clear challenge.", "..."],
  "sections": [{"label": "Situation", "bullets": ["..."]}, {"label": "Task", "bullets": ["..."]}, {"label": "Action", "bullets": ["...", "..."]}, {"label": "Result", "bullets": ["..."]}],
  "star": {"situation": "...", "task": "...", "action": ["...", "..."], "result": "..."},
  "cv_evidence": ["Relevant experience from CV..."],
  "evidence_note": "",
  "closing_line": "...",
  "keywords": ["leadership", "communication", "results"]
}
```

With `"answer_mode": "written"` the shape is the same, but each string in `sections[].bullets` is a full paragraph of prose rather than one spoken sentence, and `star` is absent. Both the browser and the history page render those as paragraphs instead of bullets.

## Security

- PDO prepared statements everywhere (`ATTR_EMULATE_PREPARES=false`). No SQL is built by string concatenation with user data.
- `password_hash()` / `password_verify()` with automatic rehash. Session ID is regenerated on login. Cookies are HttpOnly, SameSite=Lax, Secure on HTTPS, with strict mode. Sessions time out after inactivity and are bound to the user agent.
- CSRF tokens on every form and API POST. Logout requires POST.
- Rate limits for login (per account and per IP), registration, password reset, AI calls, transcription and uploads.
- Uploads: extension whitelist, `finfo` MIME detection, magic-byte signature checks, blocking of double extensions (`.php.txt`), executable signatures (`MZ`, ELF, `#!`) and embedded `<?php`/`<script>`. Files get random 40-hex names, are stored outside the public folders and are served only through authenticated routes with `Content-Disposition` and a sandbox CSP.
- Output is escaped with `htmlspecialchars`. AI text is inserted with `textContent`, never `innerHTML`.
- Security headers: CSP (`script-src 'self'`; `connect-src` limited to self and `api.openai.com`), `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy (microphone and camera limited to this origin, geolocation off), and HSTS on HTTPS.
- Errors: stack traces are never shown when `APP_ENV=production`. Every failure is logged to `storage/logs/`, and logs redact passwords, tokens, API keys and CV text.
- OpenAI requests use `store: false`. CV files uploaded to OpenAI for analysis are deleted when you replace or delete the CV.

## Testing

The automated suites run in Docker (MySQL 8.4 + PHP 8.3 + a mock OpenAI server), so they never touch your real database, your `storage/` folder or your OpenAI account:

```bash
bash tests/run-all.sh        # unit + end-to-end HTTP tests
bash tests/run-browser.sh    # headless-Chromium checks: responsive sweep + fake-microphone interview flow
```

Coverage includes:
- registration, login, logout, wrong password, duplicate email, session regeneration, CSRF and rate limiting
- PDF, DOCX and TXT uploads, invalid and oversized files, replace and delete, private-path blocking
- creating, editing, deleting and selecting jobs
- interview sessions: transcript, answer, second question with context, small-talk rejection, mode switching, ended sessions
- scanning a question paper: multi-page and PDF scans, page-format validation (an executable or SVG renamed `.png` is rejected), questions stored unanswered then answered in written mode, correcting and adding questions, unreadable pages, temporary OpenAI files deleted, scan sessions in history, and ownership checks
- history search and filters, practice mode, settings, access control between users, password reset, full account deletion
- OpenAI 401, 403, 429, quota, 500, timeout, invalid JSON, refusal and malformed structured output
- layouts at 320–1440 px with no horizontal scroll, touch targets, and console/CSP errors

The PHP unit tests also run without Docker: `php tests/run.php`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "The database is currently unavailable" | Check the `DB_*` values in `.env`, that MySQL is running and that the user has privileges on the database. |
| "The AI service is not configured yet" | Add `OPENAI_API_KEY` to `.env`. |
| "rejected the server API key" / "quota has been reached" | Check the key, the project's model access and the billing at platform.openai.com. |
| "configured AI model or endpoint was not found" | Your key may not have access to a configured model. Change `OPENAI_ANSWER_MODEL` / `OPENAI_CV_MODEL`, and set `OPENAI_ANSWER_REASONING=` (empty) for models without reasoning. |
| "Live transcription is unavailable. Switching to recorded-question mode." | WebRTC was blocked (corporate network, older browser) or the realtime model isn't enabled for your key. Recorded mode still works. You can set `OPENAI_REALTIME_ENABLED=false`. |
| Microphone button errors on a phone | The site must be on **HTTPS**. Check the browser's site permissions for the microphone. |
| "Microphone access was blocked" | Allow the microphone via the padlock or camera icon in the address bar, then press Try again. |
| "Camera access was blocked" on the Scan page | Allow the camera via the padlock in the address bar, or use **Choose files** and upload a photo taken with your phone's camera app. |
| Scan returns "We couldn't find any questions on that page" | Re-shoot the page: fill the frame, keep it flat and square to the camera, and avoid shadow across the text. Then check the extracted list and add anything missing. |
| CV upload fails with "too large" below 10 MB | Raise `upload_max_filesize` / `post_max_size` in the PHP settings (see `.user.ini`). |
| "Your session has expired. Please refresh" | The CSRF token expired. Reload the page. |
| 403 on every page under Apache | `AllowOverride All` must be enabled for the directory, or remove unsupported directives from `.htaccess`. |
| Answers appear all at once instead of streaming | A proxy is buffering the response. The app sends `X-Accel-Buffering: no` and `X-LiteSpeed-Cache-Control: no-cache`; make sure LSCache isn't caching `/api/`. Answers still work, just without the progressive display. |
| Blank page | Set `APP_ENV=development` temporarily and check `storage/logs/app-YYYY-MM-DD.log` and `storage/logs/php-errors.log`. |
| Reset emails not arriving | Set `MAIL_ENABLED=true` and a `MAIL_FROM` address on your domain. Many hosts reject other From domains. |

## Known limitations

- Live transcription depends on WebRTC access to `api.openai.com`. Some corporate firewalls block it, and the app then falls back to recorded mode.
- The end of a question is detected locally from silence (about 1.2 s). Very long pauses mid-question may split it. The app re-joins the pieces within 15 s, and you can always tap **Stop** yourself.
- Password-reset email uses PHP `mail()`. For reliable delivery, configure your host's mail or swap `App\Services\Mailer` for an SMTP provider.
- CV analysis runs synchronously during upload, which takes about 5–30 s depending on the model and CV length.
- Legacy `.doc` files are parsed on a best-effort basis locally, otherwise via OpenAI file input. DOCX or PDF give the best results.
- Cost estimates use the prices in `OPENAI_PRICING` and are approximate.
- Scanning is only as good as the photo. A page shot at an angle, in poor light or with the edges cropped loses questions, so check the extracted list — and correct or add questions — before answering. Dense handwriting is the hardest case.
- A scan reads at most 8 pages and 40 questions at a time. A longer paper takes more than one scan.
- `OPENAI_SCAN_MODEL` must be vision-capable. A text-only model returns an error from the Responses API.
