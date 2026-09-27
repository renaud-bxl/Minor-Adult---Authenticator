/*
 * VeriAge : script de la page de vérification hébergée (/s/{session}).
 * En mode intégré (modale, iframe, popup), la page dialogue avec le widget de la page parente par
 * postMessage, UNIQUEMENT vers l'origine parente vérifiée côté serveur (data-parent-origin).
 * Aucun texte en dur : les libellés sont rendus et traduits côté serveur.
 */
(function () {
    'use strict';

    var body = document.body;
    var mode = body.getAttribute('data-embed');
    var parentOrigin = body.getAttribute('data-parent-origin');
    var sessionId = body.getAttribute('data-session');

    function parentWindow() {
        if (mode === 'popup') {
            return window.opener || null;
        }
        return window.parent !== window ? window.parent : null;
    }

    function send(message) {
        var target = parentWindow();
        if (!target || !parentOrigin || !sessionId) {
            return;
        }
        message.source = 'veriage';
        message.session_id = sessionId;
        try {
            target.postMessage(message, parentOrigin);
        } catch (e) {
            // Page parente fermée ou naviguée ailleurs : rien à faire.
        }
    }

    // Saisie du code : chiffres uniquement.
    var code = document.getElementById('code');
    if (code) {
        code.addEventListener('input', function () {
            var digits = code.value.replace(/\D/g, '').slice(0, 6);
            if (digits !== code.value) {
                code.value = digits;
            }
        });
    }

    if (!mode) {
        return;
    }

    document.querySelectorAll('[data-veriage-close]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            send({ type: 'close' });
            if (mode === 'popup') {
                window.close();
            }
        });
    });

    send({ type: 'ready' });

    // Modale : Échap ferme aussi quand le focus est dans le cadre (le document parent, d'une autre
    // origine, ne reçoit pas ces touches). Le widget ne l'accepte que de ce cadre et de cette origine.
    if (mode === 'modal') {
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                send({ type: 'close' });
            }
        });
    }

    var result = document.getElementById('veriage-result');
    if (result) {
        try {
            send(JSON.parse(result.getAttribute('data-message')));
        } catch (e) {
            // Donnée illisible : le client confirmera le résultat par l'API ou le webhook.
        }
    }

    // Méthodes exigeant une fenêtre de premier niveau (eID) : bascule vers un popup ou une redirection.
    if (mode !== 'popup') {
        document.querySelectorAll('form[data-toplevel]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var url = new URL(window.location.href);
                url.searchParams.set('embed', 'popup');
                send({ type: 'escalate', url: url.toString() });
            });
        });
    }
})();
