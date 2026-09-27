<?php

declare(strict_types=1);

namespace App\Verification;

use App\Billing\CreditGateInterface;
use App\Core\ApiException;
use App\Core\Application;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\IpAddress;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\TextInput;
use App\Models\AuditLog;
use App\Models\ManualReviewRepository;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\VerificationRepository;
use App\Models\VerificationSession;
use App\Models\VerificationSessionRepository;
use App\Models\WebhookDeliveryRepository;
use App\Services\MailSender;
use App\Verification\Webhooks\WebhookDispatcher;
use Predis\ClientInterface;

/**
 * Logique métier du module de vérification : création des sessions (API), consultation et
 * effacement des résultats, parcours de la page hébergée (consentement, code e-mail, réutilisation,
 * méthode) et fin de session (résultat, audit, webhook).
 *
 * Règles (cahier des charges 3.3) :
 * - périmètre (projet, mode) : un client ne voit jamais les données d'un autre, ni la sandbox la production ;
 * - réutilisation pour le même projet si la vérification n'est pas expirée et couvre l'âge demandé
 *   (non facturée) ; entre projets, uniquement si l'utilisateur l'a autorisée lors de sa vérification
 *   ET l'accepte explicitement sur le nouveau site, et si le projet l'admet ;
 * - l'adresse doit être contrôlée (code à 6 chiffres) avant de lier un résultat ;
 * - blocage d'une adresse après X échecs sur 24 h ; journal d'audit sans donnée d'identité.
 */
final class VerificationService
{
    public const CODE_OK = 'ok';
    public const CODE_INVALID = 'code_invalid';
    public const CODE_EXPIRED = 'code_expired';
    public const CODE_LOCKED = 'code_locked';
    public const CODE_THROTTLED = 'throttled';

    /** Sel global de la preuve partagée (le hash reste poivré par APP_KEY). */
    private const SHARED_SALT = 'veriage-shared-proof-v1';

