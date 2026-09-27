# RGPD : registre, AIPD et points à valider (squelette)

> ⚖️ **Document de travail, pas encore un avis juridique.** Chaque point marqué ⚖️ doit être validé par
> un juriste avant la mise en production. Cahier des charges §7. Mise à jour : phase 2 (2026-09-27).

## 1. Rôles

| Point | Proposition | Statut |
|---|---|---|
| Position de VeriAge vis-à-vis des plateformes clientes | Sous-traitant (art. 28) : VeriAge agit pour le client qui demande la vérification. | ⚖️ |
| Position de VeriAge pour la preuve réutilisable entre sites | Responsable de traitement distinct : la personne y consent elle-même, et la preuve sert plusieurs clients. | ⚖️ |
| Contrat de sous-traitance (DPA) | Modèle à rédiger (phase 9). | ⚖️ |

## 2. Registre des traitements (art. 30)

| Traitement | Finalité | Données | Base légale | Conservation | Destinataires |
|---|---|---|---|---|---|
| Vérification d'âge | Dire au client si l'utilisateur atteint l'âge minimal | E-mail : HMAC salé par client + chiffré (AES-256-GCM) ; résultat booléen ; âge minimal évalué ; méthode ; dates | Contrat du client / obligation légale du client ⚖️ | Résultat positif : validité du projet (365 j par défaut) ; résultat négatif : 24 h par défaut (0 à 720 h, E1) ; puis purge | Le client concerné uniquement |
| Données biométriques (phase 3) | Comparer le visage au document, contrôle du vivant | Images traitées en mémoire, jamais stockées | Consentement explicite, art. 9.2.a ⚖️ | Aucune (effacées dès la fin) | Aucun |
| Contrôle de l'adresse | Prouver que l'utilisateur détient l'adresse | Code à 6 chiffres ou lien à usage unique, stocké uniquement sous forme de HMAC | Intérêt légitime / exécution du service ⚖️ | 10 à 15 minutes | Aucun |
| Sessions de vérification | Suivi du parcours | E-mail chiffré, `external_ref` (identifiant pseudonyme fourni par le client), statut | Idem vérification | 30 jours (cron) | Le client (webhook, JWT, API) |
| Preuve réutilisable | Éviter une nouvelle vérification sur un autre site | HMAC global de l'e-mail (sans sel client), résultat | Consentement : case facultative, puis acceptation sur chaque site ⚖️ | Durée de validité du résultat | Les sites que la personne accepte |
| Webhooks | Transmettre le résultat au client | Corps chiffré (contient l'e-mail) | Idem vérification | 30 jours après livraison ou abandon ; effacé avec l'adresse | Le client |
| Journal d'audit | Sécurité, preuve | Identifiants internes, action, IP tronquée (/24 ou /48), métadonnées sans identité | Intérêt légitime (sécurité) ⚖️ | 365 jours (`AUDIT_RETENTION_DAYS`) | Opérateur |
| Journaux applicatifs | Exploitation | Sans e-mail ni IP (masquage automatique) | Intérêt légitime | 30 jours | Opérateur |
| Limitation de débit, blocages | Sécurité | Empreintes HMAC dans Redis, sans donnée en clair | Intérêt légitime | 1 minute à 24 heures | Aucun |

## 3. AIPD (art. 35) : éléments à préparer

- **Traitement à grande échelle** de données biométriques (phase 3) et de données de mineurs
  potentiels : l'AIPD est probablement obligatoire ⚖️.
- **Risques identifiés et mesures prises :**
  - fuite de la base : e-mails chiffrés et hachés, aucune identité stockée ;
  - rapprochement des utilisateurs entre clients : sel propre à chaque client ; hash global seulement
    sur consentement ;
  - usurpation de la preuve d'un tiers : contrôle de l'adresse, plafonds de tentatives, lien à usage
    unique au-delà de 20 codes erronés en 24 h ;
  - personne mineure bloquée à tort pendant un an : validité courte des résultats négatifs (E1).
- **Garanties restant à documenter :** traitement local, pas de fournisseur externe, hébergement dans
  l'UE (VPS), chiffrement, rétention courte.

## 4. Droits des personnes

- **Effacement** : `DELETE /api/v1/verifications` (déclenché par le client) ; formulaire public en
  phase 9.
- **Information** : écran de consentement de la page hébergée (textes ⚖️ à valider).
- **Retrait du consentement** : fermer la page avant la fin du parcours ; pour la preuve réutilisable,
  demander son effacement.

## 5. Points ouverts ⚖️

1. Base légale de la vérification elle-même : obligation légale du client (alcool, jeux, contenus)
   ou intérêt légitime ?
2. Âge du consentement numérique (art. 8, variable selon l'État membre) face à des utilisateurs
   potentiellement mineurs.
3. `external_ref` : rappeler au client de n'y mettre aucune donnée directement identifiante ; ce champ
   est conservé 30 jours et transmis dans le JWT et le webhook.
4. Textes du consentement (article 9) et de la case « réutiliser sur d'autres sites ».
5. Usage du numéro de registre national (eID, phase 4) : voir `docs/juridique.md` (à créer).
6. Transfert de l'adresse e-mail au client dans le webhook : nécessaire au rapprochement, déjà connue
   du client.
