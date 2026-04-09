<?php

declare(strict_types=1);

namespace App;

/**
 * GoogleAuth
 *
 * Authenticates users via a Google Service Account with domain-wide delegation.
 * Generates an OAuth2 access token for the given user email, which can be used
 * for SMTP XOAUTH2 authentication or Gmail API calls.
 *
 * Prerequisites:
 * - A Google Cloud project with the Gmail API enabled
 * - A Service Account with domain-wide delegation configured
 * - The service account granted the scope:
 *     https://mail.google.com/
 */
class GoogleAuth
{
    /** Absolute path to the service-account.json file */
    private string $serviceAccountFile;

    /**
     * @param string $serviceAccountFile Absolute path to service-account.json
     * @throws \RuntimeException if the file does not exist
     */
    public function __construct(string $serviceAccountFile)
    {
        if (!file_exists($serviceAccountFile)) {
            throw new \RuntimeException(
                "Service account file not found: {$serviceAccountFile}"
            );
        }

        $this->serviceAccountFile = $serviceAccountFile;
    }

    /**
     * Build an authenticated Google_Client impersonating the given user.
     *
     * Uses domain-wide delegation (setSubject) so the service account can
     * act on behalf of the given user email.
     *
     * @param string $userEmail The email address to impersonate
     * @return \Google_Client
     */
    public function buildClient(string $userEmail): \Google_Client
    {
        $client = new \Google_Client();
        $client->setAuthConfig($this->serviceAccountFile);
        $client->setScopes([
            'https://mail.google.com/',
            \Google_Service_Gmail::GMAIL_SEND,
        ]);
        $client->setSubject($userEmail);

        return $client;
    }

    /**
     * Generate an OAuth2 access token for the given user email.
     *
     * @param string $userEmail The email address to impersonate
     * @return string The access token string
     * @throws \RuntimeException if token generation fails
     */
    public function getAccessToken(string $userEmail): string
    {
        $client = $this->buildClient($userEmail);
        $token  = $client->fetchAccessTokenWithAssertion();

        if (isset($token['error'])) {
            $desc = $token['error_description'] ?? $token['error'];
            throw new \RuntimeException(
                "Failed to obtain access token for {$userEmail}: {$desc}"
            );
        }

        if (empty($token['access_token'])) {
            throw new \RuntimeException(
                "Empty access token returned for {$userEmail}"
            );
        }

        return $token['access_token'];
    }
}
