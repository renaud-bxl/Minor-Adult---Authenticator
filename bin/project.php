<?php

declare(strict_types=1);

/*
 * Gestion des projets clients en ligne de commande (en attendant l'espace client, phase 5).
 *
 *   php bin/project.php create --account-name="Brasserie SA" --name="Boutique" \
 *       --origins=https://boutique.example,https://*.boutique.example [--min-age=18] [--validity-days=365] \
 *       [--webhook-test=https://boutique.example/hooks/veriage] [--webhook-live=…] [--accept-shared]
 *       [--negative-ttl-hours=24]   (validité d'un résultat « âge non atteint » ; 0 : jamais réutilisé)
 *   php bin/project.php create --account=12 --name=… --origins=…      (compte existant)
 *   php bin/project.php list
 *   php bin/project.php rotate-key    --project=prj_… --mode=test|live
 *   php bin/project.php rotate-secret --project=prj_… --mode=test|live [--grace-hours=24]
 *       (--grace-hours : l'ancien secret signe encore les webhooks pendant ce délai ; 0 si compromis)
 *   php bin/project.php set-origins   --project=prj_… --origins=…
 *   php bin/project.php set-negative-ttl --project=prj_… --hours=24
 *   php bin/project.php set-below-threshold --project=prj_… --mode=fail|review
 *       (pièce d'identité + visage : correspondance sous le seuil → échec, ou revue manuelle)
 *   php bin/project.php add-webhook   --project=prj_… --mode=test|live --url=…
 *   php bin/project.php demo          (projet de démonstration ; lignes .env sur la sortie standard)
 *
 * Les clés et secrets ne sont affichés qu'une seule fois : seuls leurs empreintes (clés) ou leurs
 * versions chiffrées (secrets) sont conservées.
 */

use App\Core\HostMap;
use App\Models\ApiKeyRepository;
use App\Models\ProjectRepository;
use App\Models\WebhookEndpointRepository;
use App\Services\ProjectAdmin;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$command = $argv[1] ?? 'help';
// Options « --nom=valeur » ou « --drapeau » après la commande (getopt() s'arrêterait à la commande).
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/sD', $argument, $m) !== 1) {
        fwrite(STDERR, 'Argument non reconnu : ' . $argument . "\n");
        exit(1);
    }
    $options[$m[1]] = $m[2] ?? true;
}
$admin = ProjectAdmin::fromApplication($app);
$projects = new ProjectRepository($app->db(), $app->crypto());

$option = static fn (string $name): ?string => isset($options[$name]) && is_string($options[$name]) ? $options[$name] : null;
$list = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));
$err = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
};
$mode = static function () use ($option): bool {
    $value = $option('mode');
    if (!in_array($value, ['test', 'live'], true)) {
        throw new InvalidArgumentException('--mode=test ou --mode=live requis.');
    }

    return $value === 'live';
};
$project = static function () use ($option, $projects): App\Models\Project {
    $id = (string) $option('project');

    return $projects->findByPublicId($id) ?? throw new InvalidArgumentException('Projet introuvable : ' . $id);
};
$printCreated = static function (array $created): void {
    /** @var App\Models\Project $p */
    $p = $created['project'];
    echo "Projet créé : {$p->publicId} ({$p->name})\n";
    echo '  Domaines autorisés : ' . implode(', ', $p->allowedOrigins) . "\n";
    echo "  Âge minimal : {$p->minAge} ; validité : {$p->validityDays} jours (résultat négatif : {$p->negativeTtlHours} h)\n\n";
    echo "À conserver en lieu sûr (affiché une seule fois) :\n";
    echo "  Clé sandbox     : {$created['keys']['test']}\n";
    echo "  Clé production  : {$created['keys']['live']}\n";
    echo "  Secret de signature (sandbox)    : {$created['secrets']['test']}\n";
    echo "  Secret de signature (production) : {$created['secrets']['live']}\n";
};

