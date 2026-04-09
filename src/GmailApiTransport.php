<?php

declare(strict_types=1);

namespace App;

/**
 * GmailApiTransport
 *
 * Sends emails via the Gmail API using a Google Service Account
 * (domain-wide delegation) provided through a GoogleAuth instance.
 *
 * Full implementation requires:
 * - A Google Cloud project with Gmail API enabled
 * - A Service Account with domain-wide delegation
 * - The google/apiclient package installed via Composer
 *
 * @see https://developers.google.com/gmail/api
 */
class GmailApiTransport
{
    /** Gmail address to impersonate (must belong to the GSuite/Workspace domain) */
    private string $impersonatedEmail;

    private GoogleAuth $googleAuth;

    /**
     * @param string     $impersonatedEmail Email address to send from (impersonation)
     * @param GoogleAuth $googleAuth        GoogleAuth instance for service account auth
     */
    public function __construct(string $impersonatedEmail, GoogleAuth $googleAuth)
    {
        $this->impersonatedEmail = $impersonatedEmail;
        $this->googleAuth        = $googleAuth;
    }

    /**
     * Build and return an authenticated Google_Client instance.
     *
     * Delegates to GoogleAuth so that service account configuration
     * is managed centrally.
     *
     * @return \Google_Client
     */
    private function buildClient(): \Google_Client
    {
        return $this->googleAuth->buildClient($this->impersonatedEmail);
    }

    /**
     * Send a single email via the Gmail API.
     *
     * @param string   $to          Recipient email address
     * @param string   $fromName    Sender display name
     * @param string   $subject     Email subject
     * @param string   $htmlBody    HTML body
     * @param string   $textBody    Plain-text body (unused in basic API call, kept for interface parity)
     * @param string[] $attachments Paths to attachment files (not yet implemented)
     *
     * @throws \RuntimeException on API error
     */
    public function sendSingle(
        string $to,
        string $fromName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $attachments = []
    ): void {
        $client  = $this->buildClient();
        $service = new \Google_Service_Gmail($client);

        $rawMessage = $this->buildRawMessage(
            $this->impersonatedEmail,
            $fromName,
            $to,
            $subject,
            $htmlBody
        );

        $message = new \Google_Service_Gmail_Message();
        $message->setRaw(rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '='));

        try {
            $service->users_messages->send('me', $message);
        } catch (\Google_Service_Exception $e) {
            throw new \RuntimeException(
                "Gmail API error: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Build a raw RFC 2822 email message string.
     *
     * @param string $from      Sender email address
     * @param string $fromName  Sender display name
     * @param string $to        Recipient email address
     * @param string $subject   Email subject
     * @param string $htmlBody  HTML body
     * @return string           Raw email string
     */
    private function buildRawMessage(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $htmlBody
    ): string {
        $boundary = uniqid('sendbox_', true);

        $headers = implode("\r\n", [
            "From: {$fromName} <{$from}>",
            "To: {$to}",
            "Subject: {$subject}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ]);

        $body = implode("\r\n", [
            "--{$boundary}",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: quoted-printable",
            "",
            quoted_printable_encode($htmlBody),
            "--{$boundary}--",
        ]);

        return $headers . "\r\n\r\n" . $body;
    }
}
