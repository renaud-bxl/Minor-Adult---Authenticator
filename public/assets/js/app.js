/*
 * VeriAge : amélioration progressive du site (le site reste utilisable sans JavaScript).
 * Aucun texte en dur : les libellés viennent des attributs data-* rendus et traduits côté serveur.
 */
(function () {
    'use strict';

    // Boutons « Afficher / Masquer » des champs mot de passe.
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) {
            return;
        }
        button.hidden = false;
        button.setAttribute('aria-controls', input.id);
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.textContent = button.getAttribute(reveal ? 'data-label-hide' : 'data-label-show');
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        });
    });

    // Fermeture des messages flash.
    document.querySelectorAll('[data-dismiss]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            var flash = button.closest('.flash');
            if (flash) {
                flash.remove();
            }
        });
    });

    // Sélecteur de langue : fermeture au clic extérieur et avec Échap.
    var switcher = document.querySelector('.lang-switcher');
    if (switcher) {
        document.addEventListener('click', function (event) {
            if (switcher.open && !switcher.contains(event.target)) {
                switcher.open = false;
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && switcher.open) {
                switcher.open = false;
                switcher.querySelector('summary').focus();
            }
        });
    }
})();
