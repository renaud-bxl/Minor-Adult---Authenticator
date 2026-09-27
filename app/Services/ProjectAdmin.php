<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;
use App\Core\TextInput;
use App\Models\AccountRepository;
use App\Models\ApiKeyRepository;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\WebhookEndpointRepository;
use App\Verification\UrlGuard;

/**
 * Administration des projets en attendant l'espace client complet (phase 5) : création d'un projet
 * avec ses clés et secrets, rotation, domaines autorisés, webhooks. Utilisé par bin/project.php.
 * Toute entrée est validée (domaines et URL contre la SSRF, âge minimal, nom).
 */
final class ProjectAdmin
{
    /** Méthodes activées par défaut (implémentées en phases 3 et 4 ; la simulation est implicite en sandbox). */
    public const DEFAULT_METHODS = ['id_document_face', 'eid_be'];

    public function __construct(
        private readonly Database $db,
        private readonly ProjectRepository $projects,
        private readonly ApiKeyRepository $keys,
        private readonly WebhookEndpointRepository $webhooks,
        private readonly AccountRepository $accounts,
        private readonly AuditLog $audit,
        private readonly UrlGuard $urls,
        /** @var list<int> */
        private readonly array $allowedMinAges,
        private readonly int $defaultValidityDays,
    ) {
    }

    public static function fromApplication(Application $app): self
    {
        $db = $app->db();
        /** @var list<int> $ages */
        $ages = $app->config->get('verification.allowed_min_ages');

        return new self(
            $db,
            new ProjectRepository($db, $app->crypto()),
            new ApiKeyRepository($db),
            new WebhookEndpointRepository($db),
            new AccountRepository($db),
            new AuditLog($db),
            $app->urlGuard(),
            $ages,
            (int) $app->config->get('verification.default_validity_days'),
        );
    }

    /**
     * Crée un projet, ses deux clés (sk_test_, sk_live_) et ses deux secrets de signature.
     *
     * @param list<string> $origins     domaines autorisés (origines)
     * @param array{test?: string, live?: string} $webhooks URL de webhook par mode
     * @return array{project: Project, keys: array{test: string, live: string}, secrets: array{test: string, live: string}}
     */
    public function create(
        int $accountId,
        string $name,
        array $origins,
        ?int $minAge = null,
        ?int $validityDays = null,
        array $webhooks = [],
        bool $acceptShared = false,
    ): array {
        $name = TextInput::normalize($name);
        if ($name === null || $name === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Nom de projet invalide.');
        }
        $minAge ??= 18;
        if (!in_array($minAge, $this->allowedMinAges, true)) {
            throw new \InvalidArgumentException('Âge minimal invalide (valeurs admises : ' . implode(', ', $this->allowedMinAges) . ').');
        }
        $validityDays ??= $this->defaultValidityDays;
        if ($validityDays < 1 || $validityDays > 3650) {
            throw new \InvalidArgumentException('Durée de validité invalide (1 à 3650 jours).');
        }
        $origins = $this->normalizeOrigins($origins);
        foreach ($webhooks as $mode => $url) {
            $this->assertWebhook($url, $origins, (string) $mode);
        }
        if ($this->db->fetchOne('SELECT id FROM accounts WHERE id = ?', [$accountId]) === null) {
            throw new \InvalidArgumentException('Compte introuvable : ' . $accountId);
        }

        return $this->db->transaction(function () use ($accountId, $name, $origins, $minAge, $validityDays, $webhooks, $acceptShared): array {
            [$project, $secrets] = $this->projects->create($accountId, $name, $minAge, $validityDays, $origins, self::DEFAULT_METHODS, $acceptShared);
            $keys = ['test' => $this->keys->create($project->id, false), 'live' => $this->keys->create($project->id, true)];
            foreach ($webhooks as $mode => $url) {
                $this->webhooks->create($project->id, $mode === 'live', $url);
            }
            $this->audit->record('project.created', null, $accountId, null, $project->id);

            return ['project' => $project, 'keys' => $keys, 'secrets' => $secrets];
        });
    }

    public function createAccount(string $name, string $locale = 'fr'): int
    {
        $name = TextInput::normalize($name);
        if ($name === null || $name === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Nom de compte invalide.');
        }

        return $this->accounts->create($name, $locale);
    }

    /** Révoque les clés du mode et en émet une nouvelle. @return string la nouvelle clé */
    public function rotateKey(Project $project, bool $livemode): string
    {
        return $this->db->transaction(function () use ($project, $livemode): string {
            $this->keys->revokeAll($project->id, $livemode);
            $key = $this->keys->create($project->id, $livemode);
            $this->audit->record('project.key_rotated', null, $project->accountId, null, $project->id, ['livemode' => $livemode]);

            return $key;
        });
    }

    public function rotateSecret(Project $project, bool $livemode): string
    {
        $secret = $this->projects->rotateSigningSecret($project, $livemode);
        $this->audit->record('project.secret_rotated', null, $project->accountId, null, $project->id, ['livemode' => $livemode]);

        return $secret;
    }

    /** @param list<string> $origins */
    public function setOrigins(Project $project, array $origins): void
    {
        $this->projects->updateOrigins($project->id, $this->normalizeOrigins($origins));
        $this->audit->record('project.origins_updated', null, $project->accountId, null, $project->id);
    }

    public function addWebhook(Project $project, bool $livemode, string $url): string
    {
        $this->assertWebhook($url, $project->allowedOrigins, $livemode ? 'live' : 'test');
        $id = $this->webhooks->create($project->id, $livemode, $url);
        $this->audit->record('project.webhook_added', null, $project->accountId, null, $project->id, ['livemode' => $livemode]);

        return $id;
    }

    /**
     * @param list<string> $origins
     * @return list<string>
     */
    private function normalizeOrigins(array $origins): array
    {
        $normalized = [];
        foreach ($origins as $origin) {
            if (trim($origin) === '') {
                continue;
            }
            $value = $this->urls->normalizeOrigin($origin);
            if ($value === null) {
                throw new \InvalidArgumentException('Domaine autorisé invalide ou interdit : ' . $origin);
            }
            $normalized[$value] = true;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('Au moins un domaine autorisé est requis (ex. https://boutique.example).');
        }

        return array_keys($normalized);
    }

    /** @param list<string> $origins */
    private function assertWebhook(string $url, array $origins, string $mode): void
    {
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new \InvalidArgumentException('Mode de webhook inconnu : ' . $mode);
        }
        $error = $this->urls->checkUrl($url, $origins);
        if ($error !== null) {
            throw new \InvalidArgumentException('URL de webhook refusée (' . $error . ') : ' . $url);
        }
    }
}
