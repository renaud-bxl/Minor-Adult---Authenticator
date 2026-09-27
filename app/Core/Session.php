<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session serveur (données en JSON dans un \SessionHandlerInterface, Redis en production).
 *
 * Choix de conception : on n'utilise pas session_start() afin de maîtriser explicitement le cookie
 * et le cycle de vie (aucun état global, sessions rejouables en test). Garanties :
 * - mode strict : un identifiant inconnu ou mal formé n'est jamais adopté (anti-fixation) ;
 * - régénération de l'identifiant à la connexion et invalidation à la déconnexion ;
 * - expiration d'inactivité (TTL du stockage) et durée de vie absolue ;
 * - aucune session n'est créée pour un visiteur tant que rien n'y est écrit (robots, pages sans formulaire) ;
 * - JSON plutôt que unserialize() : aucune désérialisation d'objets.
 */
final class Session
{
    private const ID_PATTERN = '/^[a-f0-9]{64}$/';

    private string $id = '';

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> messages flash lisibles pendant cette requête */
    private array $flashNow = [];

    private bool $persisted = false;

    private bool $dirty = false;

    public function __construct(
        private readonly \SessionHandlerInterface $handler,
        private readonly string $cookieName,
        private readonly bool $secureCookie,
        private readonly int $absoluteLifetimeSeconds,
    ) {
    }

    public function start(?string $cookieId): void
    {
        if ($cookieId !== null && preg_match(self::ID_PATTERN, $cookieId) === 1) {
            $raw = $this->handler->read($cookieId);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($data) && is_int($data['_created'] ?? null)) {
                $this->id = $cookieId;
                $this->data = $data;
                $this->persisted = true;
            }
        }

        if ($this->persisted && time() - (int) $this->data['_created'] > $this->absoluteLifetimeSeconds) {
            $this->handler->destroy($this->id);
            $this->persisted = false;
        }

        if (!$this->persisted) {
            $this->id = self::newId();
            $this->data = ['_created' => time()];
        }

        $flash = $this->data['_flash'] ?? [];
        $this->flashNow = is_array($flash) ? $flash : [];
        if (array_key_exists('_flash', $this->data)) {
            unset($this->data['_flash']);
            $this->dirty = true;
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    /** Message disponible uniquement lors de la prochaine requête. */
    public function flash(string $key, mixed $value): void
    {
        $flash = $this->data['_flash'] ?? [];
        $flash[$key] = $value;
        $this->set('_flash', $flash);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashNow[$key] ?? $default;
    }

    /** Nouvel identifiant, données conservées (à appeler à chaque changement de privilège). */
    public function regenerate(): void
    {
        if ($this->persisted) {
            $this->handler->destroy($this->id);
        }
        $this->id = self::newId();
        $this->persisted = false;
        $this->dirty = true;
    }

    /** Vide la session et change d'identifiant (déconnexion). */
    public function invalidate(): void
    {
        $this->regenerate();
        $this->data = ['_created' => time()];
        $this->flashNow = [];
    }

    /** Persiste la session. Renvoie false si rien n'est à persister (visiteur sans état). */
    public function save(): bool
    {
        if (!$this->persisted && !$this->dirty) {
            return false;
        }
        $this->handler->write($this->id, json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->persisted = true;
        $this->dirty = false;

        return true;
    }

    /** Valeur de l'en-tête Set-Cookie : cookie de session navigateur, HttpOnly, SameSite=Lax. */
    public function cookieHeader(): string
    {
        return $this->cookieName . '=' . $this->id . '; Path=/; HttpOnly; SameSite=Lax'
            . ($this->secureCookie ? '; Secure' : '');
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
