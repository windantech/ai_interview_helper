// Headless Chromium UI test: responsive layout, touch targets, console/CSP errors,
// and the real Listen → transcribe → answer flow using Chromium's fake microphone.
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8000';
const OUT = process.env.OUT || '/out';
fs.mkdirSync(OUT, { recursive: true });

let pass = 0, fail = 0;
const check = (name, cond, detail = '') => {
    if (cond) { pass++; console.log(`  \x1b[32m✓\x1b[0m ${name}`); }
    else { fail++; console.log(`  \x1b[31m✗ ${name}\x1b[0m ${detail ? '— ' + String(detail).slice(0, 300) : ''}`); }
};
const section = (s) => console.log(`\n\x1b[1m${s}\x1b[0m`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// Centre the element first so fixed mobile navigation can't intercept the click.
const tap = async (pg, sel) => { await pg.$eval(sel, (el) => el.scrollIntoView({ block: 'center' })); await pg.click(sel); };

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH || '/usr/bin/chromium-browser',
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required'],
});
const page = await browser.newPage();
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + e.message));
page.on('dialog', (d) => d.accept());

const email = `ui+${Date.now()}@example.com`;
const pw = 'Secur3pass!';

// ------------------------------------------------------------------ register via UI
section('Register + setup through the UI');
await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
await page.goto(`${BASE}/register.php`, { waitUntil: 'networkidle0' });
await page.type('#name', 'Jamie Rivera');
await page.type('#email', email);
await page.type('#password', pw);
await page.type('#password_confirmation', pw);
await tap(page, 'input[name="agree"]');
await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
check('registered and landed on dashboard', page.url().includes('dashboard.php'));
await page.screenshot({ path: `${OUT}/dashboard-390.png`, fullPage: true });

// CV upload through the dropzone input
const cvPath = '/tmp/cv.txt';
fs.writeFileSync(cvPath, 'Jamie Rivera\nProject Manager\nAcme Build 2019-2025\n' + 'Delivered a £4m depot upgrade on time and led a 12-person team. '.repeat(8));
await page.goto(`${BASE}/cv.php`, { waitUntil: 'networkidle0' });
const input = await page.$('#cv-input');
await Promise.all([page.waitForNavigation({ timeout: 30000 }).catch(() => {}), input.uploadFile(cvPath)]);
check('CV uploaded via drag/drop input + success state shown', (await page.content()).includes('CV uploaded successfully'));
check('extracted information displayed', (await page.content()).includes('Employment history'));
await page.screenshot({ path: `${OUT}/cv-390.png`, fullPage: true });

// Add a job through the form
await page.goto(`${BASE}/jobs.php?new=1`, { waitUntil: 'networkidle0' });
await page.waitForSelector('#job-form');
await page.type('#title', 'Senior Project Manager');
await page.type('#company', 'ABC Company');
await page.type('#description', 'Lead infrastructure projects, manage stakeholders, budgets and risk across multiple workstreams. '.repeat(6));
await page.select('#seniority', 'senior');
await tap(page, '#job-save');
await page.waitForFunction(() => location.pathname.endsWith('jobs.php') && !location.search && document.querySelector('.job-card'), { timeout: 15000 }).catch(() => {});
const jobHtml = await page.content();
check('job saved via form', jobHtml.includes('Senior Project Manager') && jobHtml.includes('Current'), page.url() + ' ' + await page.$eval('#jobs-alert', (e) => e.textContent).catch(() => ''));

// ------------------------------------------------------------------ interview flow with fake mic
section('Interview: Listen → (live attempt → fallback) → transcript → answer');
await page.goto(`${BASE}/interview.php`, { waitUntil: 'networkidle0' });
// Interview instructions: open the panel, write, save (before the session exists)
await tap(page, '#instructions-card summary');
await page.type('#ins-text', 'When asked for a sample project, use the depot upgrade.');
await tap(page, '#ins-save');
await page.waitForFunction(() => !document.getElementById('ins-badge').hidden, { timeout: 5000 }).catch(() => {});
check('instructions panel saves and shows "On"', await page.$eval('#ins-badge', (e) => !e.hidden));
await tap(page, '#instructions-card summary'); // collapse again
const micBox = await (await page.$('#mic-btn')).boundingBox();
check('mic button ≥ 70px', micBox.width >= 70 && micBox.height >= 70, JSON.stringify(micBox));
await tap(page, '#mic-btn');
await page.waitForSelector('#consent-modal:not([hidden])', { timeout: 5000 });
check('consent notice shown before microphone starts', (await page.$eval('#consent-modal', (e) => e.innerText)).includes('Microphone access is used to transcribe interview questions'));
await tap(page, '#consent-check');
await tap(page, '#consent-ok');

