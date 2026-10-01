/* =====================================================================
   Audio capture helpers
   - VoiceMeter: level metering + simple voice-activity detection (VAD)
   - RecordedTranscriber: MediaRecorder fallback -> POST /api/transcribe.php
   ===================================================================== */
(function () {
    'use strict';

    var AudioCtx = window.AudioContext || window.webkitAudioContext;

    /** Request the microphone with speech-friendly constraints. Maps errors to friendly messages. */
    function getMicrophone() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject(micError('unsupported', window.isSecureContext === false
                ? 'Microphone access requires HTTPS (or localhost). Please open this site over https://.'
                : 'This browser does not support microphone access. Try the latest Chrome, Edge or Safari.'));
        }
        return navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 }
        }).catch(function (err) {
            var name = err && err.name;
            if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
                throw micError('denied', 'Microphone access was blocked. Allow microphone access in your browser settings (the icon in the address bar), then try again.');
            }
            if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
                throw micError('no_device', 'No microphone was found. Connect a microphone and try again.');
            }
            if (name === 'NotReadableError' || name === 'TrackStartError') {
                throw micError('busy', 'Your microphone is being used by another app. Close it and try again.');
            }
            throw micError('mic', 'Could not start the microphone. Please try again.');
        });
    }

    function micError(kind, message) {
        var e = new Error(message);
        e.kind = kind;
        return e;
    }

    function stopStream(stream) {
        if (stream) { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) { /* ignore */ } }); }
    }

    /**
     * VoiceMeter: analyses a MediaStream and reports level + speech/silence.
     * Create it synchronously inside a user gesture (Safari requires that for AudioContext).
     */
    function VoiceMeter(opts) {
        this.opts = opts || {};
        this.ctx = AudioCtx ? new AudioCtx() : null;
        this.timer = null;
        this.reset();
    }
    VoiceMeter.prototype.reset = function () {
        this.noiseFloor = 0.008;
        this.speechMs = 0;
        this.lastSpeechAt = 0;
        this.firstSpeechAt = 0;
        this.startedAt = Date.now();
    };
    VoiceMeter.prototype.attach = function (stream) {
        if (!this.ctx) { return; }
        var self = this;
        if (this.ctx.state === 'suspended') { this.ctx.resume().catch(function () {}); }
        this.source = this.ctx.createMediaStreamSource(stream);
        this.analyser = this.ctx.createAnalyser();
        this.analyser.fftSize = 1024;
        this.source.connect(this.analyser);
        var buf = new Uint8Array(this.analyser.fftSize);
        var last = Date.now();
        this.timer = setInterval(function () {
            self.analyser.getByteTimeDomainData(buf);
            var sum = 0;
            for (var i = 0; i < buf.length; i++) { var v = (buf[i] - 128) / 128; sum += v * v; }
            var rms = Math.sqrt(sum / buf.length);
            var now = Date.now();
            var dt = now - last;
            last = now;
            var threshold = Math.max(0.012, self.noiseFloor * 2.6);
            var speaking = rms > threshold;
            if (speaking) {
                self.speechMs += dt;
                self.lastSpeechAt = now;
                if (!self.firstSpeechAt) { self.firstSpeechAt = now; }
            } else {
                self.noiseFloor = self.noiseFloor * 0.96 + rms * 0.04;
            }
            if (self.opts.onLevel) { self.opts.onLevel(Math.min(1, rms * 9), speaking); }
            if (self.opts.onTick) { self.opts.onTick(self); }
        }, 50);
    };
    /** True when the speaker talked for a while and has now been silent for `silenceMs`. */
    VoiceMeter.prototype.turnEnded = function (silenceMs, minSpeechMs) {
        return this.speechMs >= (minSpeechMs || 600) && this.lastSpeechAt > 0 && (Date.now() - this.lastSpeechAt) >= silenceMs;
    };
    VoiceMeter.prototype.heardSpeech = function (minMs) {
        return this.speechMs >= (minMs || 300);
    };
    VoiceMeter.prototype.detach = function () {
        if (this.timer) { clearInterval(this.timer); this.timer = null; }
        try { if (this.source) { this.source.disconnect(); } } catch (e) { /* ignore */ }
        this.source = null;
    };
    VoiceMeter.prototype.close = function () {
        this.detach();
        if (this.ctx && this.ctx.state !== 'closed') { this.ctx.close().catch(function () {}); }
    };

    // ================================================================ MediaRecorder fallback

    var MIME_CANDIDATES = [
        { mime: 'audio/webm;codecs=opus', ext: 'webm' },
        { mime: 'audio/webm', ext: 'webm' },
        { mime: 'audio/mp4;codecs=mp4a.40.2', ext: 'm4a' },
        { mime: 'audio/mp4', ext: 'm4a' },
        { mime: 'audio/mpeg', ext: 'mp3' },
        { mime: 'audio/wav', ext: 'wav' }
    ];

    function pickMime() {
        if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== 'function') { return null; }
        for (var i = 0; i < MIME_CANDIDATES.length; i++) {
            if (MediaRecorder.isTypeSupported(MIME_CANDIDATES[i].mime)) { return MIME_CANDIDATES[i]; }
        }
        return null;
    }

    function extFor(mime) {
        mime = (mime || '').toLowerCase();
        if (mime.indexOf('webm') !== -1) { return 'webm'; }
        if (mime.indexOf('mp4') !== -1 || mime.indexOf('aac') !== -1 || mime.indexOf('m4a') !== -1) { return 'm4a'; }
        if (mime.indexOf('mpeg') !== -1) { return 'mp3'; }
        if (mime.indexOf('wav') !== -1) { return 'wav'; }
        return 'webm';
    }

    /**
     * RecordedTranscriber
     * opts: {
     *   onState(state, detail), onLevel(level, speaking), onFinal(text, info), onError(err),
     *   autoStop (bool, stop on silence), silenceMs, maxMs, sessionId (fn), maxBytes, purpose ('question'|'answer')
     * }
     */
    function RecordedTranscriber(opts) {
        this.opts = opts || {};
        this.stream = null;
        this.recorder = null;
        this.chunks = [];
        this.meter = new VoiceMeter({ onLevel: this.opts.onLevel, onTick: this._onTick.bind(this) });
        this.active = false;
        this.uploading = null;
        this.kind = 'recorded';
    }

    RecordedTranscriber.supported = function () {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    };

    RecordedTranscriber.prototype.start = function () {
        var self = this;
        if (!RecordedTranscriber.supported()) {
            return Promise.reject(micError('unsupported', 'Audio recording is not supported in this browser. Please type the question instead.'));
        }
        var ready = this.stream ? Promise.resolve(this.stream) : getMicrophone();
        return ready.then(function (stream) {
            self.stream = stream;
            self._begin();
        });
    };

    RecordedTranscriber.prototype._begin = function () {
        var self = this;
        var choice = pickMime();
        try {
            this.recorder = choice ? new MediaRecorder(this.stream, { mimeType: choice.mime, audioBitsPerSecond: 48000 }) : new MediaRecorder(this.stream);
        } catch (e) {
            this.recorder = new MediaRecorder(this.stream);
        }
        this.chunks = [];
        this.recorder.ondataavailable = function (e) { if (e.data && e.data.size) { self.chunks.push(e.data); } };
        this.recorder.onerror = function () {
            self._fail(micError('recorder', 'Recording failed. Please try again or type the question.'));
        };
        this.meter.reset();
        this.meter.detach();
        this.meter.attach(this.stream);
        this.recorder.start(250);
        this.active = true;
        this.startedAt = Date.now();
        this._emit('listening');
    };

    RecordedTranscriber.prototype._onTick = function (meter) {
        if (!this.active) { return; }
        var elapsed = Date.now() - this.startedAt;
        var maxMs = this.opts.maxMs || 90000;
        if (elapsed >= maxMs) { this.stop(); return; }
        if (this.opts.autoStop && meter.turnEnded(this.opts.silenceMs || 1600, 700)) { this.stop(); return; }
        if (this.opts.onTick) { this.opts.onTick(elapsed, meter); }
    };

    /** Finish the current recording and transcribe it. */
    RecordedTranscriber.prototype.stop = function () {
        var self = this;
        if (!this.active || !this.recorder) { return; }
        this.active = false;
        var heard = this.meter.heardSpeech(250);
        this.meter.detach();
        this.recorder.onstop = function () {
            var type = self.recorder.mimeType || (self.chunks[0] && self.chunks[0].type) || 'audio/webm';
            var blob = new Blob(self.chunks, { type: type.split(';')[0] });
            self.chunks = [];
            if (!heard || blob.size < 1200) {
                self._fail(micError('no_audio', "We couldn't hear the question clearly."));
                return;
            }
            if (self.opts.maxBytes && blob.size > self.opts.maxBytes) {
                self._fail(micError('too_large', 'The recording is too long. Please keep it shorter.'));
                return;
            }
            self._upload(blob, extFor(type));
        };
        try { this.recorder.stop(); } catch (e) { this._fail(micError('recorder', 'Recording failed. Please try again.')); }
    };

    RecordedTranscriber.prototype._upload = function (blob, ext) {
        var self = this;
        this._emit('transcribing');
        var form = new FormData();
        form.append('audio', blob, (this.opts.purpose || 'question') + '.' + ext);
        var sid = typeof this.opts.sessionId === 'function' ? this.opts.sessionId() : this.opts.sessionId;
        if (sid) { form.append('session_id', String(sid)); }
        this.abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
        this.uploading = window.App.api('api/transcribe.php', { form: form, timeout: 70000, signal: this.abort ? this.abort.signal : undefined })
            .then(function (data) {
                self.uploading = null;
                var text = (data.transcript || '').trim();
                if (!text) { throw micError('no_audio', "We couldn't hear the question clearly."); }
                if (self.opts.onFinal) { self.opts.onFinal(text, { plausible: data.plausible !== false, source: 'recorded' }); }
            })
            .catch(function (err) {
                self.uploading = null;
                if (err && err.kind === 'aborted') { return; }
                self._fail(err);
            });
    };

    /** Start a fresh recording on the existing microphone stream (continuous listening). */
    RecordedTranscriber.prototype.restart = function () {
        if (!this.stream) { return this.start(); }
        this._begin();
        return Promise.resolve();
    };

    RecordedTranscriber.prototype.cancel = function () {
        this.active = false;
        if (this.abort) { try { this.abort.abort(); } catch (e) { /* ignore */ } }
        if (this.recorder && this.recorder.state !== 'inactive') {
            this.recorder.onstop = null;
            try { this.recorder.stop(); } catch (e) { /* ignore */ }
        }
        this.release();
    };

    /** Stop the microphone completely. */
    RecordedTranscriber.prototype.release = function () {
        this.active = false;
        this.meter.detach();
        stopStream(this.stream);
        this.stream = null;
        this._emit('released');
    };

    RecordedTranscriber.prototype.destroy = function () {
        this.cancel();
        this.meter.close();
    };

    RecordedTranscriber.prototype._emit = function (state, detail) {
        if (this.opts.onState) { this.opts.onState(state, detail || {}); }
    };

    RecordedTranscriber.prototype._fail = function (err) {
        this.active = false;
        if (this.opts.onError) { this.opts.onError(err); }
    };

    window.VoiceMeter = VoiceMeter;
    window.RecordedTranscriber = RecordedTranscriber;
    window.AudioCapture = { getMicrophone: getMicrophone, stopStream: stopStream, micError: micError };
})();
