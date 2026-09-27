/*
 * VeriAge : capture guidée de la méthode « pièce d'identité + visage » (page hébergée /s/{id}/document).
 *
 * Étapes : type de document → recto (ou page photo du passeport) → verso (carte) → selfie avec défis.
 * - Documents : caméra arrière (getUserMedia), cadre de guidage, contrôle de netteté (variance du
 *   laplacien) et de luminosité, aperçu et reprise ; en secours, envoi d'une photo depuis l'appareil.
 * - Selfie : OBLIGATOIREMENT en direct (aucun envoi de fichier). Les défis sont tirés par le serveur ;
 *   chaque consigne est affichée et annoncée (région ARIA), les images sont prises à intervalle fixe.
 * - Envoi : charge utile chiffrée dans le navigateur (AES-256-GCM, WebCrypto, clé propre à la capture)
 *   puis POST binaire. Rien n'est stocké dans le navigateur (ni localStorage, ni cache) : les images ne
 *   vivent qu'en mémoire, le temps de l'envoi.
 * Aucun texte en dur : les libellés viennent de data-labels (traduits côté serveur).
 */
(function () {
    'use strict';

    var root = document.getElementById('capture');
    if (!root) {
        return;
    }
    var labels = JSON.parse(root.getAttribute('data-labels') || '{}');
    var settings = JSON.parse(root.getAttribute('data-settings') || '{}');
    var live = document.getElementById('capture-live');
    var errorBox = document.getElementById('capture-error');
    var panels = {};
    root.querySelectorAll('[data-panel]').forEach(function (panel) {
        panels[panel.getAttribute('data-panel')] = panel;
    });

    var DOC_MAX_SIDE = 2000;
    var FRAME_WIDTH = 480;
    var state = {
        documentType: 'id_card',
        sides: [],
        sideIndex: 0,
        images: { front: null, back: null },
        stream: null,
        pending: null,
        busy: false
    };

    function label(key, params) {
        var text = labels[key] || labels.error_generic || '';
        Object.keys(params || {}).forEach(function (name) {
            text = text.split('{' + name + '}').join(String(params[name]));
        });
        return text;
    }

    function announce(text) {
        // Vider puis remplir : les lecteurs d'écran relisent même un texte identique.
        live.textContent = '';
        window.setTimeout(function () { live.textContent = text; }, 50);
    }

    function show(name) {
        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = key !== name;
        });
        var heading = panels[name].querySelector('h2[tabindex]');
        if (heading) {
            heading.focus();
        }
    }

    function hideError() {
        errorBox.hidden = true;
    }

    function showError(code, options) {
        options = options || {};
        errorBox.querySelector('[data-error-text]').textContent = label(labels[code] ? code : 'error_generic');
        var attempts = errorBox.querySelector('[data-attempts]');
        attempts.hidden = typeof options.attemptsLeft !== 'number';
        if (!attempts.hidden) {
            attempts.textContent = label('attempts_left', { count: options.attemptsLeft });
        }
        var retry = errorBox.querySelector('[data-action="retry"]');
        retry.hidden = !options.retry;
        retry.onclick = options.retry || null;
        errorBox.hidden = false;
        errorBox.focus && errorBox.focus();
    }

    // -- Caméra ----------------------------------------------------------------------------------

    function stopCamera() {
        if (state.stream) {
            state.stream.getTracks().forEach(function (track) { track.stop(); });
            state.stream = null;
        }
    }

    function startCamera(panel, facing) {
        stopCamera();
        var box = panel.querySelector('[data-camera]');
        var status = panel.querySelector('[data-camera-status]');
        var video = box.querySelector('video');
        status.textContent = label('camera_starting');
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject(new Error('unavailable'));
        }
        var constraints = facing === 'user'
            ? { audio: false, video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } } }
            : { audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } };
        return navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
            state.stream = stream;
            video.srcObject = stream;
            box.hidden = false;
            return video.play().catch(function () {}).then(function () {
                status.textContent = '';
                return video;
            });
        }).catch(function (error) {
            box.hidden = true;
            throw error;
        });
    }

    function cameraMessage(error) {
        return error && (error.name === 'NotAllowedError' || error.name === 'SecurityError') ? 'camera_denied' : 'camera_unavailable';
    }

    // -- Images ------------------------------------------------------------------------------------

    function drawScaled(source, width, height, maxSide) {
        var scale = Math.min(1, maxSide / Math.max(width, height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
        return canvas;
    }

    function toJpeg(canvas, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (!blob) {
                    reject(new Error('encode'));
                    return;
                }
                blob.arrayBuffer().then(resolve, reject);
            }, 'image/jpeg', quality);
        });
    }

    function base64(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return window.btoa(binary);
    }

    /** Netteté (variance du laplacien) et luminosité, sur une réduction en niveaux de gris. */
    function quality(canvas) {
        var small = drawScaled(canvas, canvas.width, canvas.height, 320);
        var data = small.getContext('2d').getImageData(0, 0, small.width, small.height).data;
        var w = small.width;
        var h = small.height;
        var gray = new Float32Array(w * h);
        var sum = 0;
        var saturated = 0;
        for (var i = 0, p = 0; i < data.length; i += 4, p++) {
            var v = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
            gray[p] = v;
            sum += v;
            if (v > 250) {
                saturated++;
            }
        }
        var lapSum = 0;
        var lapSq = 0;
        var n = 0;
        for (var y = 1; y < h - 1; y++) {
            for (var x = 1; x < w - 1; x++) {
                var k = y * w + x;
                var lap = gray[k - w] + gray[k + w] + gray[k - 1] + gray[k + 1] - 4 * gray[k];
                lapSum += lap;
                lapSq += lap * lap;
                n++;
            }
        }
        var mean = sum / (w * h);
        var variance = n ? lapSq / n - Math.pow(lapSum / n, 2) : 0;
        if (Math.min(canvas.width, canvas.height) < 480) {
            return 'quality_small';
        }
        if (mean < 55) {
            return 'quality_dark';
        }
        if (mean > 225 || saturated / (w * h) > 0.08) {
            return 'quality_bright';
        }
        if (variance < 40) {
            return 'quality_blurry';
        }
        return 'quality_ok';
    }

    // -- Documents ----------------------------------------------------------------------------------

    function documentStep() {
        var side = state.sides[state.sideIndex];
        var panel = panels.document;
        var passport = state.documentType === 'passport';
        panel.querySelector('[data-doc-title]').textContent = label(passport ? 'passport_title' : side + '_title');
        panel.querySelector('[data-doc-help]').textContent = label(passport ? 'passport_help' : side + '_help');
        panel.querySelector('[data-preview]').hidden = true;
        panel.querySelector('[data-shoot-actions]').hidden = false;
        state.pending = null;
        show('document');
        var shoot = panel.querySelector('[data-action="shoot"]');
        shoot.hidden = true;
        startCamera(panel, 'environment').then(function () {
            shoot.hidden = false;
        }).catch(function (error) {
            panel.querySelector('[data-camera-status]').textContent = label(cameraMessage(error));
        });
    }

    function preview(canvas) {
        var panel = panels.document;
        var verdict = quality(canvas);
        state.pending = canvas;
        var img = panel.querySelector('[data-preview-img]');
        img.src = canvas.toDataURL('image/jpeg', 0.7);
        panel.querySelector('[data-quality]').textContent = label(verdict);
        panel.querySelector('[data-quality]').className = verdict === 'quality_ok' ? 'quality-ok' : 'quality-warning';
        panel.querySelector('[data-action="use"]').disabled = verdict === 'quality_small';
        panel.querySelector('[data-preview]').hidden = false;
        panel.querySelector('[data-shoot-actions]').hidden = true;
        panel.querySelector('[data-camera]').hidden = true;
        stopCamera();
        announce(label(verdict));
    }

    panels.document.querySelector('[data-action="shoot"]').addEventListener('click', function () {
        var video = panels.document.querySelector('video');
        if (!video.videoWidth) {
            return;
        }
        preview(drawScaled(video, video.videoWidth, video.videoHeight, DOC_MAX_SIDE));
    });

    var fileInput = panels.document.querySelector('[data-file]');
    panels.document.querySelector('[data-action="upload"]').addEventListener('click', function () {
        fileInput.value = '';
        fileInput.click();
    });
    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) {
            return;
        }
        // Lecture en data: (autorisé par la CSP img-src), jamais d'URL blob: ni de copie ailleurs qu'en mémoire.
        var reader = new FileReader();
        reader.onload = function () {
            var image = new Image();
            image.onload = function () {
                preview(drawScaled(image, image.naturalWidth, image.naturalHeight, DOC_MAX_SIDE));
            };
            image.onerror = function () {
                showError('capture_image_invalid');
            };
            image.src = String(reader.result);
        };
        reader.onerror = function () {
            showError('capture_image_invalid');
        };
        reader.readAsDataURL(file);
    });

    panels.document.querySelector('[data-action="retake"]').addEventListener('click', function () {
        hideError();
        documentStep();
    });

    panels.document.querySelector('[data-action="use"]').addEventListener('click', function () {
        if (!state.pending) {
            return;
        }
        var canvas = state.pending;
        encodeDocument(canvas, 0.9).then(function (data) {
            state.images[state.sides[state.sideIndex]] = data;
            state.pending = null;
            state.sideIndex++;
            hideError();
            if (state.sideIndex < state.sides.length) {
                documentStep();
            } else {
                selfieStep();
            }
        }).catch(function () {
            showError('capture_too_large');
        });
    });

    /** JPEG sous la taille maximale acceptée (qualité, puis dimensions, réduites si besoin). */
    function encodeDocument(canvas, qualityLevel) {
        return toJpeg(canvas, qualityLevel).then(function (buffer) {
            if (buffer.byteLength <= settings.doc_max_bytes) {
                return base64(buffer);
            }
            if (qualityLevel > 0.6) {
                return encodeDocument(canvas, qualityLevel - 0.15);
            }
            if (Math.max(canvas.width, canvas.height) > 1200) {
                return encodeDocument(drawScaled(canvas, canvas.width, canvas.height, Math.max(canvas.width, canvas.height) * 0.75), 0.85);
            }
            throw new Error('too_large');
        });
    }

    // -- Selfie et défis ------------------------------------------------------------------------------

    function selfieStep() {
        var panel = panels.selfie;
        var start = panel.querySelector('[data-action="start-selfie"]');
        panel.querySelector('[data-progress]').textContent = '';
        start.hidden = true;
        start.disabled = false;
        show('selfie');
        startCamera(panel, 'user').then(function () {
            start.hidden = false;
        }).catch(function (error) {
            var key = cameraMessage(error) === 'camera_denied' ? 'camera_denied' : 'selfie_camera_required';
            panel.querySelector('[data-camera-status]').textContent = label(key);
            announce(label(key));
        });
    }

    function wait(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function post(url, body, headers) {
        return fetch(url, {
            method: 'POST',
            body: body,
            headers: headers,
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                return { ok: response.ok, status: response.status, data: data };
            });
        });
    }

    /** Images de la séquence : prises à intervalle fixe, étiquetées par fenêtre (0 = neutre, puis défis). */
    function record(video, step, duration, frames, t0) {
        var interval = settings.frame_interval_ms;
        var count = Math.max(1, Math.floor(duration / interval));
        var chain = Promise.resolve();
        var encodes = [];
        for (var i = 0; i < count; i++) {
            chain = chain.then(function () {
                var t = Math.round(performance.now() - t0);
                var canvas = drawScaled(video, video.videoWidth, video.videoHeight, FRAME_WIDTH);
                encodes.push(toJpeg(canvas, 0.8).then(function (buffer) {
                    frames.push({ t: t, step: step, image: base64(buffer) });
                }));
                return wait(interval);
            });
        }
        return chain.then(function () { return Promise.all(encodes); });
    }

    function runChallenge(capture) {
        var panel = panels.selfie;
        var video = panel.querySelector('video');
        var banner = panel.querySelector('[data-challenge]');
        var progress = panel.querySelector('[data-progress]');
        var frames = [];
        var t0 = performance.now();
        banner.hidden = false;
        banner.textContent = label('look');
        announce(label('look'));
        var chain = record(video, 0, settings.neutral_ms, frames, t0);
        capture.challenge.forEach(function (action, index) {
            chain = chain.then(function () {
                var text = label(action);
                banner.textContent = text;
                banner.setAttribute('data-current', action);
                progress.textContent = label('progress', { current: index + 1, total: capture.challenge.length });
                announce(label('progress', { current: index + 1, total: capture.challenge.length }) + ' : ' + text);
                return record(video, index + 1, settings.step_ms, frames, t0);
            });
        });
        return chain.then(function () {
            banner.hidden = true;
            banner.removeAttribute('data-current');
            frames.sort(function (a, b) { return a.t - b.t; });
            return frames.slice(0, settings.max_frames);
        });
    }

    function encrypt(capture, plaintext) {
        var raw = Uint8Array.from(window.atob(capture.key), function (c) { return c.charCodeAt(0); });
        var iv = window.crypto.getRandomValues(new Uint8Array(12));
        return window.crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt']).then(function (key) {
            return window.crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: new TextEncoder().encode(capture.aad), tagLength: 128 },
                key, new TextEncoder().encode(plaintext));
        }).then(function (ciphertext) {
            var body = new Uint8Array(12 + ciphertext.byteLength);
            body.set(iv, 0);
            body.set(new Uint8Array(ciphertext), 12);
            return body;
        });
    }

    function failure(result) {
        var code = (result && result.data && result.data.error) || 'error_generic';
        var retryable = ['capture_expired', 'capture_too_fast', 'capture_invalid', 'capture_frames_invalid', 'error_network', 'biometrics_unavailable'].indexOf(code) !== -1;
        var restartDocument = ['capture_image_invalid', 'capture_too_large'].indexOf(code) !== -1;
        var attemptsLeft = typeof state.attemptsLeft === 'number' ? state.attemptsLeft : undefined;
        if ((retryable || restartDocument) && attemptsLeft === 0) {
            retryable = restartDocument = false;
        }
        show('selfie');
        if (retryable) {
            showError(code, { attemptsLeft: attemptsLeft, retry: function () { hideError(); selfieStep(); } });
        } else if (restartDocument) {
            showError(code, { retry: function () { hideError(); state.sideIndex = 0; documentStep(); } });
        } else {
            showError(code, { retry: function () { window.location.assign(result.data.redirect || root.getAttribute('data-session-url')); } });
        }
        announce(label(code));
    }

    panels.selfie.querySelector('[data-action="start-selfie"]').addEventListener('click', function () {
        if (state.busy) {
            return;
        }
        state.busy = true;
        hideError();
        var button = this;
        button.disabled = true;
        button.hidden = true;
        var capture;
        post(root.getAttribute('data-start-url'), new URLSearchParams({ _state: root.getAttribute('data-state') }), {
            'Content-Type': 'application/x-www-form-urlencoded'
        }).then(function (result) {
            if (!result.ok) {
                throw result;
            }
            capture = result.data;
            state.attemptsLeft = capture.attempts_left;
            return runChallenge(capture);
        }).then(function (frames) {
            stopCamera();
            show('sending');
            announce(label('sending'));
            var payload = JSON.stringify({
                document_type: state.documentType,
                front: state.images.front,
                back: state.documentType === 'id_card' ? state.images.back : null,
                frames: frames
            });
            return encrypt(capture, payload);
        }).then(function (body) {
            return post(root.getAttribute('data-submit-url'), body, {
                'Content-Type': 'application/octet-stream',
                'X-VeriAge-State': root.getAttribute('data-state'),
                'X-VeriAge-Capture': capture.capture_id
            });
        }).then(function (result) {
            if (result.ok && result.data.redirect) {
                window.location.assign(result.data.redirect);
                return;
            }
            throw result;
        }).catch(function (result) {
            stopCamera();
            state.busy = false;
            failure(result && result.data ? result : { data: { error: 'error_network' } });
        });
    });

    // -- Démarrage ----------------------------------------------------------------------------------

    panels.type.addEventListener('submit', function (event) {
        event.preventDefault();
        var checked = panels.type.querySelector('input[name="document_type"]:checked');
        state.documentType = checked ? checked.value : 'id_card';
        state.sides = state.documentType === 'passport' ? ['front'] : ['front', 'back'];
        state.sideIndex = 0;
        state.images = { front: null, back: null };
        documentStep();
    });

    window.addEventListener('pagehide', stopCamera);
    errorBox.setAttribute('tabindex', '-1');
    show('type');
})();
