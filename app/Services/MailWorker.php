<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\RedisQueue;

/**
 * Envoi des e-mails mis en file (QueuedMailSender). Échec : nouvelle tentative différée
 * (1, 2, 4, 8… minutes) jusqu'au plafond, puis abandon journalisé. Le destinataire n'est jamais
 * journalisé.
 */
final class MailWorker
{
    public function __construct(
        private readonly RedisQueue $queue,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly string $workerId,
        private readonly int $maxAttempts,
    ) {
    }

    public function recover(): int
    {
        return $this->queue->recover(QueuedMailSender::QUEUE, $this->workerId);
    }

    /** @return int nombre de travaux traités */
    public function process(int $limit = 20): int
    {
        $this->queue->promoteDelayed(QueuedMailSender::QUEUE);
        $done = 0;
        while ($done < $limit && ($reserved = $this->queue->reserve(QueuedMailSender::QUEUE, $this->workerId)) !== null) {
            $done++;
            $job = $reserved['job'];
            if (!is_string($job['to'] ?? null) || !is_string($job['template'] ?? null)) {
                $this->logger->error('mail_job_invalid', []);
                $this->queue->ack(QueuedMailSender::QUEUE, $this->workerId, $reserved['raw']);
                continue;
            }
            try {
                $this->mailer->send(
                    $job['to'],
                    (string) ($job['subject'] ?? ''),
                    $job['template'],
                    is_array($job['data'] ?? null) ? $job['data'] : [],
                    (string) ($job['locale'] ?? 'en'),
                );
                $this->logger->info('mail_sent', ['template' => $job['template']]);
            } catch (\Throwable $e) {
                $attempts = (int) ($job['attempts'] ?? 0) + 1;
                $final = $attempts >= $this->maxAttempts;
                $this->logger->error('mail_send_failed', ['template' => $job['template'], 'attempt' => $attempts, 'final' => $final, ...Logger::exceptionContext($e)]);
                if (!$final) {
                    $this->queue->push(QueuedMailSender::QUEUE, [...$job, 'attempts' => $attempts], 60 * (2 ** ($attempts - 1)));
                }
            }
            $this->queue->ack(QueuedMailSender::QUEUE, $this->workerId, $reserved['raw']);
        }

        return $done;
    }
}