try {
    switch ($command) {
        case 'create':
            $accountId = $option('account') !== null ? (int) $option('account') : null;
            if ($accountId === null) {
                $accountId = $admin->createAccount((string) ($option('account-name') ?? throw new InvalidArgumentException('--account ou --account-name requis.')));
            }
            $webhooks = array_filter(['test' => $option('webhook-test'), 'live' => $option('webhook-live')], 'is_string');
            $printCreated($admin->create(
                $accountId,
                (string) ($option('name') ?? throw new InvalidArgumentException('--name requis.')),
                $list($option('origins')),
                $option('min-age') !== null ? (int) $option('min-age') : null,
                $option('validity-days') !== null ? (int) $option('validity-days') : null,
                $webhooks,
                isset($options['accept-shared']),
                $option('negative-ttl-hours') !== null ? (int) $option('negative-ttl-hours') : null,
            ));
            break;

        case 'set-negative-ttl':
            $admin->setNegativeTtl($project(), (int) ($option('hours') ?? throw new InvalidArgumentException('--hours requis.')));
            echo "Validité d'un résultat négatif mise à jour.\n";
            break;

        case 'set-below-threshold':
            $admin->setBelowThreshold($project(), (string) ($option('mode') ?? throw new InvalidArgumentException('--mode=fail ou --mode=review requis.')));
            echo "Comportement sous le seuil mis à jour.\n";
            break;

        case 'list':
            $keys = new ApiKeyRepository($app->db());
            $hooks = new WebhookEndpointRepository($app->db());
            foreach ($projects->all() as $p) {
                echo "{$p->publicId}  compte {$p->accountId}  « {$p->name} »  âge {$p->minAge}  validité {$p->validityDays} j  sous le seuil : {$p->belowThreshold}"
                    . ($p->acceptShared ? '  réutilisation entre clients acceptée' : '') . "\n";
                echo '    domaines : ' . implode(', ', $p->allowedOrigins) . "\n";
                foreach ($keys->listForProject($p->id) as $k) {
                    echo '    clé sk_' . ((int) $k['livemode'] === 1 ? 'live' : 'test') . '_…' . $k['last4']
                        . ($k['revoked_at'] !== null ? '  (révoquée)' : '') . "\n";
                }
                foreach ($hooks->listForProject($p->id) as $h) {
                    echo '    webhook ' . ((int) $h['livemode'] === 1 ? 'live' : 'test') . ' : ' . $h['url'] . ((int) $h['enabled'] === 1 ? '' : '  (désactivé)') . "\n";
                }
            }
            break;

        case 'rotate-key':
            echo 'Nouvelle clé (les précédentes sont révoquées) : ' . $admin->rotateKey($project(), $mode()) . "\n";
            break;

        case 'rotate-secret':
            $graceHours = $option('grace-hours') ?? '24';
            if (preg_match('/^\d{1,3}$/D', $graceHours) !== 1) {
                throw new InvalidArgumentException('--grace-hours doit être un nombre entier d\'heures (0 à 168).');
            }
            echo 'Nouveau secret de signature : ' . $admin->rotateSecret($project(), $mode(), (int) $graceHours * 3600) . "\n";
            echo $graceHours === '0'
                ? "L'ancien secret ne vaut plus.\n"
                : "L'ancien secret signe encore les webhooks pendant {$graceHours} h (deux signatures v1) ; le jeton de retour est signé avec le nouveau.\n";
            break;

        case 'set-origins':
            $admin->setOrigins($project(), $list($option('origins')));
            echo "Domaines autorisés mis à jour.\n";
            break;

        case 'add-webhook':
            echo 'Webhook ajouté : ' . $admin->addWebhook($project(), $mode(), (string) $option('url')) . "\n";
            break;

        case 'demo':
            $demoUrl = (string) $app->config->get('app.demo_url');
            $origin = HostMap::origin($demoUrl);
            if ($origin === null) {
                throw new InvalidArgumentException('DEMO_URL doit être défini dans .env (ex. http://127.0.0.1:8001).');
            }
            $created = $admin->create(
                $admin->createAccount('Démo VeriAge'),
                'Cave du Parc (démo)',
                [$origin],
                18,
                365,
                ['test' => rtrim($demoUrl, '/') . '/demo/webhook'],
            );
            $err('Projet de démonstration créé : ' . $created['project']->publicId . ' (domaine autorisé : ' . $origin . ').');
            $err('Ajoutez ces deux lignes à .env (ex. « php bin/project.php demo >> .env »), puis redémarrez le serveur :');
            echo 'DEMO_API_KEY=' . $created['keys']['test'] . "\n";
            echo 'DEMO_SIGNING_SECRET=' . $created['secrets']['test'] . "\n";
            break;

        default:
            $err('Commandes : create, list, rotate-key, rotate-secret, set-origins, set-negative-ttl, set-below-threshold, add-webhook, demo (voir l\'en-tête de bin/project.php).');
            exit($command === 'help' ? 0 : 1);
    }
} catch (InvalidArgumentException $e) {
    $err('Erreur : ' . $e->getMessage());
    exit(1);
}
exit(0);
