<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

/**
 * SmtpRelayTransport
 *
 * Handles email delivery via SMTP Relay using Symfony Mailer.
 * Supports both single-recipient (TO) mode and batch BCC mode.
 *
 * Authenticates using a Google Service Account (via GoogleAuth) and
 * XOAUTH2 — no per-user passwords required.
 *
 * SMTP host/port/encryption are read from the 'smtp_relay' section of config.php.
 */
class SmtpRelayTransport
{
    /** The sender email address (impersonated via service account) */
    private string $userEmail;

    /** @var array{host: string, port: int, encryption: string} */
    private array $smtpConfig;

    private GoogleAuth $googleAuth;

    private SymfonyMailer $mailer;

    /**
     * @param string     $userEmail  Sender email address (from user.txt)
     * @param array      $smtpConfig SMTP settings from config.php smtp_relay section
     * @param GoogleAuth $googleAuth GoogleAuth instance for token generation
     */
    public function __construct(string $userEmail, array $smtpConfig, GoogleAuth $googleAuth)
    {
        $this->userEmail  = $userEmail;
        $this->smtpConfig = $smtpConfig;
        $this->googleAuth = $googleAuth;
        $this->mailer     = $this->buildMailer();
    }

    /**
     * Build a Symfony Mailer instance using XOAUTH2 authentication.
     *
     * Obtains an OAuth2 access token for the sender via GoogleAuth and
     * constructs the DSN using the xoauth2 auth mode.
     */
    private function buildMailer(): SymfonyMailer
    {
        $accessToken = $this->googleAuth->getAccessToken($this->userEmail);

        $encryption = $this->smtpConfig['encryption'] ?? 'tls';
        $scheme     = ($encryption === 'ssl') ? 'smtps' : 'smtp';

        $queryParams = ['auth_mode' => 'xoauth2'];
        if ($encryption !== 'ssl' && $encryption !== 'none') {
            $queryParams['encryption'] = $encryption;
        }

        $dsn = sprintf(
            '%s://%s:%s@%s:%d?%s',
            $scheme,
            rawurlencode($this->userEmail),
            rawurlencode($accessToken),
            $this->smtpConfig['host'],
            (int) $this->smtpConfig['port'],
            http_build_query($queryParams)
        );

        $transport = Transport::fromDsn($dsn);
        return new SymfonyMailer($transport);
    }

    /**
     * Send a single email to one recipient (TO mode).
     *
     * @param string $to         Recipient email address
     * @param string $fromName   Sender display name
     * @param string $subject    Email subject
     * @param string $htmlBody   HTML body
     * @param string $textBody   Plain-text body
     * @param string[] $attachments Paths to attachment files
     */
    public function sendSingle(
        string $to,
        string $fromName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $attachments = []
    ): void {
        $email = (new Email())
            ->from(new Address($this->userEmail, $fromName))
            ->to($to)
            ->subject($subject)
            ->html($htmlBody)
            ->text($textBody);

        foreach ($attachments as $path) {
            if (is_file($path) && is_readable($path)) {
                $email->attachFromPath($path);
            }
        }

        $this->mailer->send($email);
    }

    /**
     * Send one email with multiple BCC recipients (BCC mode).
     *
     * @param string[] $bccList     List of BCC recipient addresses
     * @param string $fromName      Sender display name
     * @param string $subject       Email subject
     * @param string $htmlBody      HTML body
     * @param string $textBody      Plain-text body
     * @param string[] $attachments Paths to attachment files
     */
    public function sendBcc(
        array $bccList,
        string $fromName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $attachments = []
    ): void {
        if (empty($bccList)) {
            return;
        }

        $email = (new Email())
            ->from(new Address($this->userEmail, $fromName))
            // Use sender's own address as the visible "To" recipient when using BCC mode
            ->to(new Address($this->userEmail, $fromName))
            ->subject($subject)
            ->html($htmlBody)
            ->text($textBody);

        foreach ($bccList as $bcc) {
            $email->addBcc($bcc);
        }

        foreach ($attachments as $path) {
            if (is_file($path) && is_readable($path)) {
                $email->attachFromPath($path);
            }
        }

        $this->mailer->send($email);
    }

    /**
     * Return the sender email address for this transport.
     */
    public function getEmail(): string
    {
        return $this->userEmail;
    }
}
