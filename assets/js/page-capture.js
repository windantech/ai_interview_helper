/* =====================================================================
   Page capture: rear-camera preview + frame grab, and downscaling for
   images picked from the device. Produces JPEG blobs small enough to
   upload quickly while keeping printed and handwritten text readable.
   ===================================================================== */
(function () {
    'use strict';

    var MAX_EDGE = 2000;      // longest side of an uploaded page, in pixels
    var QUALITY = 0.88;

    function err(kind, message) {
        var e = new Error(message);
        e.kind = kind;
        return e;
    }

    /** Draw a source (video or image) onto a canvas no larger than maxEdge and return a JPEG blob. */
    function toJpeg(source, width, height, maxEdge) {
        var scale = Math.min(1, (maxEdge || MAX_EDGE) / Math.max(width, height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        var ctx = canvas.getContext('2d');
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        return new Promise(function (resolve, reject) {
            if (canvas.toBlob) {
                canvas.toBlob(function (blob) {
                    blob ? resolve(blob) : reject(err('encode', 'The page image could not be prepared.'));
                }, 'image/jpeg', QUALITY);
            } else {
                try {
                    var parts = canvas.toDataURL('image/jpeg', QUALITY).split(',')[1];
                    var bin = atob(parts);
                    var bytes = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
                    resolve(new Blob([bytes], { type: 'image/jpeg' }));
                } catch (e) { reject(err('encode', 'The page image could not be prepared.')); }
            }
        });
    }

    /**
     * Re-encode an image File as a downscaled JPEG. PDFs and anything that cannot be decoded are
     * returned untouched, so the server still validates them.
     */
    function prepareFile(file) {
        if (!file || file.type === 'application/pdf' || !/^image\//.test(file.type || '')) {
            return Promise.resolve(file);
        }
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                toJpeg(img, img.naturalWidth, img.naturalHeight).then(function (blob) {
                    URL.revokeObjectURL(url);
                    // Keep the original if re-encoding made it bigger (already small or well compressed).
                    resolve(blob.size < file.size ? new File([blob], renameJpeg(file.name), { type: 'image/jpeg' }) : file);
                }, function () { URL.revokeObjectURL(url); resolve(file); });
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }

    function renameJpeg(name) {
        return String(name || 'page').replace(/\.[^.]+$/, '') .replace(/[^\w\-. ]+/g, '_').slice(0, 60) + '.jpg';
    }

    /** Rear-camera preview bound to a <video> element. */
    function PageCamera(video) {
        this.video = video;
        this.stream = null;
    }

    PageCamera.supported = function () {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext);
    };

    PageCamera.prototype.start = function () {
        var self = this;
        if (!PageCamera.supported()) {
            return Promise.reject(err('unsupported', window.isSecureContext
                ? 'This browser cannot use the camera. Choose a photo file instead.'
                : 'Camera access requires HTTPS. Choose a photo file instead.'));
        }
        if (this.stream) { return Promise.resolve(); }
        return navigator.mediaDevices.getUserMedia({
            // A question paper is portrait, so ask for the widest sensible frame rather than a
            // tight 16:9 crop, and let the browser pick the closest mode it has.
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1920 },
                height: { ideal: 1920 },
                aspectRatio: { ideal: 1 }
            },
            audio: false
        }).then(function (stream) {
            self.stream = stream;
            self.video.srcObject = stream;
            return self.video.play().catch(function () { /* autoplay guard; the frame still renders */ });
        }, function (e) {
            var name = e && e.name;
            if (name === 'NotAllowedError' || name === 'SecurityError') {
                throw err('denied', 'Camera access was blocked. Allow it in your browser settings, or choose a photo file instead.');
            }
            if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                throw err('no_device', 'No camera was found on this device. Choose a photo file instead.');
            }
            if (name === 'NotReadableError') {
                throw err('busy', 'The camera is in use by another app. Close it and try again.');
            }
            throw err('camera', 'The camera could not be started. Choose a photo file instead.');
        });
    };

    /** Grab the current frame as a downscaled JPEG File. */
    PageCamera.prototype.capture = function (index, maxEdge) {
        var v = this.video;
        if (!this.stream || !v.videoWidth) {
            return Promise.reject(err('not_ready', 'The camera is still starting. Try again in a moment.'));
        }
        return toJpeg(v, v.videoWidth, v.videoHeight, maxEdge).then(function (blob) {
            return new File([blob], 'page-' + (index || 1) + '.jpg', { type: 'image/jpeg' });
        });
    };

    PageCamera.prototype.track = function () {
        return this.stream ? this.stream.getVideoTracks()[0] || null : null;
    };

    /**
     * Optical/digital zoom range, when the camera exposes one (Android Chrome does; iOS Safari
     * does not). Returns null when zoom cannot be controlled — move the camera instead.
     */
    PageCamera.prototype.zoomRange = function () {
        var t = this.track();
        if (!t || !t.getCapabilities) { return null; }
        var caps;
        try { caps = t.getCapabilities(); } catch (e) { return null; }
        if (!caps || !caps.zoom || caps.zoom.max <= caps.zoom.min) { return null; }
        var settings = (t.getSettings && t.getSettings()) || {};
        return {
            min: caps.zoom.min,
            max: caps.zoom.max,
            step: caps.zoom.step || (caps.zoom.max - caps.zoom.min) / 50,
            value: typeof settings.zoom === 'number' ? settings.zoom : caps.zoom.min
        };
    };

    PageCamera.prototype.setZoom = function (value) {
        var t = this.track();
        if (!t || !t.applyConstraints) { return Promise.resolve(false); }
        return t.applyConstraints({ advanced: [{ zoom: value }] }).then(function () { return true; },
            function () { return false; });
    };

    /** Widest field of view the camera allows, so a whole page fits without backing away. */
    PageCamera.prototype.zoomOut = function () {
        var r = this.zoomRange();
        return r ? this.setZoom(r.min).then(function () { return r.min; }) : Promise.resolve(null);
    };

    PageCamera.prototype.stop = function () {
        if (this.stream) {
            this.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) { /* ignore */ } });
            this.stream = null;
        }
        try { this.video.srcObject = null; } catch (e) { /* ignore */ }
    };

    PageCamera.prototype.active = function () {
        return !!this.stream;
    };

    window.PageCamera = PageCamera;
    window.PageCamera.prepareFile = prepareFile;
})();
