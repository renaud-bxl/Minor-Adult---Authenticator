<?php

declare(strict_types=1);

return [
    'common.back_home' => 'Retour à l’accueil',
    'bad_request.title' => 'Requête invalide',
    'bad_request.message' => 'La requête n’a pas pu être traitée.',
    'not_found.title' => 'Page introuvable',
    'not_found.message' => 'La page demandée n’existe pas ou a été déplacée.',
    'method_not_allowed.title' => 'Méthode non autorisée',
    'method_not_allowed.message' => 'Cette action n’est pas disponible à cette adresse.',
    'csrf.title' => 'Session expirée',
    'csrf.message' => 'Votre session a expiré ou le formulaire n’est plus valide. Rechargez la page et réessayez.',
    'too_many_requests.title' => 'Trop de requêtes',
    'too_many_requests.message' => 'Vous avez effectué trop de requêtes. Veuillez patienter avant de réessayer.',
    'server.title' => 'Erreur interne',
    'server.message' => 'Une erreur inattendue est survenue. Notre équipe a été prévenue ; veuillez réessayer plus tard.',

    'api.bad_request' => 'Requête invalide.',
    'api.invalid_json' => 'Le corps de la requête doit être un objet JSON valide.',
    'api.unauthorized' => 'Clé API absente, invalide ou révoquée. Utilisez l’en-tête « Authorization: Bearer sk_… ».',
    'api.insufficient_credits' => 'Crédits insuffisants pour une nouvelle vérification. Rechargez votre compte.',
    'api.not_found' => 'Ressource introuvable.',
    'api.method_not_allowed' => 'Méthode HTTP non autorisée pour cette ressource.',
    'api.payload_too_large' => 'Le corps de la requête est trop volumineux.',
    'api.unsupported_media_type' => 'Type de contenu non pris en charge : utilisez « Content-Type: application/json ».',
    'api.validation_failed' => 'Certains champs sont invalides (voir « details »).',
    'api.rate_limited' => 'Trop de requêtes. Réessayez après le délai indiqué par l’en-tête Retry-After.',
    'api.email_locked' => 'Trop d’échecs de vérification pour cette adresse. Réessayez plus tard.',
    'api.server_error' => 'Erreur interne. Réessayez plus tard.',
];
