<?php

declare(strict_types=1);

namespace App;

/**
 * Mailer — Main Orchestrator
 *
 * Reads config, loads the email list and user list, renders templates,
 * and dispatches emails via the configured transport (smtp_relay or gmail_api).
 *
 * Supported modes
 * ───────────────
 * smtp_relay / to  — Individual round-robin: one email per user per turn.
 *                    After every full rotation, waits `delay_after_rotation`
 *                    seconds and sends a monitor/pantau email.
 *
 * smtp_relay / bcc — Batch BCC: each user sends one BCC email to
 *                    `bcc_batch_size` recipients, then waits a random delay
 *                    and sends a monitor/pantau email before the next batch.
 *
 * gmail_api        — (Skeleton) Uses GmailApiTransport for sending.
 */
class Mailer
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var string[] */
    private array $emailList = [];

    /** @var array<int, array{email: string, password: string, host: string, port: int}> */
    private array $users = [];

    /** @var string[] */
    private array $attachments = [];

    private string $htmlTemplate = '';
    private string $textTemplate = '';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ─────────────────────────────────────────────────────────────
    // Public entry point
    // ─────────────────────────────────────────────────────────────

    /**
     * Run the sender based on the configured method and mode.
     */
    public function run(): void
    {
        $this->loadResources();

        $method = $this->config['method'] ?? 'smtp_relay';

        $this->printLine("════════════════════════════════════════════════");
        $this->printLine("  Sendbox — PHP Email Sender");
        $this->printLine("  Method  : {$method}");
        $this->printLine("  Recipients: " . count($this->emailList));
        $this->printLine("  SMTP Users: " . count($this->users));
        $this->printLine("════════════════════════════════════════════════");
        echo PHP_EOL;

        match ($method) {
            'smtp_relay' => $this->runSmtpRelay(),
            'gmail_api'  => $this->runGmailApi(),
            default      => throw new \InvalidArgumentException(
                "Unknown method: '{$method}'. Use 'smtp_relay' or 'gmail_api'."
            ),
        };

        echo PHP_EOL;
        $this->printLine("All emails dispatched. Done!");
    }

    // ─────────────────────────────────────────────────────────────
    // Loading resources
    // ─────────────────────────────────────────────────────────────

    /**
     * Load email list, user list, templates, and attachments.
     */
    private function loadResources(): void
    {
        $this->emailList = $this->loadLines($this->config['list_file'], 'email list');
        $this->users     = $this->loadUsers($this->config['user_file']);

        if (empty($this->emailList)) {
            throw new \RuntimeException("Email list is empty. Add recipients to data/list.txt");
        }

        if (empty($this->users)) {
            throw new \RuntimeException("User list is empty. Add SMTP users to data/user.txt");
        }

        $this->htmlTemplate = $this->loadTemplate($this->config['html_template']);
        $this->textTemplate = $this->loadTemplate($this->config['text_template']);

        if ($this->config['send_attachments'] ?? false) {
            $this->attachments = $this->loadAttachments($this->config['attachment_dir']);
        }
    }

    /**
     * Load non-empty lines from a file.
     *
     * @return string[]
     */
    private function loadLines(string $path, string $label): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found ({$label}): {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new \RuntimeException("Could not read {$label}: {$path}");
        }

        return array_values(array_filter(array_map('trim', $lines)));
    }

    /**
     * Parse user.txt and return structured user array.
     *
     * @return array<int, array{email: string, password: string, host: string, port: int}>
     */
    private function loadUsers(string $path): array
    {
        $lines = $this->loadLines($path, 'user list');
        $users = [];

        foreach ($lines as $i => $line) {
            try {
                $users[] = SmtpRelayTransport::parseUserLine($line);
            } catch (\InvalidArgumentException $e) {
                $this->printLine("[WARNING] Skipping user line " . ($i + 1) . ": " . $e->getMessage());
            }
        }

        return $users;
    }

    /**
     * Read a template file into a string.
     */
    private function loadTemplate(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Template not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Could not read template: {$path}");
        }

        return $content;
    }

    /**
     * Collect all readable files from the attachment directory.
     *
     * @return string[]
     */
    private function loadAttachments(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (new \DirectoryIterator($dir) as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->isReadable()) {
                $files[] = $fileInfo->getRealPath();
            }
        }

        return $files;
    }

    // ─────────────────────────────────────────────────────────────
    // SMTP Relay dispatcher
    // ─────────────────────────────────────────────────────────────

    /**
     * Route to the correct SMTP Relay mode (to / bcc).
     */
    private function runSmtpRelay(): void
    {
        $mode = $this->config['smtp_relay']['mode'] ?? 'to';

        match ($mode) {
            'to'    => $this->runSmtpRelayTo(),
            'bcc'   => $this->runSmtpRelayBcc(),
            default => throw new \InvalidArgumentException(
                "Unknown smtp_relay mode: '{$mode}'. Use 'to' or 'bcc'."
            ),
        };
    }

    // ─── TO mode ────────────────────────────────────────────────

    /**
     * SMTP Relay "TO" mode — round-robin, one email per user per turn.
     *
     * After one full rotation (all users used once), the script:
     *   1. Waits `delay_after_rotation` seconds.
     *   2. Sends a monitor/pantau email using the first user.
     *   3. Continues with the next rotation.
     */
    private function runSmtpRelayTo(): void
    {
        $this->printLine("Mode: SMTP Relay → TO (round-robin)");
        echo PHP_EOL;

        $totalEmails  = count($this->emailList);
        $totalUsers   = count($this->users);
        $delay        = (int) ($this->config['delay_after_rotation'] ?? 3);
        $monitorEmail = $this->config['monitor_email'] ?? '';

        $rotationSize = $totalUsers;   // one full cycle = one email per user
        $rotationNum  = 0;

        for ($i = 0; $i < $totalEmails; $i++) {
            $recipient  = $this->emailList[$i];
            $userIndex  = $i % $totalUsers;
            $user       = $this->users[$userIndex];
            $userNum    = $userIndex + 1;
            $emailNum   = $i + 1;

            // After a full rotation, pause + send monitor email
            if ($i > 0 && $userIndex === 0) {
                $rotationNum++;
                $this->printLine("");
                $this->printLine(
                    "[Wait {$delay}s] Rotation #{$rotationNum} complete. " .
                    "Sending monitor email to {$monitorEmail} using user 1..."
                );

                sleep($delay);
                $this->sendMonitorEmail($this->users[0], $rotationNum);
                $this->printLine("");
            }

            // Send the email
            $this->printLine(
                "{$emailNum}. {$recipient} => sending with user {$userNum} ({$user['email']})"
            );

            try {
                $transport = new SmtpRelayTransport($user);
                $transport->sendSingle(
                    $recipient,
                    $this->config['from_name'] ?? 'Sender',
                    $this->config['subject']   ?? '(no subject)',
                    $this->renderTemplate($this->htmlTemplate, $recipient),
                    $this->renderTemplate($this->textTemplate, $recipient),
                    $this->attachments
                );
            } catch (\Throwable $e) {
                $this->printLine("  [ERROR] Failed to send to {$recipient}: " . $e->getMessage());
            }
        }

        // Final rotation monitor email (if we sent any emails)
        if ($totalEmails > 0 && $monitorEmail !== '') {
            $rotationNum++;
            $this->printLine("");
            $this->printLine(
                "[Wait {$delay}s] Final rotation #{$rotationNum}. " .
                "Sending monitor email to {$monitorEmail}..."
            );
            sleep($delay);
            $this->sendMonitorEmail($this->users[0], $rotationNum);
        }
    }

    // ─── BCC mode ───────────────────────────────────────────────

    /**
     * SMTP Relay "BCC" mode — batch sending via BCC.
     *
     * Each user sends one email to `bcc_batch_size` recipients via BCC.
     * After each batch:
     *   1. Waits a random delay between `bcc_delay_min` and `bcc_delay_max`.
     *   2. Sends a monitor/pantau email.
     * Users rotate in round-robin order.
     */
    private function runSmtpRelayBcc(): void
    {
        $this->printLine("Mode: SMTP Relay → BCC (batch)");
        echo PHP_EOL;

        $batchSize    = (int) ($this->config['smtp_relay']['bcc_batch_size'] ?? 200);
        $delayMin     = (int) ($this->config['smtp_relay']['bcc_delay_min']  ?? 1);
        $delayMax     = (int) ($this->config['smtp_relay']['bcc_delay_max']  ?? 3);
        $monitorEmail = $this->config['monitor_email'] ?? '';
        $totalEmails  = count($this->emailList);
        $totalUsers   = count($this->users);

        $batches   = array_chunk($this->emailList, $batchSize);
        $batchNum  = 0;

        foreach ($batches as $batch) {
            $batchNum++;
            $userIndex = ($batchNum - 1) % $totalUsers;
            $user      = $this->users[$userIndex];
            $userNum   = $userIndex + 1;
            $count     = count($batch);
            $delay     = random_int($delayMin, max($delayMin, $delayMax));

            $this->printLine(
                "[Batch {$batchNum}] User {$userNum} ({$user['email']}) " .
                "sending {$count} email(s) via BCC..."
            );
            $this->printLine(
                "  => BCC recipients: " . implode(', ', array_slice($batch, 0, 5)) .
                ($count > 5 ? ", ... (+" . ($count - 5) . " more)" : "")
            );

            try {
                $transport = new SmtpRelayTransport($user);

                // Use the first recipient's address to render the template
                // (personalisation is not available in BCC mode)
                $genericEmail = $batch[0];
                $transport->sendBcc(
                    $batch,
                    $this->config['from_name'] ?? 'Sender',
                    $this->config['subject']   ?? '(no subject)',
                    $this->renderTemplate($this->htmlTemplate, $genericEmail),
                    $this->renderTemplate($this->textTemplate, $genericEmail),
                    $this->attachments
                );

                $this->printLine("  => Done! Waiting {$delay}s...");
            } catch (\Throwable $e) {
                $this->printLine("  [ERROR] Batch {$batchNum} failed: " . $e->getMessage());
                $this->printLine("  => Waiting {$delay}s before continuing...");
            }

            sleep($delay);

            if ($monitorEmail !== '') {
                $this->printLine("  => Sending monitor email to {$monitorEmail}");
                $this->sendMonitorEmail($user, $batchNum);
            }

            echo PHP_EOL;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Gmail API dispatcher
    // ─────────────────────────────────────────────────────────────

    /**
     * Gmail API mode — sends via GmailApiTransport (skeleton).
     *
     * Requires a properly configured service-account.json and
     * domain-wide delegation set up in Google Workspace Admin.
     */
    private function runGmailApi(): void
    {
        $this->printLine("Mode: Gmail API");
        echo PHP_EOL;

        $serviceAccountFile = $this->config['service_account_file'] ?? '';
        $fromEmail          = $this->config['from_email'] ?? '';

        if (empty($fromEmail)) {
            throw new \RuntimeException(
                "Gmail API mode requires 'from_email' to be set in config.php"
            );
        }

        $transport = new GmailApiTransport($serviceAccountFile, $fromEmail);
        $total     = count($this->emailList);

        foreach ($this->emailList as $i => $recipient) {
            $num = $i + 1;
            $this->printLine("{$num}/{$total}. Sending to {$recipient} via Gmail API...");

            try {
                $transport->sendSingle(
                    $recipient,
                    $this->config['from_name'] ?? 'Sender',
                    $this->config['subject']   ?? '(no subject)',
                    $this->renderTemplate($this->htmlTemplate, $recipient),
                    $this->renderTemplate($this->textTemplate, $recipient),
                    $this->attachments
                );
            } catch (\Throwable $e) {
                $this->printLine("  [ERROR] " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Helper methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Send the monitor/pantau email using the given user.
     *
     * @param array{email: string, password: string, host: string, port: int} $user
     */
    private function sendMonitorEmail(array $user, int $cycleNumber): void
    {
        $monitorEmail = $this->config['monitor_email'] ?? '';

        if ($monitorEmail === '') {
            return;
        }

        try {
            $transport = new SmtpRelayTransport($user);
            $transport->sendSingle(
                $monitorEmail,
                $this->config['from_name'] ?? 'Sender',
                "[Monitor] Cycle #{$cycleNumber} complete — " . date('Y-m-d H:i:s'),
                "<p>Cycle #{$cycleNumber} completed at " . date('Y-m-d H:i:s') . "</p>",
                "Cycle #{$cycleNumber} completed at " . date('Y-m-d H:i:s')
            );
        } catch (\Throwable $e) {
            $this->printLine("  [WARNING] Monitor email failed: " . $e->getMessage());
        }
    }

    /**
     * Replace template placeholders with actual values.
     *
     * Supported placeholders:
     *   {{EMAIL}}   — recipient email address
     *   {{DATE}}    — current date (Y-m-d)
     *   {{SUBJECT}} — email subject from config
     *
     * @param string $template Raw template string
     * @param string $email    Recipient email for {{EMAIL}} substitution
     * @return string          Rendered template
     */
    private function renderTemplate(string $template, string $email): string
    {
        return str_replace(
            ['{{EMAIL}}', '{{DATE}}',              '{{SUBJECT}}'],
            [$email,      date('Y-m-d'), $this->config['subject'] ?? ''],
            $template
        );
    }

    /**
     * Print a line to stdout with a newline.
     */
    private function printLine(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
