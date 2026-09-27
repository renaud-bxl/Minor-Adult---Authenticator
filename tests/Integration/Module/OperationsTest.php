<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Core\Crypto;
use App\Models\ProjectRepository;
use App\Services\KeyRotation;
use App\Services\MailWorker;
use App\Services\ProjectAdmin;
use App\Services\Purger;
use App\Services\QueuedMailSender;
use App\Verification\VerificationService;
use Tests\Support\TestApplication;

/** Exploitation : file d'e-mails chiffrée + worker, administration des projets, purge RGPD. */
final class OperationsTest extends ModuleTestCase
{
    public function testQueuedMailIsEncryptedInRedisAndSentByTheWorker(): void
    {
        $app = TestApplication::boot(['mail.queue' => 'redis']);
        $sender = $app->mailSender();
        self::assertInstanceOf(QueuedMailSender::class, $sender);
        $sender->send('secret.person@example.be', 'emails.verification_code.subject', 'verification_code', ['code' => '123456', 'minutes' => 10, 'project' => 'Shop'], 'fr');

        $raw = $this->app->redis()->lrange('queue:mail', 0, -1);
        self::assertCount(1, $raw);
        self::assertStringNotContainsString('secret.person', (string) base64_decode($raw[0]));
        self::assertStringNotContainsString('123456', (string) base64_decode($raw[0]));
        self::assertSame([], TestApplication::outbox(), 'rien n\'est envoyé pendant la requête');

        $worker = new MailWorker($app->queue(), $app->mailer(), $app->logger(), 'test', 3);
        self::assertSame(1, $worker->process());
        self::assertCount(1, TestApplication::outbox());
        self::assertStringContainsString('To: secret.person@example.be', TestApplication::outbox()[0]);
        self::assertSame(0, $app->queue()->size('mail'));
        self::assertSame([], $this->app->redis()->lrange('queue:mail:processing:test', 0, -1), 'acquitté');
    }

    public function testAccountEmailsAlsoGoThroughTheQueue(): void
    {
        $client = new \Tests\Support\HttpClient('203.0.113.10', null, ['mail.queue' => 'redis']);
        $client->submit('/en/register', '/en/register', ['company' => 'Queue SA', 'email' => 'queue@acme.test', 'password' => 'quiet river stones at dawn', 'password_confirmation' => 'quiet river stones at dawn']);
        self::assertSame([], TestApplication::outbox());
        self::assertSame(1, $this->app->queue()->size('mail'));
        $app = TestApplication::boot();
        (new MailWorker($app->queue(), $app->mailer(), $app->logger(), 'auth', 3))->process();
        self::assertStringContainsString('/en/verify-email?token=', TestApplication::outbox()[0]);
    }

    public function testMailFailureIsRetriedLaterAndCrashedJobsAreRecovered(): void
    {
        $app = TestApplication::boot(['mail.queue' => 'redis', 'mail.driver' => 'smtp', 'mail.port' => 1, 'mail.encryption' => 'none']);
        $app->mailSender()->send('a@example.be', 'emails.verification_code.subject', 'verification_code', ['code' => '1', 'minutes' => 1, 'project' => 'x'], 'en');
        $worker = new MailWorker($app->queue(), $app->mailer(), $app->logger(), 'w1', 3);
        $worker->process();
        self::assertSame(1, (int) $this->app->redis()->zcard('queue:mail:delayed'), 'nouvelle tentative différée');
        self::assertStringContainsString('mail_send_failed', implode("\n", TestApplication::logLines()));
        self::assertStringNotContainsString('a@example.be', implode("\n", TestApplication::logLines()), 'destinataire jamais journalisé');

        // Travail réservé puis worker arrêté brutalement : remis en file au redémarrage.
        $this->app->redis()->del(['queue:mail:delayed']);
        $app->mailSender()->send('b@example.be', 'emails.verification_code.subject', 'verification_code', ['code' => '1', 'minutes' => 1, 'project' => 'x'], 'en');
        self::assertNotNull($app->queue()->reserve('mail', 'w2'));
        self::assertSame(0, (int) $this->app->redis()->llen('queue:mail'));
        self::assertSame(1, (new MailWorker($app->queue(), $app->mailer(), $app->logger(), 'w2', 3))->recover());
        self::assertSame(1, (int) $this->app->redis()->llen('queue:mail'));
    }

