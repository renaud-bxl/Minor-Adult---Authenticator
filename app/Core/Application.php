<?php

declare(strict_types=1);

namespace App\Core;

use App\Billing\CreditGateInterface;
use App\Billing\UnlimitedCreditGate;
use App\I18n\Formatter;
use App\I18n\LocaleNegotiator;
use App\I18n\Translator;
use App\Models\ProjectRepository;
use App\Models\WebhookDeliveryRepository;
use App\Models\WebhookEndpointRepository;
use App\Services\Mailer;
use App\Services\MailSender;
use App\Services\QueuedMailSender;
use App\Verification\Biometrics\BiometricsClient;
use App\Verification\Biometrics\BiometricsTransport;
use App\Verification\Biometrics\CaptureCipher;
use App\Verification\Biometrics\ChallengeStore;
use App\Verification\Biometrics\CurlBiometricsTransport;
use App\Verification\MethodRegistry;
use App\Verification\Methods\LocalBiometricsProvider;
use App\Verification\Methods\MockProvider;
use App\Verification\UrlGuard;
use App\Verification\Webhooks\CurlWebhookTransport;
use App\Verification\Webhooks\WebhookDispatcher;
use App\Verification\Webhooks\WebhookTransport;
use Dotenv\Dotenv;
use Predis\Client as RedisClient;
use Predis\ClientInterface;

/**
 * Racine de composition : configuration et services partagés, instanciés à la demande.
 * Une instance correspond à une requête (modèle PHP « shared-nothing ») ; les tests en créent une
 * par requête simulée.
 */
final class Application
{
    private static ?self $current = null;

    private ?Logger $logger = null;
    private ?Database $db = null;
    private ?ClientInterface $redis = null;
    private ?Translator $translator = null;
    private ?LocaleNegotiator $negotiator = null;
    private ?Formatter $formatter = null;
    private ?View $view = null;
    private ?Crypto $crypto = null;
    private ?RateLimiter $rateLimiter = null;
    private ?Mailer $mailer = null;
    private ?Router $router = null;
    private ?HostMap $hostMap = null;
    private ?RedisQueue $queue = null;
    private ?CreditGateInterface $creditGate = null;
    private ?BiometricsTransport $biometricsTransport = null;

    /** @var list<callable(): void> */
    private array $deferred = [];

    public function __construct(public readonly string $basePath, public readonly Config $config)
    {
    }

