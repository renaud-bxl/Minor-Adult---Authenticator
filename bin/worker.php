<?php

declare(strict_types=1);

/*
 * Worker (service systemd « veriage-worker@{id} ») : livraison des webhooks signés (relances
 * exponentielles) et envoi des e-mails mis en file.
 *
 *   php bin/worker.php [--id=1] [--once] [--wait=5]
 *
 * --id   : identifiant stable du worker (liste de traitement Redis propre, reprise au redémarrage) ;
 * --once : traite ce qui est échu puis s'arrête (tests, cron de secours) ;
 * --wait : attente maximale (s) d'un réveil Redis entre deux passes.
 * SIGTERM / SIGINT : arrêt propre après le travail en cours.
 */

use App\Core\Logger;
use App\Services\MailWorker;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['id:', 'once', 'wait:']);
$workerId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($options['id'] ?? gethostname() . '-' . getmypid())) ?: 'default';
$once = isset($options['once']);
$wait = max(1, min(30, (int) ($options['wait'] ?? 5)));

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$logger = $app->logger();
$webhooks = $app->webhooks();
$mail = new MailWorker($app->queue(), $app->mailer(), $logger, $workerId, (int) $app->config->get('mail.max_attempts', 5));
$recovered = $mail->recover();
$logger->info('worker_started', ['worker' => $workerId, 'recovered' => $recovered]);
fwrite(STDOUT, "Worker {$workerId} démarré.\n");

do {
    try {
        $handled = $webhooks->processDue() + $mail->process();
        if ($handled === 0 && !$once && $running) {
            $app->queue()->waitForWake($wait);
        }
    } catch (Throwable $e) {
        // Base ou Redis momentanément indisponibles : on journalise et on réessaie plus tard.
        $logger->error('worker_iteration_failed', Logger::exceptionContext($e));
        if ($once) {
            exit(1);
        }
        sleep($wait);
        $app = App\Core\Application::boot(dirname(__DIR__));
        $webhooks = $app->webhooks();
        $mail = new MailWorker($app->queue(), $app->mailer(), $app->logger(), $workerId, (int) $app->config->get('mail.max_attempts', 5));
    }
} while ($running && !$once);

$logger->info('worker_stopped', ['worker' => $workerId]);
exit(0);
