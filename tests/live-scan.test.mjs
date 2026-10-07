// Live scanning decides which camera frames are worth sending to be read. That decision is the
// difference between a usable feature and a slow, expensive one, so it is tested directly here.
//
//   node tests/live-scan.test.mjs
//
// No browser and no dependencies: the canvas, the camera and the frames are stubbed, so we control
// exactly what the "camera sees" and can assert what it chooses to send.
import fs from 'node:fs';
import vm from 'node:vm';

let pass = 0, fail = 0;
const check = (n, c, d = '') => {
    if (c) { pass++; console.log(`  \x1b[32m✓\x1b[0m ${n}`); }
    else { fail++; console.log(`  \x1b[31m✗\x1b[0m ${n}${d ? ' — ' + d : ''}`); }
};

const sandbox = {
    window: {}, setInterval: () => 1, clearInterval: () => {}, Date,
    document: { createElement: () => ({ getContext: () => ({ drawImage() {}, getImageData: () => ({ data: [] }) }) }) },
    Uint8Array, Math, Promise, console,
};
sandbox.window.PageCamera = { supported: () => true };
vm.createContext(sandbox);
const root = new URL('..', import.meta.url).pathname;
vm.runInContext(fs.readFileSync(root + 'assets/js/live-scan.js', 'utf8'), sandbox);
const LiveScanner = sandbox.window.LiveScanner;
check('LiveScanner is exported', typeof LiveScanner === 'function');

// A "view" is a uniform frame; two views differ by `d` luma everywhere.
const view = (v) => new Uint8Array(64 * 48).fill(v);

function makeScanner(opts = {}) {
    const captured = [];
    const camera = {
        video: { videoWidth: 640 },
        start: () => Promise.resolve(),
        stop: () => {},
        capture: () => Promise.resolve({ name: 'frame.jpg' }),
    };
    const s = new LiveScanner(camera, Object.assign({
        onCapture: (f) => captured.push(f),
        cooldownMs: 0,
    }, opts));
    s.captured = captured;
    return s;
}

// Feed a frame and let any capture promise resolve.
const feed = async (s, frame) => { s.thumb = () => frame; s.tick(); await Promise.resolve(); await Promise.resolve(); };

console.log('\nHolding still vs moving');
{
    const s = makeScanner();
    // Each tick shows a very different view = the camera is moving.
    await feed(s, view(10));
    await feed(s, view(200));
    await feed(s, view(10));
    check('nothing sent while the view keeps changing', s.captured.length === 0, `sent ${s.captured.length}`);
    check('state reports searching', s.state === 'searching', s.state);

    // Now hold the same view: one tick to settle, the next sends.
    await feed(s, view(10));
    check('one still tick is not enough', s.captured.length === 0 && s.state === 'steadying', s.state);
    await feed(s, view(10));
    check('sends once the view has settled', s.captured.length === 1, `sent ${s.captured.length}`);
}

console.log('\nNot re-reading the same view');
{
    const s = makeScanner();
    await feed(s, view(10)); await feed(s, view(10)); await feed(s, view(10));
    check('first settled view is sent', s.captured.length === 1);
    s.setBusy(false);
    await feed(s, view(10)); await feed(s, view(10)); await feed(s, view(10));
    check('the same view is not sent again', s.captured.length === 1, `sent ${s.captured.length}`);
    check('tells the user to scroll on', ['steadying', 'nothing_new'].includes(s.state), s.state);
}

console.log('\nScrolling to new content');
{
    const s = makeScanner();
    await feed(s, view(10)); await feed(s, view(10)); await feed(s, view(10));
    s.setBusy(false);
    // Scroll: a big change, then settle on the new view.
    await feed(s, view(120));
    await feed(s, view(120)); await feed(s, view(120));
    check('new content is sent', s.captured.length === 2, `sent ${s.captured.length}`);
}

console.log('\nNever overlapping reads');
{
    const s = makeScanner();
    await feed(s, view(10)); await feed(s, view(10)); await feed(s, view(10));
    check('one read in flight', s.captured.length === 1 && s.busy === true);
    // While the server is still reading, keep showing new content.
    await feed(s, view(200)); await feed(s, view(200)); await feed(s, view(200));
    check('no second read starts while one is in flight', s.captured.length === 1, `sent ${s.captured.length}`);
    s.setBusy(false);
    await feed(s, view(200)); await feed(s, view(200));
    check('reads resume once the first finishes', s.captured.length === 2, `sent ${s.captured.length}`);
}

console.log('\nCooldown and markSeen');
{
    const s = makeScanner({ cooldownMs: 100000 });
    await feed(s, view(10)); await feed(s, view(10)); await feed(s, view(10));
    s.setBusy(false);                       // sets lastSentAt = now
    await feed(s, view(200)); await feed(s, view(200)); await feed(s, view(200));
    check('cooldown blocks a rapid second send', s.captured.length === 1, `sent ${s.captured.length}`);

    const t = makeScanner();
    await feed(t, view(10)); await feed(t, view(10)); await feed(t, view(10));
    t.setBusy(false);
    t.markSeen();                            // frame yielded nothing new
    await feed(t, view(10)); await feed(t, view(10));
    check('markSeen stops a fruitless view being retried', t.captured.length === 1, `sent ${t.captured.length}`);
}

console.log('\nLifecycle');
{
    const s = makeScanner();
    check('not running before start', !s.running());
    await s.start();
    check('running after start', s.running());
    s.stop();
    check('stopped cleanly', !s.running() && s.state === 'stopped');
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
