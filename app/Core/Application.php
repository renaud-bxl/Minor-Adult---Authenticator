<?php

declare(strict_types=1);

namespace App\Core;

use App\I18n\Formatter;
use App\I18n\LocaleNegotiator;
use App\I18n\Translator;
use App\Services\Mailer;
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