    public function testProjectAdminValidatesOriginsAndWebhooks(): void
    {
        $admin = ProjectAdmin::fromApplication($this->app);
        $account = $admin->createAccount('Acme');
        $created = $admin->create($account, 'Acme shop', ['HTTPS://Shop.Example:443', 'https://*.acme.example', 'https://shop.example'], 21, 180, ['live' => 'https://shop.example/hook']);
        self::assertSame(['https://shop.example', 'https://*.acme.example'], $created['project']->allowedOrigins, 'normalisées, dédoublonnées');
        self::assertSame(21, $created['project']->minAge);
        self::assertMatchesRegularExpression('/^sk_test_[A-Za-z0-9]{40}$/', $created['keys']['test']);
        self::assertMatchesRegularExpression('/^sk_live_[A-Za-z0-9]{40}$/', $created['keys']['live']);
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $created['secrets']['test']);
        $row = $this->app->db()->fetchOne('SELECT signing_secret_test_enc FROM projects WHERE id = ?', [$created['project']->id]);
        self::assertStringNotContainsString($created['secrets']['test'], (string) $row['signing_secret_test_enc'], 'secret chiffré');

        $refused = [
            [['http://shop.example'], []],
            [['https://10.0.0.1'], []],
            [['https://localhost'], []],
            [['https://*.com'], []],
            [['https://shop.example/path'], []],
            [['https://shop.example'], ['test' => 'https://evil.example/hook']],
            [['https://shop.example'], ['test' => 'http://shop.example/hook']],
            [[], []],
        ];
        foreach ($refused as [$origins, $webhooks]) {
            try {
                $admin->create($account, 'X', $origins, null, null, $webhooks);
                self::fail('refus attendu : ' . json_encode([$origins, $webhooks]));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $admin->create($account, 'X', ['https://shop.example'], 17);
    }

    public function testKeyRotationRevokesPreviousKeys(): void
    {
        $p = $this->createProject();
        $admin = ProjectAdmin::fromApplication($this->app);
        $new = $admin->rotateKey($p['project'], false);
        self::assertSame(401, $this->api($p['keys']['test'], 'GET', '/api/v1/verifications?email=a@b.be')->status());
        self::assertSame(200, $this->api($new, 'GET', '/api/v1/verifications?email=a@b.be')->status());
        self::assertSame(200, $this->api($p['keys']['live'], 'GET', '/api/v1/verifications?email=a@b.be')->status(), 'autre mode intact');
    }

    public function testCryptoKeyRotationRewritesEveryCiphertext(): void
    {
        $db = $this->app->db();
        $p = $this->createProject(webhooks: ['test' => 'https://shop.example/hooks']);
        ProjectAdmin::fromApplication($this->app)->rotateSecret($p['project'], true, 3600);
        $this->completeFlow($this->newSession($p['keys']['test'], 'rotate@example.be')['session_id']);
        $this->newSession($p['keys']['test'], 'pending@example.be');

        $oldKey = Crypto::decodeKey((string) $this->app->config->get('security.crypto_key'));
        $hashKey = Crypto::decodeKey((string) $this->app->config->get('app.key'));
        $rotated = new Crypto(random_bytes(32), $hashKey, 2, [1 => $oldKey]);
        $counts = (new KeyRotation($db, $rotated))->run();
        self::assertSame([
            'projects.signing_secret_test_enc' => 1, 'projects.previous_secret_test_enc' => 0,
            'projects.signing_secret_live_enc' => 1, 'projects.previous_secret_live_enc' => 1,
            'verifications.email_enc' => 1, 'verification_sessions.email_enc' => 2, 'webhook_deliveries.payload_enc' => 1,
        ], $counts);
        self::assertSame(0, array_sum((new KeyRotation($db, $rotated))->run()), 'idempotent');

        // Tout est désormais lisible avec la seule nouvelle clé (ancienne retirée du trousseau).
        $newOnly = new Crypto((new \ReflectionProperty(Crypto::class, 'encryptionKey'))->getValue($rotated), $hashKey, 2);
        $repository = new ProjectRepository($db, $newOnly);
        $project = $repository->findById($p['project']->id);
        self::assertNotNull($project);
        self::assertSame($p['secrets']['test'], $repository->signingSecrets($project, false)[0]);
        self::assertSame($p['secrets']['live'], $repository->signingSecrets($project, true)[1], 'ancien secret en recouvrement');
        $verification = $db->fetchOne('SELECT * FROM verifications');
        self::assertSame('rotate@example.be', $newOnly->decrypt((string) $verification['email_enc'],
            VerificationService::verificationEmailContext((int) $verification['project_id'], (bool) $verification['livemode'], (string) $verification['email_hash'])));
        foreach ($db->fetchAll('SELECT email_enc, public_id FROM verification_sessions') as $row) {
            self::assertStringEndsWith('@example.be', $newOnly->decrypt((string) $row['email_enc'], VerificationService::sessionEmailContext((string) $row['public_id'])));
        }
    }

    public function testPurgeAppliesRetentionRules(): void
    {
        $db = $this->app->db();
        $p = $this->createProject();
        $open = $this->newSession($p['keys']['test'], 'open@example.be');
        $old = $this->newSession($p['keys']['test'], 'old@example.be');
        $this->completeFlow($old['session_id']);
        $db->execute('UPDATE verification_sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE public_id = ?', [$open['session_id']]);
        $db->execute('UPDATE verification_sessions SET created_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE public_id = ?', [$old['session_id']]);
        $db->execute('UPDATE verifications SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');

        // Compte jamais validé depuis 8 jours, compte validé, et compte récent non validé.
        foreach ([['stale@acme.test', null, 8], ['ok@acme.test', 'UTC_TIMESTAMP()', 8], ['fresh@acme.test', null, 1]] as [$email, $verified, $days]) {
            $accountId = $db->insert('INSERT INTO accounts (name, locale, created_at, updated_at) VALUES (?, "fr", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$email]);
            $userId = $db->insert(
                'INSERT INTO users (email, password_hash, email_verified_at, created_at, updated_at) VALUES (?, "x", ' . ($verified ?? 'NULL') . ', UTC_TIMESTAMP() - INTERVAL ? DAY, UTC_TIMESTAMP())',
                [$email, $days],
            );
            $db->execute('INSERT INTO account_users (account_id, user_id, role, created_at) VALUES (?, ?, "owner", UTC_TIMESTAMP())', [$accountId, $userId]);
            $db->execute('INSERT INTO user_tokens (user_id, type, token_hash, expires_at, created_at) VALUES (?, "email_verification", ?, UTC_TIMESTAMP() - INTERVAL 2 DAY, UTC_TIMESTAMP())', [$userId, random_bytes(32)]);
        }
        $db->execute('INSERT INTO audit_log (action, created_at) VALUES ("old", UTC_TIMESTAMP() - INTERVAL 400 DAY)');

        $counts = Purger::fromApplication($this->app)->run();
        self::assertSame(1, $counts['sessions_expired']);
        self::assertSame(1, $counts['sessions_deleted']);
        self::assertSame(1, $counts['verifications_deleted']);
        self::assertSame(1, $counts['unverified_accounts_deleted']);
        self::assertSame(3, $counts['tokens_deleted']);
        self::assertSame(1, $counts['audit_deleted']);
        self::assertSame('expired', $db->fetchOne('SELECT status FROM verification_sessions WHERE public_id = ?', [$open['session_id']])['status']);
        self::assertSame(['ok@acme.test', 'fresh@acme.test'], array_column($db->fetchAll('SELECT email FROM users ORDER BY id'), 'email'));
        self::assertNull($db->fetchOne('SELECT id FROM accounts WHERE name = "stale@acme.test"'));
    }
}
