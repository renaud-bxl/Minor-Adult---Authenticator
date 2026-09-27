<?php

declare(strict_types=1);

return [
    'common.greeting' => 'Bonjour,',
    'common.footer' => 'Cet e-mail vous a été envoyé automatiquement par {app}. Merci de ne pas y répondre.',
    'common.link_fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',

    'verify_email.subject' => 'Confirmez votre adresse e-mail',
    'verify_email.intro' => 'Merci de votre inscription sur {app}. Confirmez votre adresse e-mail pour activer votre compte.',
    'verify_email.action' => 'Confirmer mon adresse',
    'verify_email.expiry' => 'Ce lien est valable {hours} heures et ne peut être utilisé qu’une seule fois.',
    'verify_email.ignore' => 'Si vous n’êtes pas à l’origine de cette inscription, ignorez simplement cet e-mail.',

    'account_exists.subject' => 'Tentative d’inscription avec votre adresse',
    'account_exists.intro' => 'Quelqu’un (peut-être vous) a essayé de créer un compte {app} avec cette adresse e-mail, alors qu’un compte existe déjà.',
    'account_exists.login_action' => 'Se connecter',
    'account_exists.forgot' => 'Vous avez oublié votre mot de passe ?',
    'account_exists.reset_action' => 'Réinitialiser le mot de passe',
    'account_exists.ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre compte n’a pas été modifié.',

    'password_reset.subject' => 'Réinitialisation de votre mot de passe',
    'password_reset.intro' => 'Nous avons reçu une demande de réinitialisation du mot de passe de votre compte {app}.',
    'password_reset.action' => 'Choisir un nouveau mot de passe',
    'password_reset.expiry' => 'Ce lien est valable {minutes} minutes et ne peut être utilisé qu’une seule fois.',
    'password_reset.ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.',

    'password_changed.subject' => 'Votre mot de passe a été modifié',
    'password_changed.intro' => 'Le mot de passe de votre compte {app} vient d’être modifié.',
    'password_changed.sessions' => 'Par sécurité, toutes les sessions ouvertes ont été fermées.',
    'password_changed.not_you' => 'Si vous n’êtes pas à l’origine de ce changement, sécurisez votre compte immédiatement :',
    'password_changed.action' => 'réinitialiser le mot de passe',
];
