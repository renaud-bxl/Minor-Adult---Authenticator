<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Database;
use App\Core\Logger;
use App\Models\AccountRepository;
use App\Models\AuditLog;
use App\Models\UserRepository;
use App\Models\UserTokenRepository;

/**
 * Logique métier de l'authentification des comptes clients.
 *
 * Anti-énumération : l'inscription avec une adresse déjà connue produit la même réponse (l'e-mail
 * reçu par le titulaire l'informe de la tentative), la demande de réinitialisation répond toujours
 * de la même façon, et la connexion échoue avec un message unique et une durée comparable.
 * Les e-mails sont envoyés après validation de la transaction ; un échec d'envoi est journalisé
 * (sans destinataire) sans révéler d'information à l'appelant.
 */
final class AuthService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly UserTokenRepository $tokens,
        private readonly AuditLog $audit,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly int $verificationTtl,
        private readonly int $resetTtl,
    ) {
    }

    public static function fromApplication(Application $app): self
    {
        $db = $app->db();
        /** @var array{memory_cost: int, time_cost: int, threads: int} $argon */
        $argon = $app->config->get('security.password.argon2');

        return new self(
            $db,
            new UserRepository($db),
            new AccountRepository($db),
            new UserTokenRepository($db),
            new AuditLog($db),
            new PasswordHasher($argon),
            $app->mailer(),
            $app->logger(),
            (int) $app->config->get('security.tokens.email_verification_ttl'),
            (int) $app->config->get('security.tokens.password_reset_ttl'),
        );
    }

    public function register(string $company, string $email, string $password, string $locale, string $ip): void
    {
        // Hachage systématique, avant de savoir si l'adresse existe : même coût dans les deux cas.
        $hash = $this->hasher->hash($password);

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            $this->audit->record('auth.register_existing_email', (int) $existing['id'], null, $ip);
            $this->sendMail($email, 'emails.account_exists.subject', 'account_exists', [
                'loginUrl' => absolute_url(url('/login', $locale)),
                'resetUrl' => absolute_url(url('/forgot-password', $locale)),
            ], $locale);

            return;
        }

        try {
            [$userId, $token] = $this->db->transaction(function () use ($company, $email, $hash, $locale, $ip): array {
                $accountId = $this->accounts->create($company, $locale);
                $userId = $this->users->create($email, $hash);
                $this->accounts->addMember($accountId, $userId, AccountRepository::ROLE_OWNER);
                $this->audit->record('auth.registered', $userId, $accountId, $ip);

                return [$userId, $this->tokens->issue($userId, UserTokenRepository::EMAIL_VERIFICATION, $this->verificationTtl)];
            });
        } catch (\PDOException $e) {
            // Inscription concurrente avec la même adresse : même réponse que ci-dessus, sans e-mail en double.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return;
            }
            throw $e;
        }

        $this->sendVerification($email, $token, $locale);
        $this->logger->info('user_registered', ['user_id' => $userId]);
    }

    public function attemptLogin(string $email, string $password, string $ip): LoginResult
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->hasher->simulateVerification();
            $this->audit->record('auth.login_failed', null, null, $ip);

            return LoginResult::invalid();
        }

        $userId = (int) $user['id'];
        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->audit->record('auth.login_failed', $userId, null, $ip);

            return LoginResult::invalid();
        }
        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->rehashPassword($userId, $this->hasher->hash($password));
        }
        if ($user['email_verified_at'] === null) {
            return LoginResult::unverified($user);
        }

        $account = $this->accounts->findPrimaryForUser($userId);
        if ($account === null) {
            throw new \RuntimeException('Utilisateur sans compte rattaché : ' . $userId);
        }
        $this->users->touchLastLogin($userId);
        $this->audit->record('auth.login', $userId, (int) $account['id'], $ip);

        return LoginResult::success($user, $account);
    }

    /** @param array<string, mixed> $user */
    public function resendVerification(array $user, string $locale): void
    {
        $token = $this->tokens->issue((int) $user['id'], UserTokenRepository::EMAIL_VERIFICATION, $this->verificationTtl);
        $this->sendVerification((string) $user['email'], $token, $locale);
    }

    public function verifyEmail(string $token, string $ip): bool
    {
        if ($token === '') {
            return false;
        }
        $userId = $this->tokens->consume(UserTokenRepository::EMAIL_VERIFICATION, $token);
        if ($userId === null) {
            return false;
        }
        $this->users->markEmailVerified($userId);
        $this->audit->record('auth.email_verified', $userId, null, $ip);

        return true;
    }

    public function requestPasswordReset(string $email, string $locale, string $ip): void
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return;
        }
        $userId = (int) $user['id'];
        $token = $this->tokens->issue($userId, UserTokenRepository::PASSWORD_RESET, $this->resetTtl);
        $this->audit->record('auth.password_reset_requested', $userId, null, $ip);
        $this->sendMail((string) $user['email'], 'emails.password_reset.subject', 'password_reset', [
            'resetUrl' => absolute_url(url('/reset-password', $locale, ['token' => $token])),
            'minutes' => intdiv($this->resetTtl, 60),
        ], $locale);
    }

    /**
     * Utilisateur associé à un jeton de réinitialisation encore valide (sans le consommer).
     *
     * @return array<string, mixed>|null
     */
    public function userForResetToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $userId = $this->tokens->peek(UserTokenRepository::PASSWORD_RESET, $token);

        return $userId === null ? null : $this->users->findById($userId);
    }

    /**
     * Change le mot de passe si le jeton est valide. Effets : jeton consommé, autres jetons de
     * réinitialisation révoqués, toutes les sessions existantes invalidées (auth_version), adresse
     * considérée comme vérifiée (le lien reçu par e-mail en apporte la preuve).
     */
    public function resetPassword(string $token, string $password, string $locale, string $ip): bool
    {
        $hash = $this->hasher->hash($password);
        $user = $this->db->transaction(function () use ($token, $hash, $ip): ?array {
            $userId = $this->tokens->consume(UserTokenRepository::PASSWORD_RESET, $token);
            if ($userId === null) {
                return null;
            }
            $this->users->changePassword($userId, $hash);
            $this->users->markEmailVerified($userId);
            $this->tokens->revokeAll($userId, UserTokenRepository::PASSWORD_RESET);
            $this->audit->record('auth.password_reset', $userId, null, $ip);

            return $this->users->findById($userId);
        });
        if ($user === null) {
            return false;
        }

        $this->sendMail((string) $user['email'], 'emails.password_changed.subject', 'password_changed', [
            'resetUrl' => absolute_url(url('/forgot-password', $locale)),
        ], $locale);

        return true;
    }

    private function sendVerification(string $email, string $token, string $locale): void
    {
        $this->sendMail($email, 'emails.verify_email.subject', 'verify_email', [
            'verifyUrl' => absolute_url(url('/verify-email', $locale, ['token' => $token])),
            'hours' => intdiv($this->verificationTtl, 3600),
        ], $locale);
    }

    /** @param array<string, mixed> $data */
    private function sendMail(string $to, string $subjectKey, string $template, array $data, string $locale): void
    {
        try {
            $this->mailer->send($to, $subjectKey, $template, $data, $locale);
        } catch (\Throwable $e) {
            $this->logger->error('mail_send_failed', ['template' => $template, ...Logger::exceptionContext($e)]);
        }
    }
}
