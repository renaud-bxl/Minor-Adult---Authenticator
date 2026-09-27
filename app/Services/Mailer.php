<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\I18n\Translator;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoi d'e-mails transactionnels traduits (HTML + texte) via PHPMailer.
 *
 * Pilotes : « smtp » (production) et « log » (développement et tests : le message MIME complet est
 * écrit dans la boîte d'envoi locale MAIL_OUTBOX au lieu d'être envoyé ; interdit en production).
 * Les destinataires ne sont jamais journalisés.
 */
final class Mailer
{
    /** @param array<string, string|int> $config */
    public function __construct(
        private readonly array $config,
        string $environment,
        private readonly string $outbox,
        private readonly View $view,
        private readonly Translator $translator,
    ) {
        if (!in_array($config['driver'], ['smtp', 'log'], true)) {
            throw new \InvalidArgumentException('MAIL_DRIVER inconnu : ' . $config['driver']);
        }
        if ($config['driver'] === 'log' && $environment === 'production') {
            throw new \LogicException('MAIL_DRIVER=log est interdit en production.');
        }
    }

    /**
     * @param string               $subjectKey clé de traduction du sujet
     * @param string               $template   gabarits emails/{template}.html et emails/{template}.text
     * @param array<string, mixed> $data
     */
    public function send(string $to, string $subjectKey, string $template, array $data, string $locale): void
    {
        $previousLocale = $this->translator->locale();
        $this->translator->setLocale($locale);
        try {
            $subject = $this->translator->get($subjectKey);
            $html = $this->view->render('emails/' . $template . '.html', [...$data, 'subject' => $subject], 'emails/layout');
            $text = $this->view->render('emails/' . $template . '.text', $data, null);
        } finally {
            $this->translator->setLocale($previousLocale);
        }

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = ' ';
        if ((string) ($this->config['hostname'] ?? '') !== '') {
            $mail->Hostname = (string) $this->config['hostname'];
        }
        // RFC 3834 : message automatique (pas de réponse automatique en retour, pas d'accusé d'absence).
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->setLanguage($locale);
        $mail->setFrom((string) $this->config['from_address'], (string) $this->config['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;

        if ($this->config['driver'] === 'log') {
            $this->writeToOutbox($mail);

            return;
        }

        $mail->isSMTP();
        $mail->Host = (string) $this->config['host'];
        $mail->Port = (int) $this->config['port'];
        $mail->SMTPSecure = match ($this->config['encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->SMTPAutoTLS = $this->config['encryption'] !== 'none';
        if ((string) $this->config['username'] !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $this->config['username'];
            $mail->Password = (string) $this->config['password'];
        }
        $mail->send();
    }

    private function writeToOutbox(PHPMailer $mail): void
    {
        $mail->preSend();
        if (!is_dir($this->outbox) && !mkdir($this->outbox, 0750, true) && !is_dir($this->outbox)) {
            throw new \RuntimeException('Boîte d\'envoi inaccessible.');
        }
        // Horodatage à la microseconde : l'ordre alphabétique des fichiers suit l'ordre d'envoi.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $file = sprintf('%s/%s-%s.eml', $this->outbox, $now->format('Ymd-His-u'), bin2hex(random_bytes(4)));
        if (file_put_contents($file, $mail->getSentMIMEMessage(), LOCK_EX) === false) {
            throw new \RuntimeException('Écriture impossible dans la boîte d\'envoi.');
        }
    }
}