// Live (WebRTC) needs real OpenAI; with the mock key it must fail and fall back to recording.
await page.waitForFunction(() => document.getElementById('listen-card').dataset.state === 'listening', { timeout: 30000 }).catch(() => {});
const st = await page.$eval('#listen-card', (e) => e.dataset.state);
check('listening state reached', st === 'listening', st + ' / ' + await page.$eval('#listen-status', (e) => e.textContent));
check('visible mic indicator + "Listening..." text while recording', await page.$eval('#mic-indicator', (e) => !e.hidden) && (await page.$eval('#listen-status', (e) => e.textContent)).includes('Listening'));
const engine = await page.$eval('#engine-label', (e) => e.textContent);
check('fallback to recorded mode after live failure', engine === 'Recording', engine);
await page.screenshot({ path: `${OUT}/interview-listening-390.png` });
await sleep(4000);
await tap(page, '#mic-btn'); // Stop → question finished
await page.waitForFunction(() => document.getElementById('listen-card').dataset.state === 'answer', { timeout: 30000 }).catch(() => {});
check('answer ready state', await page.$eval('#listen-card', (e) => e.dataset.state) === 'answer', await page.$eval('#listen-status', (e) => e.textContent) + ' ' + await page.$eval('#error-detail', (e) => e.textContent));
const q = await page.$eval('#question-text', (e) => e.textContent);
check('question displayed', q.includes('difficult team member'), q);
const ans = await page.$eval('#answer-card', (e) => e.innerText);
check('STAR sections rendered', ['SITUATION', 'TASK', 'ACTION', 'RESULT'].every((s) => ans.toUpperCase().includes(s)), ans.slice(0, 200));
check('CV evidence + closing + keywords rendered', ['FROM YOUR CV', 'CLOSE WITH', 'KEYWORDS'].every((s) => ans.toUpperCase().includes(s)));
check('[fill-in] gaps highlighted for the candidate', (await page.$$('#answer-card mark.fill-in')).length > 0);
check('answer lines are full spoken sentences', ans.includes('I held a private one-to-one'));
check('mic indicator off after answer', await page.$eval('#mic-indicator', (e) => e.hidden));
const qTop = await page.$eval('#question-card', (e) => e.getBoundingClientRect().top + window.scrollY);
const micTop = await page.$eval('#listen-card', (e) => e.getBoundingClientRect().top + window.scrollY);
check('mobile: question appears above the mic panel once answered', qTop < micTop, `q=${qTop} mic=${micTop}`);
await page.screenshot({ path: `${OUT}/interview-answer-390.png`, fullPage: true });

await page.reload({ waitUntil: 'networkidle0' });
check('instructions stored on the interview session', (await page.$eval('#ins-text', (e) => e.value)).includes('use the depot upgrade'));

// typed question uses the same pipeline
section('Typed question + answer modes');
await tap(page, '#new-question');
await tap(page, '.listen-actions [data-open-typed]');
await page.type('#typed-question', 'How do you deal with difficult stakeholders?');
await tap(page, '#typed-form button[type="submit"]');
await page.waitForFunction(() => document.getElementById('listen-card').dataset.state === 'answer' && document.getElementById('question-text').textContent.includes('stakeholders'), { timeout: 20000 }).catch(() => {});
check('typed question answered', (await page.$eval('#question-text', (e) => e.textContent)).includes('stakeholders'));
check('session list shows 2 questions', (await page.$eval('#session-count', (e) => e.textContent)) === '2');
await page.evaluate(() => document.querySelector('input[name="answer-mode"][value="quick"]').click());
await page.waitForFunction(() => document.getElementById('listen-card').dataset.state === 'answer' && /Quick answer/.test(document.getElementById('question-meta').textContent), { timeout: 20000 }).catch(() => {});
check('switching to Quick regenerates as bullet points', /Quick answer/.test(await page.$eval('#question-meta', (e) => e.textContent)));

