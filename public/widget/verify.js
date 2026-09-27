/*!
 * VeriAge : widget de vérification d'âge (JavaScript vanilla, aucune dépendance).
 *
 * Intégration :
 *   <script src="https://verify.example.eu/widget/verify.js" data-session="vs_…" data-lang="auto"></script>
 *
 * Attributs : data-session (obligatoire pour l'ouverture automatique), data-mode (modal | popup |
 * iframe | redirect ; modal par défaut), data-lang (auto ou code de langue), data-autoopen
 * (« false » : ouverture au clic sur un bouton généré, ou sur data-trigger), data-target (conteneur
 * du mode iframe), data-trigger (sélecteur d'un élément existant qui déclenche l'ouverture).
 *
 * API : window.VeriAge.open({ session, mode, lang, target }), window.VeriAge.close().
 * Événements (sur window) : veriage:opened, veriage:completed, veriage:failed, veriage:closed
 * (détail : session_id, status, is_adult, verified_at, expires_at, method, token).
 *
 * Sécurité : les messages ne sont acceptés que s'ils viennent de l'origine du module ET de la
 * fenêtre ouverte par le widget. Le résultat reçu par le navigateur est INDICATIF : confirmez-le
 * toujours côté serveur (webhook signé, jeton de retour ou GET /api/v1/verifications).
 * Aucun texte en dur : les libellés viennent de /api/v1/i18n/{langue}.
 */
