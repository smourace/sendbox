<?php

declare(strict_types=1);

namespace App;

use Google\Client as GoogleClient;
use Symfony\Component\Mime\Email;

/**
 * SmtpRelayTransport — Raw socket SMTP with XOAUTH2 (keep-alive)
 *
 * Connects directly to smtp-relay.gmail.com via raw socket,
 * handles STARTTLS and AUTH XOAUTH2 with a Google Service Account
 * (Domain-Wide Delegation). Reuses the connection across multiple
 * emails to the same impersonated user (keep-alive / RSET).
 * Only reconnects when the socket dies, the user changes, or
 * maxPerConnection is reached.
 */
class SmtpRelayTransport
{
    private string $keyPath;
    private array $scopes;
    private string $host;
    private int $port;
    private bool $tls;

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokenCache = [];

    /** @var resource|null  Keep-alive SMTP socket */
    private $socket = null;

    /** @var string  User currently authenticated on the active connection */
    private string $authenticatedUser = '';

    /** @var int  Number of emails sent on the current connection */
    private int $sendCount = 0;

    /** @var int  Max emails per connection before reconnect (safety valve) */
    private int $maxPerConnection = 100;

    public function __construct(string $keyPath, array $scopes, array $smtpConfig)
    {
        if (!file_exists($keyPath)) {
            throw new \RuntimeException("Service account key not found: {$keyPath}");
        }

        $this->keyPath          = $keyPath;
        $this->scopes           = $scopes;
        $this->host             = $smtpConfig['host'] ?? 'smtp-relay.gmail.com';
        $this->port             = $smtpConfig['port'] ?? 587;
        $this->tls              = ($smtpConfig['encryption'] ?? 'tls') === 'tls';
        $this->maxPerConnection = $smtpConfig['max_per_connection'] ?? 100;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function getAccessToken(string $impersonateUser): string
    {
        if (isset($this->tokenCache[$impersonateUser])) {
            $cached = $this->tokenCache[$impersonateUser];
            if (time() < $cached['expires'] - 60) {
                return $cached['token'];
            }
        }

        $client = new GoogleClient();
        $client->setAuthConfig($this->keyPath);
        $client->setScopes($this->scopes);
        $client->setSubject($impersonateUser);
        $client->fetchAccessTokenWithAssertion();

        $tokenData = $client->getAccessToken();

        $this->tokenCache[$impersonateUser] = [
            'token'   => $tokenData['access_token'],
            'expires' => time() + ($tokenData['expires_in'] ?? 3600),
        ];

        return $tokenData['access_token'];
    }

    private function buildXOAuth2String(string $user, string $accessToken): string
    {
        return base64_encode("user={$user}\x01auth=Bearer {$accessToken}\x01\x01");
    }

    public function send(Email $email, string $impersonateUser): void
    {
        $needReconnect = false;

        if ($this->socket === null || !$this->isConnectionAlive()) {
            $needReconnect = true;
        } elseif ($this->authenticatedUser !== $impersonateUser) {
            $needReconnect = true;
        } elseif ($this->sendCount >= $this->maxPerConnection) {
            $needReconnect = true;
        }

        if ($needReconnect) {
            $this->disconnect();
            $this->establishConnection($impersonateUser);
        } else {
            $this->sendCommand($this->socket, "RSET", 250);
        }

        try {
            $this->sendCommand($this->socket, "MAIL FROM:<{$impersonateUser}>", 250);

            foreach ($email->getTo() as $recipient) {
                $this->sendCommand($this->socket, "RCPT TO:<{$recipient->getAddress()}>", 250);
            }
            foreach ($email->getCc() as $recipient) {
                $this->sendCommand($this->socket, "RCPT TO:<{$recipient->getAddress()}>", 250);
            }
            foreach ($email->getBcc() as $recipient) {
                $this->sendCommand($this->socket, "RCPT TO:<{$recipient->getAddress()}>", 250);
            }

            $this->sendCommand($this->socket, "DATA", 354);

            $rawMime = $email->toString();
            $rawMime = str_replace("\r\n.", "\r\n..", $rawMime);
            fwrite($this->socket, $rawMime . "\r\n.\r\n");
            $this->readResponse($this->socket, 250);

            $this->sendCount++;

        } catch (\Throwable $e) {
            $this->disconnect();
            throw $e;
        }
    }

    private function establishConnection(string $impersonateUser): void
    {
        $accessToken = $this->getAccessToken($impersonateUser);

        $this->socket = $this->connect();
        $this->readResponse($this->socket, 220);
        $this->sendCommand($this->socket, "EHLO php-mailer.local", 250);

        if ($this->tls) {
            $this->sendCommand($this->socket, "STARTTLS", 220);

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!stream_socket_enable_crypto($this->socket, true, $cryptoMethod)) {
                throw new \RuntimeException("STARTTLS handshake failed");
            }

            $this->sendCommand($this->socket, "EHLO php-mailer.local", 250);
        }

        $authString = $this->buildXOAuth2String($impersonateUser, $accessToken);
        $this->sendCommand($this->socket, "AUTH XOAUTH2 {$authString}", 235);

        $this->authenticatedUser = $impersonateUser;
        $this->sendCount = 0;
    }

    private function isConnectionAlive(): bool
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            return false;
        }

        $meta = stream_get_meta_data($this->socket);
        if ($meta['eof'] || $meta['timed_out']) {
            return false;
        }

        try {
            $this->sendCommand($this->socket, "NOOP", 250);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function disconnect(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            try {
                fwrite($this->socket, "QUIT\r\n");
            } catch (\Throwable $e) {}
            @fclose($this->socket);
        }

        $this->socket            = null;
        $this->authenticatedUser = '';
        $this->sendCount         = 0;
    }

    private function connect(): mixed
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client($address, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new \RuntimeException("Cannot connect to {$address}: [{$errno}] {$errstr}");
        }

        stream_set_timeout($socket, 30);

        return $socket;
    }

    private function sendCommand(mixed $socket, string $command, int $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket, $expectedCode);
    }

    private function readResponse(mixed $socket, int $expectedCode): string
    {
        $response = '';
        while (true) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                throw new \RuntimeException("SMTP connection closed unexpectedly. Last response: {$response}");
            }
            $response .= $line;

            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (strlen(trim($line)) === 3) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException(
                "SMTP Error: expected {$expectedCode}, got {$code}. Response: " . trim($response)
            );
        }

        return $response;
    }
}
