<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\RedisQueue;

/**
 * Met l'e-mail en file (chiffré) ; bin/worker.php l'envoie avec relances (MailWorker).
 * La réponse HTTP ne dépend ainsi ni du serveur SMTP ni de son temps de réponse.
 */
final class QueuedMailSender implements MailSender
{
    public const QUEUE = 'mail';

    public function __construct(private readonly RedisQueue $queue)
    {
    }

    public function send(string $to, string $subjectKey, string $template, array $data, string $locale): void
    {
        foreach ($data as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Données d\'e-mail en file : valeurs scalaires uniquement.');
            }
        }
        $this->queue->push(self::QUEUE, [
            'to' => $to,
            'subject' => $subjectKey,
            'template' => $template,
            'data' => $data,
            'locale' => $locale,
            'attempts' => 0,
        ]);
    }
}
