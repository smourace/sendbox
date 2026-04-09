<?php

/**
 * config.php — Central Configuration File
 *
 * All project settings are managed from this single file.
 * Adjust values here to control sending behaviour, templates,
 * attachments, delays, and more.
 */

return [

    // ─────────────────────────────────────────────
    // General Settings
    // ─────────────────────────────────────────────

    // Email subject line
    'subject' => 'Your Subject Here',

    // Sender display name
    'from_name' => 'Sender Name',

    // Send method: 'smtp_relay' or 'gmail_api'
    'method' => 'smtp_relay',

    // ─────────────────────────────────────────────
    // SMTP Relay Settings
    // ─────────────────────────────────────────────
    'smtp_relay' => [
        // Mode: 'to' (individual round-robin) or 'bcc' (batch via BCC)
        'mode' => 'to',

        // BCC settings — only used when mode = 'bcc'
        'bcc_batch_size'  => 200,   // Number of recipients per BCC batch
        'bcc_delay_min'   => 1,     // Minimum seconds to wait between batches
        'bcc_delay_max'   => 3,     // Maximum seconds to wait between batches
    ],

    // ─────────────────────────────────────────────
    // Monitoring / Pantau Email
    // ─────────────────────────────────────────────

    // This email receives a copy after every rotation (TO mode)
    // or after every batch (BCC mode), for monitoring purposes.
    'monitor_email' => 'pantau@example.com',

    // ─────────────────────────────────────────────
    // Delay Settings
    // ─────────────────────────────────────────────

    // Seconds to wait after one full user rotation (TO mode only)
    'delay_after_rotation' => 3,

    // ─────────────────────────────────────────────
    // File Paths
    // ─────────────────────────────────────────────

    'list_file'            => __DIR__ . '/data/list.txt',
    'user_file'            => __DIR__ . '/data/user.txt',
    'service_account_file' => __DIR__ . '/data/service-account.json',
    'html_template'        => __DIR__ . '/template/template.html',
    'text_template'        => __DIR__ . '/template/template.txt',
    'attachment_dir'       => __DIR__ . '/attachment/',

    // ─────────────────────────────────────────────
    // Attachments
    // ─────────────────────────────────────────────

    // Set to true to attach all files found in attachment_dir
    'send_attachments' => true,

];
