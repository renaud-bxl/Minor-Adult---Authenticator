/*
 * Démonstration : page d'une plateforme cliente fictive intégrant le widget VeriAge.
 * 1. Le « serveur de la boutique » crée une session sandbox (POST /demo/sessions → API VeriAge).
 * 2. Au clic (geste utilisateur, indispensable au mode popup), le widget s'ouvre dans le mode choisi.
 * 3. Les événements du widget (indicatifs) sont affichés, puis le résultat est confirmé côté serveur
 *    (API) et par les webhooks signés reçus.
 * Aucun texte en dur : les libellés viennent des attributs data-* traduits côté serveur.
 */
(function () {
    'use strict';

    var root = document.getElementById('demo');
    if (!root) {
        return;
    }
    var form = document.getElementById('demo-form');
    var openButton = document.getElementById('demo-open');
    var frame = document.getElementById('demo-frame');
    var logApi = document.getElementById('log-api');
    var logEvents = document.getElementById('log-events');
    var logConfirm = document.getElementById('log-confirm');
    var logWebhooks = document.getElementById('log-webhooks');
    var token = root.getAttribute('data-token');
    var lang = root.getAttribute('data-lang');
    var current = null;
    var poller = null;
    var pollingSession = null;

    function label(name) {
        return root.getAttribute('data-label-' + name) || '';
    }

    function show(element, value) {
        element.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    }

    function selectedMode() {
        var checked = form.querySelector('input[name="mode"]:checked');
        return checked ? checked.value : 'modal';
    }

    function logEvent(name, detail) {
        var item = document.createElement('li');
        var time = document.createElement('time');
        time.textContent = new Date().toLocaleTimeString();
        var title = document.createElement('strong');
        title.textContent = ' ' + name + ' ';
        var code = document.createElement('code');
        code.textContent = JSON.stringify(detail);
        item.appendChild(time);
        item.appendChild(title);
        item.appendChild(code);
        item.setAttribute('data-event', name);
        logEvents.appendChild(item);
    }

    function request(method, url, body) {
        return fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'X-Demo-Token': token },
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
        }).then(function (response) {
            // Une réponse non JSON (page d'erreur 419/500) est rejetée avec son code HTTP, pour le diagnostic.
            return response.json().catch(function () {
                throw new Error('HTTP ' + response.status);
            });
        });
    }

    function poll(sessionId) {
        if (poller && pollingSession === sessionId) {
            return;
        }
        if (poller) {
            window.clearInterval(poller);
        }
        pollingSession = sessionId;
        var rounds = 0;
        if (!logConfirm.hasAttribute('data-status')) {
            show(logConfirm, label('waiting'));
        }
        var tick = function () {
            rounds += 1;
            request('GET', '/demo/status?session_id=' + encodeURIComponent(sessionId)).then(function (data) {
                show(logConfirm, data.api_response || data);
                if (data.api_response && data.api_response.status) {
                    logConfirm.setAttribute('data-status', data.api_response.status);
                }
                if (data.webhooks && data.webhooks.length) {
                    show(logWebhooks, data.webhooks);
                    logWebhooks.setAttribute('data-received', String(data.webhooks.length));
                }
                var done = data.api_response && data.api_response.status !== 'pending' && data.webhooks && data.webhooks.length;
                if ((done || rounds >= 40) && pollingSession === sessionId) {
                    window.clearInterval(poller);
                    poller = null;
                    pollingSession = null;
                }
            });
        };
        tick();
        poller = window.setInterval(tick, 1500);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        openButton.disabled = true;
        logEvents.textContent = '';
        show(logConfirm, label('none'));
        show(logWebhooks, label('none'));
        logConfirm.removeAttribute('data-status');
        logWebhooks.removeAttribute('data-received');
        if (poller) {
            window.clearInterval(poller);
            poller = null;
            pollingSession = null;
        }
        var body = {
            email: form.elements.email.value,
            min_age: parseInt(form.elements.min_age.value, 10),
            mode: selectedMode(),
        };
        request('POST', '/demo/sessions', body).then(function (data) {
            show(logApi, { request: 'POST /api/v1/sessions', body: data.api_request, status: data.api_status, response: data.api_response });
            if (data.api_response && data.api_response.session_id) {
                current = { session: data.api_response.session_id, mode: data.mode };
                logApi.setAttribute('data-session', current.session);
                openButton.disabled = false;
                openButton.focus();
                if (data.api_response.status === 'verified') {
                    poll(current.session);
                }
            }
        }).catch(function (error) {
            show(logApi, label('error') + (error && error.message ? ' (' + error.message + ')' : ''));
        });
    });

    openButton.addEventListener('click', function () {
        if (!current || !window.VeriAge) {
            return;
        }
        frame.hidden = current.mode !== 'iframe';
        frame.textContent = '';
        window.VeriAge.open({ session: current.session, mode: current.mode, lang: lang, target: '#demo-frame' });
    });

    ['opened', 'completed', 'failed', 'closed'].forEach(function (name) {
        window.addEventListener('veriage:' + name, function (event) {
            logEvent('veriage:' + name, event.detail);
            if ((name === 'completed' || name === 'failed' || name === 'closed') && current) {
                poll(current.session);
            }
        });
    });
})();
