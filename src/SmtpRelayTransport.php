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
 * user.txt format: email|password|smtp_host|smtp_port
 */
class SmtpRelayTransport
{
    /** @var array{email: string, password: string, host: string, port: int} */
    private array $user;

    private SymfonyMailer $mailer;

    /**
     * @param array{email: string, password: string, host: string, port: int} $user
     */
    public function __construct(array $user)
    {
        $this->user = $user;
        $this->mailer = $this->buildMailer();
    }

    /**
     * Build a Symfony Mailer instance for this SMTP user.
     */
    private function buildMailer(): SymfonyMailer
    {
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            rawurlencode($this->user['email']),
            rawurlencode($this->user['password']),
            $this->user['host'],
            $this->user['port']
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
            ->from(new Address($this->user['email'], $fromName))
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
            ->from(new Address($this->user['email'], $fromName))
            // Use sender's own address as the visible "To" recipient when using BCC mode
            ->to(new Address($this->user['email'], $fromName))
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
        return $this->user['email'];
    }

    /**
     * Parse a single line from user.txt into a user array.
     *
     * @throws \InvalidArgumentException if the line format is invalid
     * @return array{email: string, password: string, host: string, port: int}
     */
    public static function parseUserLine(string $line): array
    {
        $parts = explode('|', trim($line));

        if (count($parts) !== 4) {
            throw new \InvalidArgumentException(
                "Invalid user line format. Expected: email|password|smtp_host|smtp_port"
            );
        }

        return [
            'email'    => trim($parts[0]),
            'password' => trim($parts[1]),
            'host'     => trim($parts[2]),
            'port'     => (int) trim($parts[3]),
        ];
    }
}
