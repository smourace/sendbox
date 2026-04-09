# Sendbox — PHP Email Sender

A flexible, config-driven PHP email sender built on **Symfony Mailer**.  
Supports SMTP Relay (round-robin TO and BCC batch modes) and Gmail API, both authenticated via a Google Service Account.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Project Structure](#project-structure)
4. [Configuration](#configuration)
5. [Data Files](#data-files)
6. [Templates](#templates)
7. [Sending Modes](#sending-modes)
   - [SMTP Relay — TO Mode](#smtp-relay--to-mode-round-robin)
   - [SMTP Relay — BCC Mode](#smtp-relay--bcc-mode-batch)
   - [Gmail API Mode](#gmail-api-mode)
8. [Attachments](#attachments)
9. [Running the Sender](#running-the-sender)
10. [Example Output](#example-output)

---

## Requirements

- PHP **8.1** or later
- [Composer](https://getcomposer.org/)
- A Google Workspace domain with a Service Account configured for domain-wide delegation

---

## Installation

```bash
git clone https://github.com/smourace/sendbox.git
cd sendbox
composer install
```

---

## Project Structure

```
sendbox/
├── config.php                    # Central configuration (all settings here)
├── send.php                      # Entry point — run with: php send.php
├── composer.json                 # Dependencies
├── data/
│   ├── list.txt                  # Recipient email list (one address per line)
│   ├── user.txt                  # Sender email addresses (one per line, no passwords)
│   └── service-account.json      # Google Service Account JSON (used for all auth)
├── template/
│   ├── template.html             # HTML email template
│   └── template.txt              # Plain-text email template
├── attachment/
│   ├── file.pdf                  # Files here are attached to every email
│   ├── dokumen.docs
│   └── file.file
├── src/
│   ├── GoogleAuth.php            # Centralised Google Service Account auth
│   ├── Mailer.php                # Main orchestrator
│   ├── SmtpRelayTransport.php    # SMTP Relay transport (XOAUTH2 via service account)
│   └── GmailApiTransport.php     # Gmail API transport
└── README.md
```

---

## Configuration

All settings live in **`config.php`**. You never need to touch the source files.

| Key | Type | Description |
|-----|------|-------------|
| `subject` | string | Email subject line |
| `from_name` | string | Sender display name |
| `method` | string | `smtp_relay` or `gmail_api` |
| `smtp_relay.mode` | string | `to` (individual) or `bcc` (batch) |
| `smtp_relay.host` | string | SMTP relay server hostname |
| `smtp_relay.port` | int | SMTP relay server port |
| `smtp_relay.encryption` | string | `tls`, `ssl`, or `none` |
| `smtp_relay.bcc_batch_size` | int | Recipients per BCC batch (default 200) |
| `smtp_relay.bcc_delay_min` | int | Min seconds between BCC batches |
| `smtp_relay.bcc_delay_max` | int | Max seconds between BCC batches |
| `monitor_email` | string | Receives a copy after every rotation/batch |
| `delay_after_rotation` | int | Seconds to wait after each TO-mode batch |
| `list_file` | path | Path to recipient list |
| `user_file` | path | Path to sender email list |
| `service_account_file` | path | Path to Google service account JSON |
| `html_template` | path | Path to HTML template |
| `text_template` | path | Path to plain-text template |
| `attachment_dir` | path | Directory containing attachment files |
| `send_attachments` | bool | Attach files from `attachment_dir` |

---

## Data Files

### `data/list.txt`

One recipient email address per line:

```
user1@example.com
user2@example.com
```

### `data/user.txt`

One **sender** email address per line — no passwords, no host, no port.  
Authentication is handled entirely by `service-account.json`:

```
kara@gateway.dpdns.org
dwiki@02438758-5465-4dbc-a6ae-2fddfd374c29.dedyn.io
masako@gateway.dpdns.org
```

### `data/service-account.json`

Google Service Account JSON file downloaded from the Google Cloud Console.  
This single file is used to authenticate **all** sending methods (SMTP Relay XOAUTH2, Gmail API).

**Setup requirements:**
1. Create a Service Account in Google Cloud Console with domain-wide delegation enabled.
2. In Google Workspace Admin, grant the service account the OAuth scope:  
   `https://mail.google.com/`
3. Place the downloaded JSON file at `data/service-account.json`.

---

## Templates

Templates support the following placeholders:

| Placeholder | Replaced with |
|-------------|---------------|
| `{{EMAIL}}` | Recipient's email address |
| `{{DATE}}` | Current date (`Y-m-d`) |
| `{{SUBJECT}}` | Email subject from config |

---

## Sending Modes

### SMTP Relay — TO Mode (Batch Round-Robin)

```php
'method'     => 'smtp_relay',
'smtp_relay' => [
    'mode'       => 'to',
    'host'       => 'smtp-relay.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',
],
```

**Flow:**

1. Splits `list.txt` into batches where each batch contains **up to** as many recipients as there are senders (last batch may be smaller if the recipient count is not evenly divisible by the number of users).
2. Within each batch, every email is sent immediately in round-robin order — **no delay between individual emails**.
3. Each sender authenticates via XOAUTH2 using the service account.
4. After each batch completes:
   - Waits `delay_after_rotation` seconds.
   - Sends a monitor email to `monitor_email`.
5. Continues with the next batch until all recipients are processed.

**Console output example:**

```
Mode: SMTP Relay → TO (round-robin)

[Batch 1]
1. recipient1@example.com => sending with user 1 (kara@gateway.dpdns.org)
2. recipient2@example.com => sending with user 2 (dwiki@02438758-5465-4dbc-a6ae-2fddfd374c29.dedyn.io)
3. recipient3@example.com => sending with user 3 (masako@gateway.dpdns.org)

[Wait 3s] Batch #1 complete. Sending monitor email to pantau@example.com...

[Batch 2]
4. recipient4@example.com => sending with user 1 (kara@gateway.dpdns.org)
5. recipient5@example.com => sending with user 2 (dwiki@02438758-5465-4dbc-a6ae-2fddfd374c29.dedyn.io)
...
```

---

### SMTP Relay — BCC Mode (Batch)

```php
'method'     => 'smtp_relay',
'smtp_relay' => [
    'mode'           => 'bcc',
    'host'           => 'smtp-relay.gmail.com',
    'port'           => 587,
    'encryption'     => 'tls',
    'bcc_batch_size' => 200,
    'bcc_delay_min'  => 1,
    'bcc_delay_max'  => 3,
],
```

**Flow:**

1. Splits `list.txt` into chunks of `bcc_batch_size`.
2. Each chunk is sent as a single email with all recipients in BCC.
3. Senders rotate in round-robin across batches.
4. After each batch:
   - Waits a random delay between `bcc_delay_min` and `bcc_delay_max` seconds.
   - Sends a monitor email to `monitor_email`.

**Console output example:**

```
[Batch 1] User 1 (kara@gateway.dpdns.org) sending 200 emails via BCC...
  => BCC recipients: email1@..., email2@..., ... (+ 195 more)
  => Done! Waiting 2s...
  => Sending monitor email to pantau@example.com

[Batch 2] User 2 (dwiki@02438758-5465-4dbc-a6ae-2fddfd374c29.dedyn.io) sending 200 emails via BCC...
...
```

---

### Gmail API Mode

```php
'method'     => 'gmail_api',
'from_email' => 'sender@yourdomain.com',
```

Sends via the Gmail API using the service account for authentication.  
Configure your Google Cloud project with Gmail API enabled and domain-wide delegation.

---

## Attachments

Place files in the `attachment/` directory and set:

```php
'send_attachments' => true,
```

All readable files in that directory are attached to every outgoing email.  
Set to `false` to send without attachments.

---

## Running the Sender

```bash
php send.php
```

Progress is printed to the console in real time.