(function () {
    'use strict';

    if (window.VeriAge && window.VeriAge.version) {
        return;
    }

    var script = document.currentScript;
    var base = script ? new URL(script.src, window.location.href).origin : window.location.origin;
    var SESSION_PATTERN = /^vs_[A-Za-z0-9]{32}$/;
    var MODES = ['modal', 'popup', 'iframe', 'redirect'];
    var MOBILE_QUERY = '(max-width: 640px)';
    var messages = {};
    var messagesPromise = null;
    var active = null;

    function t(key) {
        return messages['widget.' + key] || '';
    }

    function detectLang(lang) {
        if (lang && lang !== 'auto' && /^[a-z]{2}$/.test(lang)) {
            return lang;
        }
        var candidates = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || 'en']);
        return String(candidates[0] || 'en').slice(0, 2).toLowerCase();
    }

    function loadMessages(lang) {
        if (messagesPromise) {
            return messagesPromise;
        }
        var fetchLang = function (code) {
            return fetch(base + '/api/v1/i18n/' + code, { credentials: 'omit' }).then(function (response) {
                if (!response.ok) {
                    throw new Error('i18n ' + response.status);
                }
                return response.json();
            });
        };
        messagesPromise = fetchLang(lang)
            .catch(function () { return fetchLang('en'); })
            .then(function (data) { messages = (data && data.messages) || {}; return messages; })
            .catch(function () { return messages; });
        return messagesPromise;
    }

    function emit(name, detail) {
        var event;
        try {
            event = new CustomEvent('veriage:' + name, { detail: detail });
        } catch (e) {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent('veriage:' + name, false, false, detail);
        }
        window.dispatchEvent(event);
    }

    // Sélecteur fourni par l'intégrateur (data-target, data-trigger) : invalide = ignoré, jamais d'exception.
    function find(selector) {
        if (typeof selector !== 'string' || selector === '') {
            return null;
        }
        try {
            return document.querySelector(selector);
        } catch (e) {
            return null;
        }
    }

    function css(element, styles) {
        // CSSOM : compatible avec une CSP stricte de la page hôte (aucun style inline en balisage).
        Object.keys(styles).forEach(function (property) {
            element.style.setProperty(property, styles[property]);
        });
        return element;
    }

    function verifyUrl(session, mode, lang) {
        var url = new URL(base + '/s/' + session);
        url.searchParams.set('lang', lang);
        if (mode !== 'redirect') {
            url.searchParams.set('embed', mode === 'iframe' ? 'iframe' : mode);
            url.searchParams.set('origin', window.location.origin);
        }
        return url.toString();
    }

    function createFrame(src) {
        var frame = document.createElement('iframe');
        frame.src = src;
        frame.title = t('title');
        // Caméra (phase 3 : document + visage) et plein écran, délégués à l'origine du cadre (le module).
        frame.setAttribute('allow', 'camera; fullscreen');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        css(frame, { border: '0', width: '100%', height: '100%', display: 'block', background: 'transparent' });
        return frame;
    }

    function openOverlay(src, fullscreen) {
        var previousFocus = document.activeElement;
        var previousOverflow = document.documentElement.style.overflow;
        var overlay = css(document.createElement('div'), {
            position: 'fixed', inset: '0', 'z-index': '2147483000', background: 'rgba(15, 23, 42, 0.6)',
            display: 'flex', 'align-items': 'center', 'justify-content': 'center',
        });
        var dialog = css(document.createElement('div'), fullscreen ? {
            position: 'relative', width: '100%', height: '100%', background: '#fff',
        } : {
            position: 'relative', width: 'min(520px, 94vw)', height: 'min(760px, 92vh)', background: '#fff',
            'border-radius': '14px', overflow: 'hidden', 'box-shadow': '0 20px 60px rgba(0, 0, 0, 0.35)',
        });
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', t('title'));
        var close = css(document.createElement('button'), {
            position: 'absolute', top: '8px', right: '8px', width: '36px', height: '36px', border: '0',
            'border-radius': '50%', background: 'rgba(15, 23, 42, 0.75)', color: '#fff', 'font-size': '22px',
            'line-height': '36px', cursor: 'pointer', 'z-index': '1',
        });
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', t('close'));
        close.addEventListener('click', function () { api.close(); });
        var frame = createFrame(src);
        dialog.appendChild(frame);
        dialog.appendChild(close);
        overlay.appendChild(dialog);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                api.close();
            }
        });
        var onKey = function (event) {
            if (event.key === 'Escape') {
                api.close();
            }
        };
        document.addEventListener('keydown', onKey);
        document.documentElement.style.overflow = 'hidden';
        document.body.appendChild(overlay);
        frame.focus();
        return {
            frame: frame,
            teardown: function () {
                document.removeEventListener('keydown', onKey);
                document.documentElement.style.overflow = previousOverflow;
                overlay.remove();
                if (previousFocus && previousFocus.focus) {
                    previousFocus.focus();
                }
            },
        };
    }

    function openInline(src, container) {
        var frame = createFrame(src);
        css(frame, { 'min-height': '640px' });
        container.appendChild(frame);
        return { frame: frame, teardown: function () { frame.remove(); } };
    }

    function openPopup(src) {
        var width = 480;
        var height = 760;
        var left = Math.max(0, Math.round((window.screenX || 0) + ((window.outerWidth || width) - width) / 2));
        var top = Math.max(0, Math.round((window.screenY || 0) + ((window.outerHeight || height) - height) / 2));
        // Pas de « noopener » : la page du module doit pouvoir répondre par window.opener.postMessage.
        var popup = window.open(src, 'veriage_' + Date.now(), 'popup=yes,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);
        if (!popup) {
            return null;
        }
        var timer = window.setInterval(function () {
            if (popup.closed) {
                window.clearInterval(timer);
                if (active && active.window === popup) {
                    finish('closed', { session_id: active.session });
                }
            }
        }, 500);
        return { window: popup, teardown: function () { window.clearInterval(timer); if (!popup.closed) { popup.close(); } } };
    }

    function finish(eventName, detail) {
        var current = active;
        if (!current) {
            return;
        }
        active = null;
        current.teardown();
        emit(eventName, detail);
    }

    function onMessage(event) {
        if (!active || event.origin !== base) {
            return;
        }
        var expectedSource = active.window || (active.frame && active.frame.contentWindow);
        var data = event.data;
        if (event.source !== expectedSource || !data || data.source !== 'veriage' || data.session_id !== active.session) {
            return;
        }
        var detail = {
            session_id: data.session_id,
            status: data.status,
            is_adult: data.is_adult === true,
            verified_at: data.verified_at || null,
            expires_at: data.expires_at || null,
            method: data.method || null,
            token: data.token || null,
        };
        if (data.type === 'completed' || data.type === 'failed') {
            if (active.reported) {
                return;
            }
            active.reported = true;
            emit(data.type, detail);
            if (active.mode === 'popup') {
                var popup = active;
                window.setTimeout(function () { if (active === popup) { finish('closed', { session_id: detail.session_id }); } }, 1500);
            }
        } else if (data.type === 'close') {
            finish('closed', { session_id: data.session_id });
        } else if (data.type === 'escalate' && typeof data.url === 'string' && data.url.indexOf(base + '/s/' + active.session) === 0) {
            // Méthode exigeant une fenêtre de premier niveau (eID) : popup, sinon redirection.
            var session = active.session;
            finish('closed', { session_id: session, escalated: true });
            var popupHandle = openPopup(data.url);
            if (popupHandle) {
                active = { session: session, mode: 'popup', window: popupHandle.window, teardown: popupHandle.teardown, reported: false };
                emit('opened', { session_id: session, mode: 'popup' });
            } else {
                window.location.assign(data.url.replace(/([?&])embed=popup/, '$1embed=').replace(/[?&]origin=[^&]*/, ''));
            }
        }
    }

    var api = {
        version: '1.0.0',

        open: function (options) {
            options = options || {};
            var session = String(options.session || '');
            var mode = MODES.indexOf(options.mode) >= 0 ? options.mode : 'modal';
            var lang = detectLang(options.lang);
            if (!SESSION_PATTERN.test(session)) {
                emit('failed', { session_id: session, status: 'invalid_session' });
                return Promise.resolve(false);
            }
            if (active) {
                api.close();
            }
            // Le popup doit s'ouvrir dans le geste de l'utilisateur, avant toute attente réseau.
            var src = verifyUrl(session, mode, lang);
            if (mode === 'redirect') {
                window.location.assign(src);
                return Promise.resolve(true);
            }
            if (mode === 'popup') {
                var handle = openPopup(src);
                if (!handle) {
                    return loadMessages(lang).then(function () {
                        emit('failed', { session_id: session, status: 'popup_blocked', message: t('popup_blocked') });
                        return false;
                    });
                }
                active = { session: session, mode: mode, window: handle.window, teardown: handle.teardown, reported: false };
                emit('opened', { session_id: session, mode: mode });
                loadMessages(lang);
                return Promise.resolve(true);
            }
            return loadMessages(lang).then(function () {
                var mobile = window.matchMedia && window.matchMedia(MOBILE_QUERY).matches;
                var container = mode === 'iframe' ? find(options.target) : null;
                // Sur mobile, la modale et l'iframe passent en plein écran.
                var ui = container && !mobile ? openInline(src, container) : openOverlay(src, mobile);
                active = { session: session, mode: container && !mobile ? 'iframe' : 'modal', frame: ui.frame, teardown: ui.teardown, reported: false };
                emit('opened', { session_id: session, mode: mode });
                return true;
            });
        },

        close: function () {
            if (active) {
                finish('closed', { session_id: active.session });
            }
        },
    };

    window.addEventListener('message', onMessage);
    window.VeriAge = api;

    // Intégration déclarative par les attributs data-* du script.
    if (!script || !script.getAttribute('data-session')) {
        return;
    }
    var options = {
        session: script.getAttribute('data-session'),
        mode: script.getAttribute('data-mode') || 'modal',
        lang: script.getAttribute('data-lang') || 'auto',
        target: script.getAttribute('data-target'),
    };
    var autoOpen = script.getAttribute('data-autoopen') !== 'false' && options.mode !== 'popup';
    var start = function () {
        if (autoOpen) {
            api.open(options);
            return;
        }
        var trigger = find(script.getAttribute('data-trigger'));
        if (!trigger) {
            trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'veriage-button';
            script.parentNode.insertBefore(trigger, script.nextSibling);
            loadMessages(detectLang(options.lang)).then(function () { trigger.textContent = t('open'); });
        }
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            api.open(options);
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
