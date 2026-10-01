/* =====================================================================
   LiveTranscriber — OpenAI Realtime transcription over WebRTC.

   1. PHP (/api/realtime-session.php) mints a short-lived ephemeral key (ek_...).
      The permanent API key never reaches the browser.
   2. Browser sends its SDP offer to https://api.openai.com/v1/realtime/calls with the ephemeral key.
   3. Mic audio streams over WebRTC; events arrive on the "oai-events" data channel:
        conversation.item.input_audio_transcription.delta      (live partial text)
        conversation.item.input_audio_transcription.completed  (final text for a committed turn)
   4. gpt-live-transcribe has no server VAD, so we detect the end of the interviewer's
      question locally (VoiceMeter) and send {"type":"input_audio_buffer.commit"}.
   ===================================================================== */
(function () {
    'use strict';

    function err(kind, message) {
        var e = new Error(message);
        e.kind = kind;
        return e;
    }

    function withTimeout(promise, ms, kind, message) {
        var t;
        return Promise.race([
            promise,
            new Promise(function (_, reject) { t = setTimeout(function () { reject(err(kind, message)); }, ms); })
        ]).then(function (v) { clearTimeout(t); return v; }, function (e) { clearTimeout(t); throw e; });
    }

    /**
     * opts: {
     *   onState(state), onLevel(level, speaking), onPartial(text), onFinal(text, info), onError(err),
     *   onTick(elapsedMs, meter), autoCommit (bool), silenceMs
     * }
     */
    function LiveTranscriber(opts) {
        this.opts = opts || {};
        this.kind = 'live';
        this.pc = null;
        this.dc = null;
        this.stream = null;
        this.meter = new window.VoiceMeter({ onLevel: this.opts.onLevel, onTick: this._onTick.bind(this) });
        this.active = false;
        this.partials = {};
        this.order = [];
        this.awaitingFinal = false;
        this.finalTimer = null;
    }

    LiveTranscriber.supported = function () {
        return !!(window.RTCPeerConnection && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    };

    LiveTranscriber.prototype.start = function () {
        var self = this;
        if (!LiveTranscriber.supported()) {
            return Promise.reject(err('realtime_unsupported', 'Live transcription is not supported in this browser.'));
        }
        this._emit('connecting');
        var micP = window.AudioCapture.getMicrophone();
        var tokenP = window.App.api('api/realtime-session.php', { json: {}, timeout: 20000 });

        return Promise.all([micP, tokenP.catch(function (e) { return { __error: e }; })]).then(function (res) {
            self.stream = res[0];
            var token = res[1];
            if (token.__error) {
                var te = token.__error;
                // Keep the mic stream so the recorded fallback can reuse it without a second prompt.
                throw err('realtime_failed', te.message || 'Live transcription could not be started.');
            }
            return self._connect(token);
        }).then(function () {
            self.meter.attach(self.stream);
            self.meter.reset();
            self.active = true;
            self._emit('listening');
        }).catch(function (e) {
            self._closePeer();
            if (!e.kind || e.kind === 'mic' || e.kind === 'denied' || e.kind === 'no_device' || e.kind === 'busy' || e.kind === 'unsupported') {
                throw e;
            }
            e.kind = 'realtime_failed';
            throw e;
        });
    };

    LiveTranscriber.prototype._connect = function (token) {
        var self = this;
        var pc = new RTCPeerConnection();
        this.pc = pc;
        this.stream.getAudioTracks().forEach(function (track) { pc.addTrack(track, self.stream); });

        var dc = pc.createDataChannel('oai-events');
        this.dc = dc;
        dc.addEventListener('message', function (e) { self._onEvent(e.data); });
        dc.addEventListener('close', function () {
            if (self.active) { self._fail(err('realtime_closed', 'The live transcription connection closed.')); }
        });
        pc.addEventListener('connectionstatechange', function () {
            if (self.active && (pc.connectionState === 'failed' || pc.connectionState === 'closed')) {
                self._fail(err('realtime_closed', 'The live transcription connection was lost.'));
            }
        });

        var opened = new Promise(function (resolve) {
            if (dc.readyState === 'open') { resolve(); } else { dc.addEventListener('open', function () { resolve(); }, { once: true }); }
        });

        return pc.createOffer()
            .then(function (offer) { return pc.setLocalDescription(offer).then(function () { return offer; }); })
            .then(function (offer) {
                return withTimeout(fetch(token.calls_url, {
                    method: 'POST',
                    body: offer.sdp,
                    headers: { 'Authorization': 'Bearer ' + token.client_secret, 'Content-Type': 'application/sdp' }
                }), 15000, 'realtime_failed', 'Live transcription took too long to connect.');
            })
            .then(function (res) {
                if (!res.ok) { throw err('realtime_failed', 'Live transcription connection was refused (' + res.status + ').'); }
                return res.text();
            })
            .then(function (sdp) { return pc.setRemoteDescription({ type: 'answer', sdp: sdp }); })
            .then(function () { return withTimeout(opened, 12000, 'realtime_failed', 'Live transcription channel did not open.'); });
    };

    LiveTranscriber.prototype._onEvent = function (raw) {
        var ev;
        try { ev = JSON.parse(raw); } catch (e) { return; }
        switch (ev.type) {
            case 'conversation.item.input_audio_transcription.delta': {
                var id = ev.item_id || 'current';
                if (!(id in this.partials)) { this.partials[id] = ''; this.order.push(id); }
                this.partials[id] += ev.delta || '';
                if (this.opts.onPartial) { this.opts.onPartial(this._partialText()); }
                break;
            }
            case 'conversation.item.input_audio_transcription.completed': {
                var text = (ev.transcript || (ev.item_id && this.partials[ev.item_id]) || '').trim();
                this._clearPartials();
                this.awaitingFinal = false;
                if (this.finalTimer) { clearTimeout(this.finalTimer); this.finalTimer = null; }
                if (this.opts.onFinal) { this.opts.onFinal(text, { plausible: true, source: 'live' }); }
                break;
            }
            case 'conversation.item.input_audio_transcription.failed':
                this._clearPartials();
                this.awaitingFinal = false;
                if (this.opts.onFinal) { this.opts.onFinal('', { failed: true, source: 'live' }); }
                break;
            case 'error': {
                var code = ((ev.error && (ev.error.code || ev.error.type)) || '').toLowerCase();
                var msg = ((ev.error && ev.error.message) || '').toLowerCase();
                // Committing an empty/short buffer is harmless; keep listening.
                if (code.indexOf('buffer') !== -1 || msg.indexOf('buffer') !== -1) {
                    this.awaitingFinal = false;
                    return;
                }
                this._fail(err('realtime_error', 'Live transcription error. ' + ((ev.error && ev.error.message) || '')));
                break;
            }
            default:
                break;
        }
    };

    LiveTranscriber.prototype._partialText = function () {
        var self = this;
        return this.order.map(function (id) { return self.partials[id]; }).join(' ').trim();
    };

    LiveTranscriber.prototype._clearPartials = function () {
        this.partials = {};
        this.order = [];
    };

    LiveTranscriber.prototype._onTick = function (meter) {
        if (!this.active) { return; }
        if (this.opts.onTick) { this.opts.onTick(Date.now() - meter.startedAt, meter); }
        if (this.opts.autoCommit !== false && !this.awaitingFinal && meter.turnEnded(this.opts.silenceMs || 1400, 600)) {
            this.commit();
        }
    };

    /** Commit the buffered audio as one turn → triggers a .completed transcript. */
    LiveTranscriber.prototype.commit = function () {
        var self = this;
        if (!this.dc || this.dc.readyState !== 'open' || !this.meter.heardSpeech(250)) {
            return false;
        }
        try {
            this.dc.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
        } catch (e) {
            this._fail(err('realtime_closed', 'The live transcription connection was lost.'));
            return false;
        }
        this.meter.reset();
        this.awaitingFinal = true;
        this._emit('committed');
        if (this.finalTimer) { clearTimeout(this.finalTimer); }
        this.finalTimer = setTimeout(function () {
            if (self.awaitingFinal) {
                self.awaitingFinal = false;
                var partial = self._partialText();
                self._clearPartials();
                // Use whatever partial text arrived rather than losing the question.
                if (partial && self.opts.onFinal) { self.opts.onFinal(partial, { plausible: true, source: 'live', partial: true }); }
                else { self._fail(err('realtime_timeout', 'Live transcription did not return the question in time.')); }
            }
        }, 12000);
        return true;
    };

    /** Manual "question finished". Returns false when no speech was captured. */
    LiveTranscriber.prototype.stop = function () {
        if (!this.active) { return false; }
        if (this.awaitingFinal) { return true; }
        var ok = this.commit();
        if (!ok && this.opts.onError) {
            this.opts.onError(err('no_audio', "We couldn't hear the question clearly."));
        }
        return ok;
    };

    /** Hand the open microphone stream to another engine (used for automatic fallback). */
    LiveTranscriber.prototype.takeStream = function () {
        var s = this.stream;
        this.stream = null;
        return s;
    };

    LiveTranscriber.prototype._closePeer = function () {
        try { if (this.dc) { this.dc.close(); } } catch (e) { /* ignore */ }
        try { if (this.pc) { this.pc.close(); } } catch (e) { /* ignore */ }
        this.dc = null;
        this.pc = null;
    };

    LiveTranscriber.prototype.release = function () {
        this.active = false;
        this.awaitingFinal = false;
        if (this.finalTimer) { clearTimeout(this.finalTimer); this.finalTimer = null; }
        this.meter.detach();
        this._closePeer();
        window.AudioCapture.stopStream(this.stream);
        this.stream = null;
        this._clearPartials();
        this._emit('released');
    };

    LiveTranscriber.prototype.cancel = function () { this.release(); };

    LiveTranscriber.prototype.destroy = function () {
        this.release();
        this.meter.close();
    };

    LiveTranscriber.prototype._emit = function (state) {
        if (this.opts.onState) { this.opts.onState(state, {}); }
    };

    LiveTranscriber.prototype._fail = function (e) {
        var wasActive = this.active;
        this.active = false;
        if (wasActive || e.kind === 'no_audio') {
            if (this.opts.onError) { this.opts.onError(e); }
        }
    };

    window.LiveTranscriber = LiveTranscriber;
})();
