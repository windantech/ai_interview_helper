/* =====================================================================
   Live scanning: hold the camera over a document and scroll. Frames are
   read continuously instead of taken one photo at a time.

   Every frame is NOT sent — that would be slow and expensive, and most
   frames are blurred mid-scroll or show what was already read. A frame is
   sent only when all of these hold:

     1. the view has stopped moving (so the text is sharp, not smeared),
     2. it differs from the frame last sent (so we are looking at new text),
     3. no read is already in flight, and the cooldown has passed.

   Motion and novelty are measured from a 64x48 luma thumbnail, which is
   cheap enough to run several times a second on a phone.
   ===================================================================== */
(function () {
    'use strict';

    var THUMB_W = 64;
    var THUMB_H = 48;

    /** Option with a default, treating a passed 0 as a real value rather than "unset". */
    function num(v, fallback) {
        return typeof v === 'number' && !isNaN(v) ? v : fallback;
    }

    function LiveScanner(camera, opts) {
        opts = opts || {};
        this.camera = camera;
        this.onState = opts.onState || function () {};
        this.onCapture = opts.onCapture || function () {};

        this.tickMs = num(opts.tickMs, 350);          // how often motion is measured
        this.steadyTicks = num(opts.steadyTicks, 2);  // consecutive still ticks before reading
        this.motionMax = num(opts.motionMax, 3.2);    // mean luma delta that still counts as "held still"
        this.noveltyMin = num(opts.noveltyMin, 6);    // mean luma delta vs the last frame sent
        this.cooldownMs = num(opts.cooldownMs, 1200);
        this.nudgeMs = num(opts.nudgeMs, 6000);       // still + nothing new for this long → prompt a scroll
        this.maxEdge = num(opts.maxEdge, 1600);       // live frames upload smaller than a deliberate photo

        var c = document.createElement('canvas');
        c.width = THUMB_W;
        c.height = THUMB_H;
        this.canvas = c;
        this.ctx = c.getContext('2d', { willReadFrequently: true });

        this.prev = null;        // thumbnail from the previous tick
        this.sent = null;        // thumbnail of the frame last sent to be read
        this.steady = 0;
        this.busy = false;
        this.timer = null;
        this.lastSentAt = 0;
        this.steadySince = 0;
        this.state = '';
    }

    LiveScanner.supported = function () {
        return !!(window.PageCamera && window.PageCamera.supported() && document.createElement('canvas').getContext);
    };

    LiveScanner.prototype.start = function () {
        var self = this;
        return this.camera.start().then(function () {
            self.prev = null;
            self.sent = null;
            self.steady = 0;
            self.lastSentAt = 0;
            self.steadySince = 0;
            self.setState('searching');
            self.timer = setInterval(function () { self.tick(); }, self.tickMs);
        });
    };

    LiveScanner.prototype.stop = function () {
        if (this.timer) { clearInterval(this.timer); this.timer = null; }
        this.camera.stop();
        this.setState('stopped');
    };

    LiveScanner.prototype.running = function () {
        return !!this.timer;
    };

    /** The page tells us when a read starts and finishes, so frames never pile up. */
    LiveScanner.prototype.setBusy = function (on) {
        this.busy = on;
        if (on) {
            this.setState('reading');
        } else {
            this.steady = 0;              // re-settle before considering another frame
            this.lastSentAt = Date.now();
            this.setState('searching');
        }
    };

    /** Treat the current view as already read (used after a frame yielded nothing new). */
    LiveScanner.prototype.markSeen = function () {
        this.sent = this.prev || this.sent;
    };

    LiveScanner.prototype.setState = function (state) {
        if (state === this.state) { return; }
        this.state = state;
        this.onState(state);
    };

    LiveScanner.prototype.thumb = function () {
        var v = this.camera.video;
        if (!v || !v.videoWidth) { return null; }
        try {
            this.ctx.drawImage(v, 0, 0, THUMB_W, THUMB_H);
            var d = this.ctx.getImageData(0, 0, THUMB_W, THUMB_H).data;
            var out = new Uint8Array(THUMB_W * THUMB_H);
            for (var i = 0, p = 0; i < out.length; i++, p += 4) {
                // Rec. 601 luma, integer-weighted to stay fast on low-end phones.
                out[i] = (d[p] * 77 + d[p + 1] * 150 + d[p + 2] * 29) >> 8;
            }
            return out;
        } catch (e) {
            return null;               // tainted canvas or the stream went away
        }
    };

    function meanDelta(a, b) {
        if (!a || !b || a.length !== b.length) { return 255; }
        var sum = 0;
        for (var i = 0; i < a.length; i++) { sum += Math.abs(a[i] - b[i]); }
        return sum / a.length;
    }

    LiveScanner.prototype.tick = function () {
        var now = this.thumb();
        if (!now) { return; }
        var motion = meanDelta(now, this.prev);
        this.prev = now;

        if (this.busy) { return; }

        if (motion > this.motionMax) {
            this.steady = 0;
            this.steadySince = 0;
            this.setState('searching');
            return;
        }

        this.steady++;
        if (!this.steadySince) { this.steadySince = Date.now(); }
        if (this.steady < this.steadyTicks) {
            this.setState('steadying');
            return;
        }

        // Held still. Is this something we have not already read?
        if (this.sent && meanDelta(now, this.sent) < this.noveltyMin) {
            this.setState(Date.now() - this.steadySince > this.nudgeMs ? 'nothing_new' : 'steadying');
            return;
        }
        if (Date.now() - this.lastSentAt < this.cooldownMs) { return; }

        var self = this;
        this.busy = true;
        this.setState('capturing');
        this.camera.capture(1, this.maxEdge).then(function (file) {
            self.sent = now;
            self.lastSentAt = Date.now();
            self.steadySince = 0;
            self.setState('reading');
            self.onCapture(file);
        }, function () {
            self.busy = false;
            self.setState('searching');
        });
    };

    window.LiveScanner = LiveScanner;
})();
