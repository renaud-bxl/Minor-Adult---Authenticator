<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Envoi d'un e-mail transactionnel traduit. Implémentations : Mailer (envoi direct) et
 * QueuedMailSender (file Redis chiffrée, envoi et relances par bin/worker.php).
 */
interface MailSender
{
    /**
     * @param string               $subjectKey clé de traduction du sujet
     * @param string               $template   gabarits emails/{template}.html et emails/{template}.text
     * @param array<string, mixed> $data       données des gabarits (scalaires uniquement si mis en file)
     */
    public function send(string $to, string $subjectKey, string $template, array $data, string $locale): void;
}