    /**
     * Charge le .env (sans écraser les variables déjà définies par l'environnement), la configuration
     * et le fuseau horaire, puis enregistre l'instance courante (utilisée par les helpers globaux).
     *
     * @param array<string, mixed> $overrides surcharges de configuration en notation pointée
     */
    public static function boot(string $basePath, array $overrides = []): self
    {
        Dotenv::createImmutable($basePath)->safeLoad();
        $config = Config::fromDirectory($basePath . '/config', $overrides);
        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Brussels'));

        return self::$current = new self($basePath, $config);
    }

    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('Application non initialisée (app/bootstrap.php).');
    }

    /** Chemin absolu à partir d'un chemin de configuration (relatif à la racine du projet ou absolu). */
    public function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->basePath . '/' . $path;
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger(
            $this->path((string) $this->config->get('app.log_path')),
            (string) $this->config->get('app.log_level', 'info'),
            (int) $this->config->get('app.log_retention_days', 30),
        );
    }

    public function db(): Database
    {
        /** @var array{host: string, port: int, database: string, username: string, password: string} $config */
        $config = $this->config->get('database');

        return $this->db ??= new Database($config);
    }

    public function redis(): ClientInterface
    {
        if ($this->redis === null) {
            $parameters = [
                'scheme' => 'tcp',
                'host' => (string) $this->config->get('redis.host'),
                'port' => (int) $this->config->get('redis.port'),
                'database' => (int) $this->config->get('redis.database'),
            ];
            $password = (string) $this->config->get('redis.password');
            if ($password !== '') {
                $parameters['password'] = $password;
            }
            $this->redis = new RedisClient($parameters, ['prefix' => (string) $this->config->get('redis.prefix')]);
        }

        return $this->redis;
    }

    public function translator(): Translator
    {
        /** @var list<string> $enabled */
        $enabled = $this->config->get('i18n.enabled');
        /** @var list<string> $domains */
        $domains = $this->config->get('i18n.domains');

        return $this->translator ??= new Translator(
            $this->path((string) $this->config->get('i18n.path')),
            $enabled,
            $domains,
            (string) $this->config->get('i18n.fallback'),
            $this->logger(),
        );
    }

    public function negotiator(): LocaleNegotiator
    {
        return $this->negotiator ??= new LocaleNegotiator($this->translator()->enabled(), $this->translator()->fallback());
    }

    public function formatter(): Formatter
    {
        return $this->formatter ??= new Formatter((string) $this->config->get('app.timezone'));
    }

    public function view(): View
    {
        return $this->view ??= new View($this->basePath . '/app/Views');
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto(
            Crypto::decodeKey((string) $this->config->get('security.crypto_key')),
            Crypto::decodeKey((string) $this->config->get('app.key')),
            (int) $this->config->get('security.crypto_key_version', 1),
            Crypto::parseKeyring((string) $this->config->get('security.crypto_previous_keys', '')),
        );
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= new RateLimiter($this->redis(), $this->crypto());
    }

    public function mailer(): Mailer
    {
        /** @var array<string, string|int> $config */
        $config = $this->config->get('mail');

        return $this->mailer ??= new Mailer(
            $config,
            (string) $this->config->get('app.env'),
            $this->path((string) $config['outbox']),
            $this->view(),
            $this->translator(),
        );
    }

    public function hostMap(): HostMap
    {
        return $this->hostMap ??= HostMap::fromConfig($this->config);
    }

    public function queue(): RedisQueue
    {
        return $this->queue ??= new RedisQueue($this->redis(), $this->crypto());
    }

    /** Envoi des e-mails : file Redis (MAIL_QUEUE=redis, worker) ou envoi direct (sync). */
    public function mailSender(): MailSender
    {
        return $this->config->get('mail.queue') === 'redis' ? new QueuedMailSender($this->queue()) : $this->mailer();
    }

    public function urlGuard(): UrlGuard
    {
        return new UrlGuard((bool) $this->config->get('verification.allow_private_network'));
    }

    public function methods(): MethodRegistry
    {
        return new MethodRegistry(new MockProvider(), $this->biometricsProvider());
    }

    /**
     * Client du microservice biométrique, ou null si la méthode n'est pas activée (BIOMETRICS_ENABLED)
     * ou pas configurée (secret absent) : la méthode n'est alors pas proposée.
     */
    public function biometricsClient(): ?BiometricsClient
    {
        $secret = (string) $this->config->get('biometrics.secret');
        if ($this->config->get('biometrics.enabled') !== true || strlen($secret) < 32) {
            return null;
        }

        return new BiometricsClient(
            $this->biometricsTransport ?? new CurlBiometricsTransport((int) $this->config->get('biometrics.connect_timeout'), (int) $this->config->get('biometrics.timeout')),
            (string) $this->config->get('biometrics.url'),
            $secret,
        );
    }

    public function biometricsProvider(): LocalBiometricsProvider
    {
        /** @var array<string, int|float> $liveness */
        $liveness = $this->config->get('biometrics.liveness');

        return new LocalBiometricsProvider(
            $this->biometricsClient(),
            (float) $this->config->get('biometrics.face_match_threshold'),
            (float) $this->config->get('biometrics.face_review_threshold'),
            $liveness,
            (string) $this->config->get('app.timezone'),
            $this->logger(),
        );
    }

    /** Remplace le transport vers le microservice (tests : faux microservice). */
    public function setBiometricsTransport(BiometricsTransport $transport): void
    {
        $this->biometricsTransport = $transport;
    }

    public function challenges(): ChallengeStore
    {
        return new ChallengeStore($this->redis(), (int) $this->config->get('biometrics.challenge.ttl'), (int) $this->config->get('biometrics.challenge.max_attempts'));
    }

    public function captureCipher(): CaptureCipher
    {
        return new CaptureCipher($this->crypto()->deriveKey('capture-upload'));
    }

    public function webhooks(?WebhookTransport $transport = null): WebhookDispatcher
    {
        /** @var array{max_attempts: int, base_delay: int, max_delay: int, connect_timeout: int, timeout: int} $config */
        $config = $this->config->get('verification.webhooks');
        $db = $this->db();

        return new WebhookDispatcher(
            new WebhookEndpointRepository($db),
            new WebhookDeliveryRepository($db),
            new ProjectRepository($db, $this->crypto()),
            $this->crypto(),
            $this->urlGuard(),
            $transport ?? new CurlWebhookTransport((bool) $this->config->get('verification.allow_private_network')),
            $this->queue(),
            $this->logger(),
            $config,
        );
    }

    /** Point d'extension de la facturation (phase 6) : 402 « insufficient_credits » en cas de refus. */
    public function creditGate(): CreditGateInterface
    {
        return $this->creditGate ??= new UnlimitedCreditGate();
    }

    public function setCreditGate(CreditGateInterface $gate): void
    {
        $this->creditGate = $gate;
    }

    public function newSession(): Session
    {
        $secure = (bool) $this->config->get('security.session.secure_cookie', true);

        return new Session(
            new RedisSessionHandler($this->redis(), 60 * (int) $this->config->get('security.session.idle_minutes')),
            // Le préfixe __Host- impose Secure, Path=/ et l'absence de Domain : cookie non partageable entre sous-domaines.
            $secure ? '__Host-veriage_session' : 'veriage_session',
            $secure,
            60 * (int) $this->config->get('security.session.absolute_minutes'),
        );
    }

    public function router(): Router
    {
        if ($this->router === null) {
            $this->router = new Router();
            (require $this->basePath . '/app/routes.php')($this->router);
        }

        return $this->router;
    }

    /**
     * Reporte un traitement après l'envoi de la réponse (e-mails transactionnels notamment).
     *
     * Deux raisons : la durée de la réponse ne dépend plus de l'existence d'un compte ni du temps
     * SMTP (anti-énumération par le temps), et l'utilisateur n'attend pas le serveur de messagerie.
     * En production (PHP-FPM), public/index.php ferme la connexion (fastcgi_finish_request) avant
     * d'exécuter ces traitements. Les e-mails eux-mêmes passent par mailSender() : file Redis chiffrée
     * et worker avec relances en production (MAIL_QUEUE=redis), envoi direct en développement (sync).
     *
     * @param callable(): void $task
     */
    public function defer(callable $task): void
    {
        $this->deferred[] = $task;
    }

    /**
     * Exécute les traitements reportés, dans l'ordre. Un échec est journalisé (sans donnée
     * personnelle) et n'empêche pas les suivants.
     */
    public function runDeferred(): void
    {
        while (($task = array_shift($this->deferred)) !== null) {
            try {
                $task();
            } catch (\Throwable $e) {
                $this->logger()->error('deferred_task_failed', Logger::exceptionContext($e));
            }
        }
    }

    /** @return array{0: int, 1: int} [tentatives maximales, fenêtre en secondes] */
    public function rateLimit(string $name): array
    {
        $limit = $this->config->get('security.rate_limits.' . $name);
        if (!is_array($limit) || count($limit) !== 2) {
            throw new \InvalidArgumentException('Limite de débit inconnue : ' . $name);
        }

        return [(int) $limit[0], (int) $limit[1]];
    }
}