    private const EXTERNAL_REF_PATTERN = '/^[A-Za-z0-9_.:\-]{1,64}$/D';
    private const INPUT_FIELDS = ['email', 'min_age', 'return_url', 'lang', 'external_ref'];
    /** Clé d'idempotence : caractères sûrs, 255 au plus (UUID conseillé) ; conservée 24 h. */
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9_.:\-]{1,255}$/D';
    private const IDEMPOTENCY_TTL = 86400;
    private const RATE_LIMITS = ['api_session_email', 'verify_code_send_ip', 'verify_code_ip', 'verify_code_email', 'verify_code_global'];
    /** Jeton du lien à usage unique (preuve renforcée) : 32 caractères base 62, ≈ 190 bits. */
    private const LINK_TOKEN_PATTERN = '/^[A-Za-z0-9]{32}$/D';

    /**
     * @param array<string, mixed>          $config  section « verification » de la configuration
     * @param list<string>                  $languages langues activées
     * @param \Closure(callable(): void): void $defer   exécution après la réponse (e-mails)
     */
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly ProjectRepository $projects,
        private readonly VerificationSessionRepository $sessions,
        private readonly VerificationRepository $verifications,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly AuditLog $audit,
        private readonly MethodRegistry $methods,
        private readonly WebhookDispatcher $webhooks,
        private readonly CreditGateInterface $credits,
        private readonly RateLimiter $limiter,
        private readonly UrlGuard $urls,
        private readonly MailSender $mail,
        private readonly ClientInterface $redis,
        private readonly ReturnToken $returnTokens,
        private readonly \Closure $defer,
        private readonly array $config,
        private readonly array $languages,
        private readonly string $verifyUrl,
        private readonly string $timezone,
        /** @var array<string, array{0: int, 1: int}> limites nommées (voir fromApplication) */
        private readonly array $rateLimits,
        private readonly ?Logger $logger = null,
        private readonly ?ManualReviewRepository $reviews = null,
        /** Délai de décision d'une revue manuelle (heures). */
        private readonly int $reviewTtlHours = 48,
    ) {
    }

    public static function fromApplication(Application $app): self
    {
        $db = $app->db();
        $crypto = $app->crypto();
        /** @var array<string, mixed> $config */
        $config = $app->config->get('verification');
        /** @var list<string> $languages */
        $languages = $app->config->get('i18n.enabled');

        return new self(
            $db,
            $crypto,
            new ProjectRepository($db, $crypto),
            new VerificationSessionRepository($db),
            new VerificationRepository($db),
            new WebhookDeliveryRepository($db),
            new AuditLog($db),
            $app->methods(),
            $app->webhooks(),
            $app->creditGate(),
            $app->rateLimiter(),
            $app->urlGuard(),
            $app->mailSender(),
            $app->redis(),
            new ReturnToken((string) $app->config->get('app.verify_url'), (int) $config['return_token_ttl']),
            $app->defer(...),
            $config,
            $languages,
            (string) $app->config->get('app.verify_url'),
            (string) $app->config->get('app.timezone'),
            array_combine(self::RATE_LIMITS, array_map($app->rateLimit(...), self::RATE_LIMITS)),
            $app->logger(),
            new ManualReviewRepository($db),
            (int) $app->config->get('biometrics.review_ttl_hours', 48),
        );
    }

    // ------------------------------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------------------------------

    /**
     * POST /api/v1/sessions, avec clé d'idempotence facultative (en-tête « Idempotency-Key ») : un
     * nouvel envoi de la même requête (réseau coupé, relance du client) renvoie la même session au
     * lieu d'en créer une seconde, pendant 24 h.
     *
     * - même clé, même corps : réponse d'origine rejouée (corps reconstruit depuis la session ; aucune
     *   adresse n'est conservée dans Redis), en-tête « Idempotent-Replayed: true » ;
     * - même clé, autre corps : 422 idempotency_key_reused ;
     * - requête d'origine encore en cours : 409 idempotency_in_progress ;
     * - erreur (4xx, 5xx) : rien n'est mémorisé, la même clé peut être réessayée.
     * Clés propres à chaque projet et à chaque mode.
     *
     * @param array<string, mixed> $input corps JSON
     * @return array{0: int, 1: array<string, mixed>, 2: bool} [statut HTTP, corps, réponse rejouée]
     */
    public function createSession(ApiContext $ctx, array $input, string $ip, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey === null) {
            return [...$this->createSessionOnce($ctx, $input, $ip), false];
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new ApiException(422, 'validation_failed', ['Idempotency-Key' => 'invalid']);
        }
        $key = 'idempotency:sessions:' . $ctx->project->id . ':' . ($ctx->livemode ? 'live' : 'test') . ':' . $this->crypto->fingerprint($idempotencyKey);
        ksort($input);
        $request = $this->crypto->fingerprint(json_encode($input, JSON_THROW_ON_ERROR));

        $stored = $this->redis->get($key);
        if (!is_string($stored) && $this->redis->set($key, json_encode(['request' => $request], JSON_THROW_ON_ERROR), 'EX', 60, 'NX') === null) {
            $stored = $this->redis->get($key); // requête concurrente avec la même clé
        }
        if (is_string($stored)) {
            $record = json_decode($stored, true);
            if (!is_array($record) || !hash_equals((string) ($record['request'] ?? ''), $request)) {
                throw new ApiException(422, 'idempotency_key_reused');
            }
            if (!isset($record['session_id'], $record['status'])) {
                throw new ApiException(409, 'idempotency_in_progress', [], ['Retry-After' => '1']);
            }
            $session = $this->sessions->findByPublicId((string) $record['session_id']);
            if ($session !== null && $session->projectId === $ctx->project->id && $session->livemode === $ctx->livemode) {
                return [...$this->sessionResponse($ctx->project, $session, $this->sessionEmail($session), (int) $record['status'] === 200), true];
            }
            // Session effacée entre-temps (droit à l'effacement, purge) : traitée comme une nouvelle requête.
            $this->redis->set($key, json_encode(['request' => $request], JSON_THROW_ON_ERROR), 'EX', 60);
        }

        try {
            [$status, $body] = $this->createSessionOnce($ctx, $input, $ip);
        } catch (\Throwable $e) {
            $this->redis->del([$key]);

            throw $e;
        }
        $this->redis->set($key, json_encode(['request' => $request, 'session_id' => $body['session_id'], 'status' => $status], JSON_THROW_ON_ERROR), 'EX', self::IDEMPOTENCY_TTL);

        return [$status, $body, false];
    }

    /**
     * @param array<string, mixed> $input corps JSON
     * @return array{0: int, 1: array<string, mixed>} [statut HTTP, corps]
     */
    private function createSessionOnce(ApiContext $ctx, array $input, string $ip): array
    {
        $project = $ctx->project;
        [$email, $minAge, $returnUrl, $lang, $externalRef] = $this->validateSessionInput($project, $input);
        $emailHash = $this->emailHash($project, $email);
        $scope = $this->scopeKey($project, $ctx->livemode, $emailHash);

        $lock = $this->failureLock($scope);
        if (!$lock->allowed) {
            $this->audit->record('verification.session_refused', null, $project->accountId, $ip, $project->id, [
                'livemode' => $ctx->livemode, 'reason' => 'email_locked', 'key' => $ctx->keyLast4,
            ]);
            throw new ApiException(429, 'email_locked', [], ['Retry-After' => (string) $lock->retryAfter]);
        }
        [$max, $window] = $this->rateLimits['api_session_email'];
        $limit = $this->limiter->attempt('api_session_email', $scope, $max, $window);
        if (!$limit->allowed) {
            throw new ApiException(429, 'rate_limited', [], ['Retry-After' => (string) $limit->retryAfter]);
        }

        $reusable = $this->reusableVerification($project, $ctx->livemode, $emailHash, $minAge);
        if ($reusable === null && $ctx->livemode && !$this->credits->allowsNewVerification($project, true)) {
            throw new ApiException(402, 'insufficient_credits');
        }

        $publicId = 'vs_' . Crypto::randomAlnum(32);
        $session = $this->sessions->create([
            'public_id' => $publicId,
            'project_id' => $project->id,
            'api_key_id' => $ctx->keyId,
            'livemode' => $ctx->livemode ? 1 : 0,
            'email_hash' => $emailHash,
            'email_enc' => $this->crypto->encrypt($email, self::sessionEmailContext($publicId)),
            'min_age' => $minAge,
            'return_url' => $returnUrl,
            'lang' => $lang,
            'external_ref' => $externalRef,
            'status' => VerificationSession::PENDING,
            'expires_at' => VerificationSessionRepository::sql($this->now()->modify('+' . (int) $this->config['session_ttl'] . ' seconds')),
        ]);
        $this->audit->record('verification.session_created', null, $project->accountId, $ip, $project->id, [
            'session' => $publicId, 'livemode' => $ctx->livemode, 'key' => $ctx->keyLast4,
        ]);

        if ($reusable !== null) {
            // Réutilisation (même client, non expirée) : résultat immédiat, non facturé.
            $this->complete($project, $session, (string) $reusable['method'], 'same_client', (bool) $reusable['is_adult'],
                $this->utc((string) $reusable['verified_at']), $this->utc((string) $reusable['expires_at']), null, null, $ip);
            $session = $this->refresh($session);
        }

        return $this->sessionResponse($project, $session, $email, $reusable !== null);
    }

    /**
     * Réponse de POST /api/v1/sessions : 201 (session à suivre) ou 200 (résultat réutilisé immédiat).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function sessionResponse(Project $project, VerificationSession $session, string $email, bool $reused): array
    {
        $body = [
            'object' => 'verification_session',
            'session_id' => $session->publicId,
            'project' => $project->publicId,
            'status' => 'pending',
            'verify_url' => $this->verifyUrl . '/s/' . $session->publicId,
            'expires_in' => (int) $this->config['session_ttl'],
            'expires_at' => $this->iso($session->expiresAt),
            'livemode' => $session->livemode,
        ];

        return $reused
            ? [200, [...$body, 'status' => 'verified', 'reused' => true, 'verification' => $this->verificationBody($email, $session)]]
            : [201, $body];
    }

    /**
     * GET /api/v1/verifications?email= : statut not_verified | pending | failed | verified.
     *
     * @return array<string, mixed>
     */
    public function status(ApiContext $ctx, string $rawEmail, mixed $minAge = null): array
    {
        $email = $this->requireEmail($rawEmail);
        /** @var list<int> $allowedAges */
        $allowedAges = $this->config['allowed_min_ages'];
        if ($minAge !== null && (!is_int($minAge) || !in_array($minAge, $allowedAges, true))) {
            throw new ApiException(422, 'validation_failed', ['min_age' => 'invalid']);
        }
        $emailHash = $this->emailHash($ctx->project, $email);
        $base = ['object' => 'verification', 'email' => $email, 'livemode' => $ctx->livemode];

        $verification = $this->verifications->findValid($ctx->project->id, $ctx->livemode, $emailHash);
        // Âge demandé : un résultat qui ne permet pas de conclure pour cet âge vaut « not_verified ».
        if ($verification !== null && $minAge !== null && !self::covers($verification, $minAge)) {
            $verification = null;
        }
        if ($verification !== null) {
            return [
                ...$base,
                'status' => 'verified',
                'is_adult' => (bool) $verification['is_adult'],
                'min_age' => (int) $verification['min_age'],
                'verified_at' => $this->iso($this->utc((string) $verification['verified_at'])),
                'method' => (string) $verification['method'],
                'expires_at' => $this->iso($this->utc((string) $verification['expires_at'])),
            ];
        }

        $session = $this->sessions->latestForEmail($ctx->project->id, $ctx->livemode, $emailHash);
        $now = $this->now();
        if ($session !== null && $session->isOpen($now)) {
            return [...$base, 'status' => 'pending', 'is_adult' => false, 'session_id' => $session->publicId,
                'session_expires_at' => $this->iso($session->expiresAt), 'review' => $session->inReview($now)];
        }
        if ($session !== null && $session->status === VerificationSession::FAILED) {
            return [...$base, 'status' => 'failed', 'is_adult' => false, 'session_id' => $session->publicId,
                'failure_reason' => $session->failureReason, 'failed_at' => $this->iso($session->completedAt)];
        }
        // Dernière session terminée dont le résultat n'est plus (ou pas) réutilisable, par exemple un
        // résultat négatif à validité 0 h : on l'expose pour que le client comprenne ce qui s'est passé,
        // sans en faire une vérification valable (statut « not_verified »).
        if ($session !== null && $session->status === VerificationSession::COMPLETED) {
            return [...$base, 'status' => 'not_verified', 'is_adult' => false, 'last_session' => [
                'session_id' => $session->publicId,
                'status' => 'verified',
                'is_adult' => $session->resultIsAdult === true,
                'min_age' => $session->minAge,
                'verified_at' => $this->iso($session->resultVerifiedAt),
                'expires_at' => $this->iso($session->resultExpiresAt),
                'method' => $session->method,
            ]];
        }

        return [...$base, 'status' => 'not_verified', 'is_adult' => false];
    }

    /**
     * DELETE /api/v1/verifications?email= : droit à l'effacement (résultat et sessions, dans le périmètre).
     *
     * @return array<string, mixed>
     */
    public function erase(ApiContext $ctx, string $rawEmail, string $ip): array
    {
        $email = $this->requireEmail($rawEmail);
        $emailHash = $this->emailHash($ctx->project, $email);
        $deleted = $this->db->transaction(function () use ($ctx, $emailHash): int {
            // Livraisons de webhooks en attente ou passées : leur corps (chiffré) contient l'adresse.
            $this->deliveries->deleteForSessions($ctx->project->id, $this->sessions->publicIdsForEmail($ctx->project->id, $ctx->livemode, $emailHash));

            return $this->verifications->deleteForEmail($ctx->project->id, $ctx->livemode, $emailHash)
                + $this->sessions->deleteForEmail($ctx->project->id, $ctx->livemode, $emailHash);
        });
        $this->limiter->clear('verification_failures', $this->scopeKey($ctx->project, $ctx->livemode, $emailHash));
        $this->audit->record('verification.erased', null, $ctx->project->accountId, $ip, $ctx->project->id, [
            'livemode' => $ctx->livemode, 'count' => $deleted, 'key' => $ctx->keyLast4,
        ]);

        return ['object' => 'verification', 'email' => $email, 'deleted' => $deleted > 0, 'livemode' => $ctx->livemode];
    }

    // ------------------------------------------------------------------------------------------
    // Page hébergée
    // ------------------------------------------------------------------------------------------

    /** @return array{0: Project, 1: VerificationSession}|null */
    public function load(string $publicId): ?array
    {
        if (preg_match(VerificationSession::ID_REGEX, $publicId) !== 1) {
            return null;
        }
        $session = $this->sessions->findByPublicId($publicId);
        $project = $session === null ? null : $this->projects->findById($session->projectId);

        return $session === null || $project === null ? null : [$project, $session];
    }

    public function refresh(VerificationSession $session): VerificationSession
    {
        return $this->sessions->findById($session->id) ?? $session;
    }

    /**
     * Consentement (art. 9 RGPD) enregistré, puis premier envoi du code.
     *
     * @return string|null erreur d'envoi (voir sendCode), ou null
     */
    public function consent(Project $project, VerificationSession $session, string $locale, string $ip): ?string
    {
        if ($this->sessions->recordConsent($session->id)) {
            $this->audit->record('verification.consent', null, $project->accountId, $ip, $project->id, [
                'session' => $session->publicId, 'livemode' => $session->livemode,
            ]);
        }

        return $this->sendCode($project, $this->refresh($session), $locale, $ip);
    }

    /**
     * Envoie un nouveau code à 6 chiffres (e-mail en production ; affiché sur la page en sandbox).
     *
     * @return string|null « code_resend_wait », « code_send_limit », « throttled » ou null si envoyé
     */
    public function sendCode(Project $project, VerificationSession $session, string $locale, string $ip): ?string
    {
        if (!$session->isOpen($this->now()) || $session->consentAt === null || $session->emailVerifiedAt !== null) {
            return null;
        }
        /** @var array{ttl: int, max_attempts: int, max_sends: int, resend_interval: int} $cfg */
        $cfg = $this->config['email_code'];
        if ($session->codeSends >= $cfg['max_sends']) {
            return 'code_send_limit';
        }
        // Anti-bombardement d'e-mails par (IP, projet) (la sandbox n'envoie aucun e-mail).
        [$max, $window] = $this->rateLimits['verify_code_send_ip'];
        if ($session->livemode && !$this->limiter->attempt('verify_code_send_ip', $this->ipProjectKey($ip, $project), $max, $window)->allowed) {
            return 'throttled';
        }
        if ($session->livemode && $this->needsStrongProof($session)) {
            return $this->sendLink($project, $session, $locale, $ip);
        }
        $code = Crypto::randomDigits(6);
        if (!$this->sessions->storeCode($session->id, $this->codeHash($session, $code), $cfg['ttl'], $cfg['max_sends'], $cfg['resend_interval'])) {
            return 'code_resend_wait';
        }

        if (!$session->livemode) {
            // Sandbox : aucun e-mail n'est envoyé, le code est affiché sur la page (mode test signalé).
            $this->redis->setex($this->sandboxCodeKey($session), $cfg['ttl'], $code);

            return null;
        }
        $email = $this->sessionEmail($session);
        $minutes = intdiv($cfg['ttl'], 60);
        $projectName = $project->name;
        ($this->defer)(fn () => $this->mail->send($email, 'emails.verification_code.subject', 'verification_code', [
            'code' => $code,
            'minutes' => $minutes,
            'project' => $projectName,
        ], $locale));

        return null;
    }

    /**
     * Preuve renforcée : l'adresse cumule trop de codes erronés en 24 h, tous clients confondus
     * (tentative probable d'appropriation d'une vérification). Pas de blocage (un tiers pourrait
     * sinon priver l'adresse de toute vérification) : un lien à usage unique à forte entropie remplace
     * le code, impossible à deviner.
     */
    private function needsStrongProof(VerificationSession $session): bool
    {
        [$max, $window] = $this->rateLimits['verify_code_global'];

        return !$this->limiter->peek('verify_code_global', $this->sharedHash($this->sessionEmail($session)), $max, $window)->allowed;
    }

    private function sendLink(Project $project, VerificationSession $session, string $locale, string $ip): ?string
    {
        /** @var array{max_sends: int, resend_interval: int} $cfg */
        $cfg = $this->config['email_code'];
        $token = Crypto::randomAlnum(32);
        $ttl = (int) $this->config['magic_link_ttl'];
        if (!$this->sessions->storeCode($session->id, $this->codeHash($session, $token), $ttl, $cfg['max_sends'], $cfg['resend_interval'], 'link')) {
            return 'code_resend_wait';
        }
        $this->audit->record('verification.proof_escalated', null, $project->accountId, $ip, $project->id, [
            'session' => $session->publicId, 'livemode' => $session->livemode, 'reason' => 'global_code_failures',
        ]);
        $this->logger?->warning('verification_proof_escalated', ['project' => $project->id]);
        $email = $this->sessionEmail($session);
        $url = $this->verifyUrl . '/s/' . $session->publicId . '/confirm?' . http_build_query(['token' => $token, 'lang' => $locale], '', '&', PHP_QUERY_RFC3986);
        $minutes = intdiv($ttl, 60);
        $projectName = $project->name;
        ($this->defer)(fn () => $this->mail->send($email, 'emails.verification_link.subject', 'verification_link', [
            'url' => $url,
            'minutes' => $minutes,
            'project' => $projectName,
        ], $locale));

        return null;
    }

    /** Code affiché en sandbox (null en production ou s'il a expiré). */
    public function sandboxCode(VerificationSession $session): ?string
    {
        if ($session->livemode) {
            return null;
        }
        $code = $this->redis->get($this->sandboxCodeKey($session));

        return is_string($code) ? $code : null;
    }

    /**
     * Contrôle du code. Force brute : 5 essais par session, et au plus N codes erronés par adresse
     * (par projet et par mode, 24 h glissantes) toutes sessions confondues, faute de quoi un attaquant
     * pourrait ouvrir des sessions en série ; au-delà, la session échoue (et compte pour le blocage).
     * Essais par IP bornés en plus (sans consommer d'essai de la session).
     *
     * @return string CODE_OK, CODE_INVALID, CODE_EXPIRED, CODE_LOCKED ou « throttled »
     */
    public function verifyCode(Project $project, VerificationSession $session, string $rawCode, string $ip): string
    {
        /** @var array{max_attempts: int} $cfg */
        $cfg = $this->config['email_code'];
        [$ipMax, $ipWindow] = $this->rateLimits['verify_code_ip'];
        if (!$this->limiter->attempt('verify_code_ip', $this->ipProjectKey($ip, $project), $ipMax, $ipWindow)->allowed) {
            return self::CODE_THROTTLED;
        }
        $code = (string) preg_replace('/\s+/', '', $rawCode);
        $format = $session->proofKind === 'link' ? self::LINK_TOKEN_PATTERN : '/^\d{6}$/D';
        if (preg_match($format, $code) === 1
            && $this->sessions->attemptCode($session->id, $this->codeHash($session, $code), $cfg['max_attempts'])) {
            $this->redis->del([$this->sandboxCodeKey($session)]);
            $this->audit->record('verification.email_confirmed', null, $project->accountId, $ip, $project->id, [
                'session' => $session->publicId, 'livemode' => $session->livemode,
            ]);
            // Une vérification a pu aboutir pour ce projet entre-temps : réutilisation immédiate.
            $session = $this->refresh($session);
            $reusable = $this->reusableVerification($project, $session->livemode, $session->emailHash, $session->minAge);
            if ($reusable !== null) {
                $this->complete($project, $session, (string) $reusable['method'], 'same_client', (bool) $reusable['is_adult'],
                    $this->utc((string) $reusable['verified_at']), $this->utc((string) $reusable['expires_at']), null, null, $ip);
            }

            return self::CODE_OK;
        }

        $session = $this->refresh($session);
        if (!$session->isOpen($this->now()) || $session->emailVerifiedAt !== null) {
            return self::CODE_INVALID;
        }
        if ($session->livemode) {
            // Compteur global de l'adresse (tous clients) : déclenche la preuve renforcée, jamais un blocage.
            [$globalMax, $globalWindow] = $this->rateLimits['verify_code_global'];
            $this->limiter->attempt('verify_code_global', $this->sharedHash($this->sessionEmail($session)), $globalMax, $globalWindow);
        }
        [$emailMax, $emailWindow] = $this->rateLimits['verify_code_email'];
        $perEmail = $this->limiter->attempt('verify_code_email', $this->scopeKey($project, $session->livemode, $session->emailHash), $emailMax, $emailWindow);
        if ($session->codeAttempts >= $cfg['max_attempts'] || !$perEmail->allowed) {
            $this->failSession($project, $session, 'code_attempts_exceeded', null, $ip);

            return self::CODE_LOCKED;
        }
        if ($session->codeExpiresAt !== null && $session->codeExpiresAt <= $this->now()) {
            return self::CODE_EXPIRED;
        }

        return self::CODE_INVALID;
    }

    /**
     * Preuve d'un autre projet réutilisable pour cette session (l'utilisateur l'a autorisée, le projet
     * l'admet, elle couvre l'âge demandé). Uniquement après contrôle de l'adresse.
     *
     * @return array<string, mixed>|null
     */
    public function sharedCandidate(Project $project, VerificationSession $session): ?array
    {
        if (!$project->acceptShared || $session->emailVerifiedAt === null || !$session->isOpen($this->now())) {
            return null;
        }
        // Sandbox : entre projets d'un même compte seulement (le code y est affiché, l'adresse n'est pas prouvée).
        $candidate = $this->verifications->findShared($this->sharedHash($this->sessionEmail($session)), $session->livemode, $project->id,
            $session->livemode ? null : $project->accountId, $session->minAge);

        return $candidate !== null && self::covers($candidate, $session->minAge) ? $candidate : null;
    }

    /** L'utilisateur accepte explicitement de réutiliser sa vérification d'âge sur ce site. */
    public function acceptShared(Project $project, VerificationSession $session, string $ip): bool
    {
        $candidate = $this->sharedCandidate($project, $session);
        if ($candidate === null) {
            return false;
        }
        $expires = min($this->utc((string) $candidate['expires_at']), $this->resultExpiry($project, (bool) $candidate['is_adult'], $this->now()));

        return $this->complete($project, $session, (string) $candidate['method'], 'shared', (bool) $candidate['is_adult'],
            $this->utc((string) $candidate['verified_at']), $expires, null, 'shared', $ip);
    }

    /**
     * Exécute une méthode de vérification. Préconditions : session ouverte, consentement, adresse contrôlée.
     *
     * @param array<string, string> $input
     * @throws \InvalidArgumentException méthode inconnue ou entrée invalide
     */
    public function runMethod(Project $project, VerificationSession $session, string $methodId, array $input, bool $shareOptIn, string $ip): void
    {
        if (!$session->isOpen($this->now()) || $session->consentAt === null || $session->emailVerifiedAt === null || $session->reviewAt !== null) {
            return;
        }
        $method = $this->methods->find($project, $session->livemode, $methodId)
            ?? throw new \InvalidArgumentException('Méthode indisponible.');
        $this->sessions->setShareOptIn($session->id, $shareOptIn);
        $outcome = $method->verify($session, $input);

        if ($outcome->needsReview) {
            $this->queueReview($project, $session, $method->id(), $outcome, $ip);

            return;
        }
        if (!$outcome->verified) {
            $this->failSession($project, $session, (string) $outcome->failureReason, $method->id(), $ip);

            return;
        }
        $now = $this->now();
        $sharedHash = $shareOptIn ? $this->sharedHash($this->sessionEmail($session)) : null;
        $this->complete($project, $session, $method->id(), 'none', (bool) $outcome->isAdult, $now,
            $this->resultExpiry($project, (bool) $outcome->isAdult, $now), $sharedHash, null, $ip);
    }

    /**
     * Méthode « pièce d'identité + visage » : la capture (déjà déchiffrée et validée) est analysée par le
     * microservice ; les défis viennent du serveur (ChallengeStore), jamais du navigateur. Le choix de
     * réutilisation entre sites a été fait sur l'écran de consentement biométrique.
     *
     * @param list<string> $challenge
     */
    public function runCapture(Project $project, VerificationSession $session, string $methodId, Biometrics\CapturePayload $capture, array $challenge, string $ip): void
    {
        $this->runMethod($project, $session, $methodId, [
            'capture' => $capture,
            'challenge' => $challenge,
            'review_allowed' => $project->belowThreshold === 'review',
        ], $session->shareOptIn, $ip);
    }

    /** Échec de la méthode avant l'analyse (tirages de défis épuisés) : compte pour le blocage de l'adresse. */
    public function failCapture(Project $project, VerificationSession $session, string $reason, string $ip): void
    {
        if ($session->isOpen($this->now()) && $session->reviewAt === null) {
            $this->failSession($project, $session, $reason, Methods\LocalBiometricsProvider::ID, $ip);
        }
    }

    /**
     * Consentement explicite au traitement biométrique (art. 9 RGPD), recueilli sur un écran dédié juste
     * avant la capture, avec le choix facultatif de réutilisation entre sites.
     */
    public function recordBiometricConsent(Project $project, VerificationSession $session, bool $shareOptIn, string $ip): bool
    {
        if (!$this->sessions->recordBiometricConsent($session->id)) {
            return false;
        }
        $this->sessions->setShareOptIn($session->id, $shareOptIn);
        $this->audit->record('verification.biometric_consent', null, $project->accountId, $ip, $project->id, [
            'session' => $session->publicId, 'livemode' => $session->livemode,
        ]);

        return true;
    }

    /**
     * Revue manuelle : la session attend la décision d'un opérateur (aucun webhook avant la décision ;
     * l'API répond « pending » avec « review: true »). Seuls les signaux de la décision automatique sont
     * conservés, jamais une image.
     */
    private function queueReview(Project $project, VerificationSession $session, string $method, VerificationOutcome $outcome, string $ip): void
    {
        if ($this->reviews === null) {
            $this->failSession($project, $session, 'face_mismatch', $method, $ip);

            return;
        }
        $evidence = $outcome->evidence ?? ['face_match_score' => null, 'liveness_passed' => false, 'reasons' => []];
        $this->db->transaction(function () use ($project, $session, $method, $outcome, $evidence, $ip): void {
            if (!$this->sessions->markReview($session->id, $this->reviewTtlHours, $method)) {
                return;
            }
            $this->reviews?->create($session->id, $project->id, $session->livemode, $method, $evidence['face_match_score'],
                $evidence['liveness_passed'], $evidence['reasons'], (bool) $outcome->isAdult);
            $this->audit->record('verification.review_queued', null, $project->accountId, $ip, $project->id, [
                'session' => $session->publicId, 'livemode' => $session->livemode, 'method' => $method,
            ]);
        });
    }

    /**
     * Décision d'un opérateur sur une revue : approuvée (résultat d'âge provisoire confirmé) ou rejetée
     * (échec « manual_review_rejected »). Webhook envoyé à ce moment-là.
     */
    public function decideReview(int $reviewId, bool $approve): bool
    {
        $review = $this->reviews?->find($reviewId);
        if ($review === null || $review['status'] !== 'pending') {
            return false;
        }
        $session = $this->sessions->findById((int) $review['session_id']);
        $project = $session === null ? null : $this->projects->findById($session->projectId);
        if ($session === null || $project === null || !$session->inReview($this->now())) {
            return false;
        }
        if (!$this->reviews->decide($reviewId, $approve ? 'approved' : 'rejected')) {
            return false;
        }
        $method = (string) $review['method'];
        if (!$approve) {
            $this->failSession($project, $session, 'manual_review_rejected', $method, null);

            return true;
        }
        $now = $this->now();
        $isAdult = (bool) $review['provisional_is_adult'];
        $sharedHash = $session->shareOptIn ? $this->sharedHash($this->sessionEmail($session)) : null;

        return $this->complete($project, $session, $method, 'none', $isAdult, $now, $this->resultExpiry($project, $isAdult, $now), $sharedHash, null, null);
    }

    /**
     * Expiration d'un résultat : validité du projet (jours) s'il est positif ; durée courte (heures,
     * réglable par projet) s'il est négatif, pour que la personne puisse se faire revérifier une fois
     * l'âge atteint (la date de naissance n'est jamais conservée). 0 heure : aussitôt expiré, donc
     * jamais réutilisé.
     */
    public function resultExpiry(Project $project, bool $isAdult, \DateTimeImmutable $from): \DateTimeImmutable
    {
        return $isAdult
            ? $from->modify('+' . $project->validityDays . ' days')
            : $from->modify('+' . max(0, $project->negativeTtlHours) . ' hours');
    }

    /** @return list<VerificationMethodInterface> */
    public function availableMethods(Project $project, VerificationSession $session): array
    {
        return $this->methods->availableFor($project, $session->livemode);
    }

    /**
     * Résumé du résultat d'une session (page, jeton de retour, postMessage, webhook).
     *
     * @return array<string, mixed>
     */
    public function sessionResult(VerificationSession $session): array
    {
        $status = match ($session->effectiveStatus($this->now())) {
            VerificationSession::COMPLETED => 'verified',
            VerificationSession::FAILED => 'failed',
            VerificationSession::EXPIRED => 'expired',
            default => 'pending',
        };

        return [
            'status' => $status,
            'is_adult' => $status === 'verified' && $session->resultIsAdult === true,
            'min_age' => $session->minAge,
            'verified_at' => $status === 'verified' ? $this->iso($session->resultVerifiedAt) : null,
            'expires_at' => $status === 'verified' ? $this->iso($session->resultExpiresAt) : null,
            'method' => $session->method,
            'reused' => $session->reuse !== 'none',
            'failure_reason' => $status === 'failed' ? $session->failureReason : null,
        ];
    }

    /**
     * Un jeton de retour n'est émis que pendant une courte fenêtre après la fin de la session
     * (VERIFICATION_RETURN_TOKEN_WINDOW, 10 min par défaut) ; au-delà, le résultat est affiché sans
     * jeton. Le jeton reste réutilisable dans sa durée de vie : au client de consommer chaque « jti »
     * une seule fois et de rattacher « sub » à l'utilisateur qui a créé la session.
     */
    public function canIssueReturnToken(VerificationSession $session): bool
    {
        $now = $this->now();
        if ($session->effectiveStatus($now) === VerificationSession::PENDING) {
            return false;
        }
        $end = $session->completedAt ?? $session->expiresAt;

        return $end->modify('+' . (int) $this->config['return_token_window'] . ' seconds') >= $now;
    }

    /** URL de retour « return_url?session_id=…&token=… » (jeton frais), ou null (pas de return_url, fenêtre close). */
    public function returnUrl(Project $project, VerificationSession $session): ?string
    {
        if ($session->returnUrl === null || !$this->canIssueReturnToken($session)) {
            return null;
        }

        return $session->returnUrl . (str_contains($session->returnUrl, '?') ? '&' : '?') . http_build_query([
            'session_id' => $session->publicId,
            'token' => $this->returnToken($project, $session),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function returnToken(Project $project, VerificationSession $session): string
    {
        $result = $this->sessionResult($session);
        unset($result['failure_reason']);

        return $this->returnTokens->issue($project, $session, $result, $this->projects->signingSecret($project, $session->livemode), time());
    }

    /** Adresse masquée pour l'affichage (« j•••@e•••.be »). */
    public function maskedEmail(VerificationSession $session): string
    {
        [$local, $domain] = array_pad(explode('@', $this->sessionEmail($session), 2), 2, '');
        $labels = explode('.', $domain);
        $tld = count($labels) > 1 ? '.' . array_pop($labels) : '';
        $mask = static fn (string $part): string => mb_substr($part, 0, 1) . '•••';

        return $mask($local) . '@' . $mask(implode('.', $labels)) . $tld;
    }

    // ------------------------------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------------------------------

    /**
     * Termine la session : résultat, vérification enregistrée, audit et webhook dans une même
     * transaction (la livraison n'existe que si la session est bien terminée).
     */
    private function complete(
        Project $project,
        VerificationSession $session,
        string $method,
        string $reuse,
        bool $isAdult,
        \DateTimeImmutable $verifiedAt,
        \DateTimeImmutable $expiresAt,
        ?string $sharedHash,
        ?string $source,
        ?string $ip,
    ): bool {
        $done = $this->db->transaction(function () use ($project, $session, $method, $reuse, $isAdult, $verifiedAt, $expiresAt, $sharedHash, $source, $ip): bool {
            if (!$this->sessions->complete($session->id, $method, $reuse, $isAdult, $verifiedAt, $expiresAt, $reuse !== 'same_client')) {
                return false;
            }
            if ($reuse !== 'same_client') {
                $email = $this->sessionEmail($session);
                $this->verifications->upsert($project->id, $session->livemode, $session->emailHash,
                    $this->crypto->encrypt($email, self::verificationEmailContext($project->id, $session->livemode, $session->emailHash)),
                    $isAdult, $session->minAge, $method, $source ?? 'verification', $sharedHash, $verifiedAt, $expiresAt);
            }
            $this->audit->record('verification.completed', null, $project->accountId, $ip, $project->id, [
                'session' => $session->publicId, 'livemode' => $session->livemode, 'method' => $method,
                'result' => $isAdult ? 'adult' : 'not_adult', 'reuse' => $reuse,
            ]);
            $this->enqueueWebhook($project, $this->refresh($session), WebhookDispatcher::COMPLETED);

            return true;
        });
        if ($done) {
            $this->webhooks->wake();
        }

        return $done;
    }

    private function failSession(Project $project, VerificationSession $session, string $reason, ?string $method, ?string $ip): void
    {
        $done = $this->db->transaction(function () use ($project, $session, $reason, $method, $ip): bool {
            if (!$this->sessions->fail($session->id, $reason, $method)) {
                return false;
            }
            $this->audit->record('verification.failed', null, $project->accountId, $ip, $project->id, [
                'session' => $session->publicId, 'livemode' => $session->livemode, 'method' => $method, 'reason' => $reason,
            ]);
            $this->enqueueWebhook($project, $this->refresh($session), WebhookDispatcher::FAILED);

            return true;
        });
        if ($done) {
            /** @var array{max_failures: int, window: int} $lock */
            $lock = $this->config['failure_lock'];
            $this->limiter->attempt('verification_failures', $this->scopeKey($project, $session->livemode, $session->emailHash),
                $lock['max_failures'], $lock['window']);
            $this->webhooks->wake();
        }
    }

    private function enqueueWebhook(Project $project, VerificationSession $session, string $type): void
    {
        $result = $this->sessionResult($session);
        $this->webhooks->enqueue($project, $session->livemode, $type, [
            'session_id' => $session->publicId,
            'external_ref' => $session->externalRef,
            'email' => $this->sessionEmail($session),
            ...$result,
        ], time());
    }

    /** @return array<string, mixed> */
    private function verificationBody(string $email, VerificationSession $session): array
    {
        return [
            'object' => 'verification',
            'email' => $email,
            'status' => 'verified',
            'is_adult' => $session->resultIsAdult === true,
            'min_age' => $session->minAge,
            'verified_at' => $this->iso($session->resultVerifiedAt),
            'method' => $session->method,
            'expires_at' => $this->iso($session->resultExpiresAt),
            'livemode' => $session->livemode,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: int, 2: ?string, 3: ?string, 4: ?string}
     */
    private function validateSessionInput(Project $project, array $input): array
    {
        $errors = [];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, self::INPUT_FIELDS, true)) {
                $errors[(string) $field] = 'unknown_field';
            }
        }

        $email = is_string($input['email'] ?? null) ? self::normalizeEmail($input['email']) : null;
        if (!array_key_exists('email', $input)) {
            $errors['email'] = 'required';
        } elseif ($email === null) {
            $errors['email'] = 'invalid';
        }

        $minAge = $input['min_age'] ?? $project->minAge;
        /** @var list<int> $allowedAges */
        $allowedAges = $this->config['allowed_min_ages'];
        if (!is_int($minAge) || !in_array($minAge, $allowedAges, true)) {
            $errors['min_age'] = 'invalid';
        }

        $returnUrl = $input['return_url'] ?? null;
        if ($returnUrl !== null) {
            $urlError = is_string($returnUrl) ? $this->urls->checkUrl($returnUrl, $project->allowedOrigins) : 'invalid_url';
            if ($urlError !== null) {
                $errors['return_url'] = $urlError;
            }
        }

        $lang = $input['lang'] ?? null;
        if ($lang !== null && (!is_string($lang) || !in_array(strtolower($lang), $this->languages, true))) {
            $errors['lang'] = 'unsupported';
        }

        $externalRef = $input['external_ref'] ?? null;
        if ($externalRef !== null && (!is_string($externalRef) || preg_match(self::EXTERNAL_REF_PATTERN, $externalRef) !== 1)) {
            $errors['external_ref'] = 'invalid';
        }

        if ($errors !== []) {
            ksort($errors);
            throw new ApiException(422, 'validation_failed', $errors);
        }

        return [(string) $email, (int) $minAge, is_string($returnUrl) ? $returnUrl : null,
            is_string($lang) ? strtolower($lang) : null, is_string($externalRef) ? $externalRef : null];
    }

    private function requireEmail(string $raw): string
    {
        $email = self::normalizeEmail($raw);
        if ($email === null) {
            throw new ApiException(422, 'validation_failed', ['email' => $raw === '' ? 'required' : 'invalid']);
        }

        return $email;
    }

    /** Adresse valide, normalisée (NFC, minuscules), ou null. */
    public static function normalizeEmail(string $raw): ?string
    {
        $email = TextInput::normalize($raw);
        if ($email === null || $email === '' || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            return null;
        }

        return Crypto::normalizeEmail($email);
    }

    /**
     * Vérification du projet réutilisable pour l'âge demandé.
     *
     * @return array<string, mixed>|null
     */
    private function reusableVerification(Project $project, bool $livemode, string $emailHash, int $minAge): ?array
    {
        $verification = $this->verifications->findValid($project->id, $livemode, $emailHash);

        return $verification !== null && self::covers($verification, $minAge) ? $verification : null;
    }

    /**
     * Un résultat obtenu pour un âge minimal A répond-il pour l'âge B ? « Majeur à A » implique
     * « majeur à B » si A ≥ B ; « pas majeur à A » implique « pas majeur à B » si A ≤ B.
     *
     * @param array<string, mixed> $verification
     */
    public static function covers(array $verification, int $minAge): bool
    {
        $storedAge = (int) $verification['min_age'];

        return (bool) $verification['is_adult'] ? $storedAge >= $minAge : $storedAge <= $minAge;
    }

    private function failureLock(string $scope): \App\Core\RateLimitResult
    {
        /** @var array{max_failures: int, window: int} $lock */
        $lock = $this->config['failure_lock'];

        return $this->limiter->peek('verification_failures', $scope, $lock['max_failures'], $lock['window']);
    }

    private function emailHash(Project $project, string $email): string
    {
        return $this->crypto->hashEmail($email, $project->emailSalt);
    }

    private function sharedHash(string $email): string
    {
        return $this->crypto->hashEmail($email, self::SHARED_SALT);
    }

    /** Clé de limitation (IP /64 en IPv6, projet) : une IP partagée (CGNAT) n'épuise pas le quota des autres clients. */
    private function ipProjectKey(string $ip, Project $project): string
    {
        return IpAddress::rateLimitKey($ip) . '|' . $project->id;
    }

    private function scopeKey(Project $project, bool $livemode, string $emailHash): string
    {
        return $project->id . ':' . ($livemode ? 'live' : 'test') . ':' . $emailHash;
    }

    private function codeHash(VerificationSession $session, string $code): string
    {
        return hash_hmac('sha256', $session->publicId . ':' . $code, $this->crypto->deriveKey('email-code'), true);
    }

    private function sandboxCodeKey(VerificationSession $session): string
    {
        return 'sandbox_code:' . $session->publicId;
    }

    private function sessionEmail(VerificationSession $session): string
    {
        return $this->crypto->decrypt($session->emailEnc, self::sessionEmailContext($session->publicId));
    }

    public static function sessionEmailContext(string $publicId): string
    {
        return 'session-email:' . $publicId;
    }

    public static function verificationEmailContext(int $projectId, bool $livemode, string $emailHash): string
    {
        return 'verification-email:' . $projectId . ':' . ($livemode ? 'live' : 'test') . ':' . $emailHash;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /** Date ISO 8601 dans le fuseau de l'application (ex. 2026-09-27T20:47:00+02:00). */
    private function iso(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone($this->timezone))->format(\DATE_ATOM);
    }
}
