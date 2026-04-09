<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

/**
 * Mailer — Main Orchestrator
 *
 * Reads config, loads the email list and user list, renders templates,
 * and dispatches emails via the configured transport (smtp_relay or gmail_api).
 *
 * Supported modes
 * ───────────────
 * smtp_relay / to  — Per-batch round-robin: emails are split into batches
 *                    equal to the number of users. All emails in a batch are
 *                    sent immediately (no delay). After every batch, waits
 *                    `delay_after_rotation` seconds and sends a monitor email.
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

    /** @var string[] */
    private array $users = [];

    /** @var string[] */
    private array $attachments = [];

    private string $htmlTemplate = '';
    private string $textTemplate = '';

    private GoogleAuth $googleAuth;

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
        $this->emailList  = $this->loadLines($this->config['list_file'], 'email list');
        $this->users      = $this->loadUsers($this->config['user_file']);
        $this->googleAuth = new GoogleAuth($this->config['service_account_file']);

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
     * Parse user.txt and return an array of email addresses (one per line).
     *
     * @return string[]
     */
    private function loadUsers(string $path): array
    {
        return $this->loadLines($path, 'user list');
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

        $transport = new SmtpRelayTransport(
            $this->config['service_account_file'],
            ['https://mail.google.com/'],
            $this->config['smtp_relay']
        );

        match ($mode) {
            'to'    => $this->runSmtpRelayTo($transport),
            'bcc'   => $this->runSmtpRelayBcc($transport),
            default => throw new \InvalidArgumentException(
                "Unknown smtp_relay mode: '{$mode}'. Use 'to' or 'bcc'."
            ),
        };
    }

    // ─── TO mode ────────────────────────────────────────────────

    /**
     * SMTP Relay "TO" mode — per-batch round-robin sending.
     *
     * Batch size equals the number of configured users. Within each batch all
     * emails are sent immediately (no sleep). After every batch the script:
     *   1. Waits `delay_after_rotation` seconds.
     *   2. Sends a monitor/pantau email using the first user.
     *   3. Continues with the next batch.
     */
    private function runSmtpRelayTo(SmtpRelayTransport $transport): void
    {
        $this->printLine("Mode: SMTP Relay → TO (round-robin)");
        echo PHP_EOL;

        $totalUsers   = count($this->users);
        $delay        = (int) ($this->config['delay_after_rotation'] ?? 3);
        $monitorEmail = $this->config['monitor_email'] ?? '';

        // Split recipient list into chunks, one chunk per full user rotation
        $batches  = array_chunk($this->emailList, $totalUsers);
        $emailNum = 0;

        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            $this->printLine("[Batch {$batchNum}]");

            // Send all emails in this batch without any delay
            foreach ($batch as $localIndex => $recipient) {
                $emailNum++;
                $userIndex = $localIndex % $totalUsers;
                $userEmail = $this->users[$userIndex];
                $userNum   = $userIndex + 1;

                $this->printLine(
                    "{$emailNum}. {$recipient} => sending with user {$userNum} ({$userEmail})"
                );

                try {
                    $email = (new Email())
                        ->from(new Address($userEmail, $this->config['from_name'] ?? 'Sender'))
                        ->to($recipient)
                        ->subject($this->config['subject'] ?? '(no subject)')
                        ->html($this->renderTemplate($this->htmlTemplate, $recipient))
                        ->text($this->renderTemplate($this->textTemplate, $recipient));

                    foreach ($this->attachments as $path) {
                        if (is_file($path) && is_readable($path)) {
                            $email->attachFromPath($path);
                        }
                    }

                    $transport->send($email, $userEmail);
                } catch (\Throwable $e) {
                    $this->printLine("  [ERROR] Failed to send to {$recipient}: " . $e->getMessage());
                }
            }

            // After the batch: wait, then send monitor email
            $this->printLine("");
            $this->printLine(
                "[Wait {$delay}s] Batch #{$batchNum} complete. " .
                "Sending monitor email to {$monitorEmail}..."
            );

            sleep($delay);
            $this->sendMonitorEmail($transport, $this->users[0], $batchNum);
            echo PHP_EOL;
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
    private function runSmtpRelayBcc(SmtpRelayTransport $transport): void
    {
        $this->printLine("Mode: SMTP Relay → BCC (batch)");
        echo PHP_EOL;

        $batchSize    = (int) ($this->config['smtp_relay']['bcc_batch_size'] ?? 200);
        $delayMin     = (int) ($this->config['smtp_relay']['bcc_delay_min']  ?? 1);
        $delayMax     = (int) ($this->config['smtp_relay']['bcc_delay_max']  ?? 3);
        $monitorEmail = $this->config['monitor_email'] ?? '';
        $totalUsers   = count($this->users);

        $batches   = array_chunk($this->emailList, $batchSize);
        $batchNum  = 0;

        foreach ($batches as $batch) {
            $batchNum++;
            $userIndex = ($batchNum - 1) % $totalUsers;
            $userEmail = $this->users[$userIndex];
            $userNum   = $userIndex + 1;
            $count     = count($batch);
            $delay     = random_int($delayMin, max($delayMin, $delayMax));

            $this->printLine(
                "[Batch {$batchNum}] User {$userNum} ({$userEmail}) " .
                "sending {$count} email(s) via BCC..."
            );
            $this->printLine(
                "  => BCC recipients: " . implode(', ', array_slice($batch, 0, 5)) .
                ($count > 5 ? ", ... (+" . ($count - 5) . " more)" : "")
            );

            try {
                // Use the first recipient's address to render the template
                // (personalisation is not available in BCC mode)
                $genericEmail = $batch[0];

                $email = (new Email())
                    ->from(new Address($userEmail, $this->config['from_name'] ?? 'Sender'))
                    ->to(new Address($userEmail, $this->config['from_name'] ?? 'Sender'))
                    ->subject($this->config['subject'] ?? '(no subject)')
                    ->html($this->renderTemplate($this->htmlTemplate, $genericEmail))
                    ->text($this->renderTemplate($this->textTemplate, $genericEmail));

                foreach ($batch as $bcc) {
                    $email->addBcc($bcc);
                }

                foreach ($this->attachments as $path) {
                    if (is_file($path) && is_readable($path)) {
                        $email->attachFromPath($path);
                    }
                }

                $transport->send($email, $userEmail);

                $this->printLine("  => Done! Waiting {$delay}s...");
            } catch (\Throwable $e) {
                $this->printLine("  [ERROR] Batch {$batchNum} failed: " . $e->getMessage());
                $this->printLine("  => Waiting {$delay}s before continuing...");
            }

            sleep($delay);

            if ($monitorEmail !== '') {
                $this->printLine("  => Sending monitor email to {$monitorEmail}");
                $this->sendMonitorEmail($transport, $userEmail, $batchNum);
            }

            echo PHP_EOL;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Gmail API dispatcher
    // ─────────────────────────────────────────────────────────────

    /**
     * Gmail API mode — sends via GmailApiTransport.
     *
     * Requires a properly configured service-account.json and
     * domain-wide delegation set up in Google Workspace Admin.
     */
    private function runGmailApi(): void
    {
        $this->printLine("Mode: Gmail API");
        echo PHP_EOL;

        $fromEmail = $this->config['from_email'] ?? '';

        if (empty($fromEmail)) {
            throw new \RuntimeException(
                "Gmail API mode requires 'from_email' to be set in config.php"
            );
        }

        $transport = new GmailApiTransport($fromEmail, $this->googleAuth);
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
     * @param SmtpRelayTransport $transport Shared transport instance
     * @param string $userEmail Sender email address
     */
    private function sendMonitorEmail(SmtpRelayTransport $transport, string $userEmail, int $cycleNumber): void
    {
        $monitorEmail = $this->config['monitor_email'] ?? '';

        if ($monitorEmail === '') {
            return;
        }

        try {
            $email = (new Email())
                ->from(new Address($userEmail, $this->config['from_name'] ?? 'Sender'))
                ->to($monitorEmail)
                ->subject("[Monitor] Cycle #{$cycleNumber} complete — " . date('Y-m-d H:i:s'))
                ->html("<p>Cycle #{$cycleNumber} completed at " . date('Y-m-d H:i:s') . "</p>")
                ->text("Cycle #{$cycleNumber} completed at " . date('Y-m-d H:i:s'));

            $transport->send($email, $userEmail);
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