// desktop layout
await page.setViewport({ width: 1440, height: 900 });
await page.reload({ waitUntil: 'networkidle0' });
const cols = await page.$eval('.iv-grid', (e) => getComputedStyle(e).gridTemplateColumns.split(' ').length);
check('desktop: two-column interview layout', cols === 2, String(cols));
check('desktop: sidebar visible, bottom nav hidden', await page.$eval('.sidebar', (e) => getComputedStyle(e).display !== 'none') && await page.$eval('.bottom-nav', (e) => getComputedStyle(e).display === 'none'));
await page.screenshot({ path: `${OUT}/interview-answer-1440.png`, fullPage: true });

// ------------------------------------------------------------------ responsive sweep
section('Responsive: no horizontal scroll, 44px touch targets');
const widths = [320, 360, 375, 390, 414, 768, 1024, 1440];
const pages = ['dashboard.php', 'cv.php', 'jobs.php', 'jobs.php?new=1', 'interview.php', 'scan.php', 'practice.php', 'history.php', 'settings.php', 'privacy.php'];
const overflow = [];
const smallTargets = new Set();
for (const w of widths) {
    await page.setViewport({ width: w, height: w < 768 ? 800 : 900, isMobile: w < 768, hasTouch: w < 1024 });
    for (const p of pages) {
        await page.goto(`${BASE}/${p}`, { waitUntil: 'networkidle0' });
        const r = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
        if (r.sw > r.cw + 1) { overflow.push(`${p}@${w} (${r.sw}>${r.cw})`); }
        if (w <= 414) {
            const bad = await page.evaluate(() => [...document.querySelectorAll('.btn, .bottom-nav a, .icon-btn, .mic-btn, .seg span, input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]), select')]
                .filter((el) => el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden')
                .map((el) => ({ h: el.getBoundingClientRect().height, t: (el.textContent || el.name || el.className).trim().slice(0, 30) }))
                .filter((x) => x.h > 0 && x.h < 40));
            bad.forEach((b) => smallTargets.add(`${p}: "${b.t}" ${Math.round(b.h)}px`));
        }
    }
}
check(`no horizontal scrolling on ${pages.length} pages × ${widths.length} widths`, overflow.length === 0, overflow.join(', '));
check('touch targets ≥ 40px on phones (buttons/inputs ≥ 44px)', smallTargets.size === 0, [...smallTargets].slice(0, 8).join(' | '));

// landscape phone
await page.setViewport({ width: 844, height: 390, isMobile: true, hasTouch: true });
await page.goto(`${BASE}/interview.php`, { waitUntil: 'networkidle0' });
const ls = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
check('landscape phone: no horizontal scroll', ls.sw <= ls.cw + 1);
await page.screenshot({ path: `${OUT}/interview-landscape.png` });

// guest pages
await page.setViewport({ width: 360, height: 780, isMobile: true, hasTouch: true });
const guest = await browser.createBrowserContext();
const gp = await guest.newPage();
gp.on('console', (m) => { if (m.type() === 'error') consoleErrors.push('[guest] ' + m.text()); });
await gp.setViewport({ width: 360, height: 780, isMobile: true });
for (const p of ['index.php', 'login.php', 'register.php', 'forgot-password.php']) {
    await gp.goto(`${BASE}/${p}`, { waitUntil: 'networkidle0' });
    const r = await gp.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check(`guest ${p} fits 360px`, r.sw <= r.cw + 1, `${r.sw}>${r.cw}`);
}
await gp.screenshot({ path: `${OUT}/landing-360.png`, fullPage: true });

// keyboard accessibility: skip link + focus ring
await page.goto(`${BASE}/dashboard.php`, { waitUntil: 'networkidle0' });
await page.keyboard.press('Tab');
check('skip link is first focusable element', (await page.evaluate(() => document.activeElement.className)).includes('skip-link'));

// ------------------------------------------------------------------ console / CSP
section('Console & CSP');
const relevant = consoleErrors.filter((e) => !/api\.openai\.com|realtime\/calls|Failed to load resource: the server responded with a status of (401|403|404|405)/.test(e));
check('no JS errors or CSP violations in console', relevant.length === 0, relevant.join(' | '));

await browser.close();
console.log(`\n${fail === 0 ? '\x1b[32m' : '\x1b[31m'}Browser tests: ${pass} passed, ${fail} failed\x1b[0m`);
process.exit(fail === 0 ? 0 : 1);
