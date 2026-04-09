#!/usr/bin/env php
<?php

/**
 * send.php — Entry Point
 *
 * Run this script from the command line to start the email sender:
 *   php send.php
 */

declare(strict_types=1);

// Load Composer autoloader
$autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    echo "[ERROR] vendor/autoload.php not found." . PHP_EOL;
    echo "Please run: composer install" . PHP_EOL;
    exit(1);
}

require_once $autoload;

// Load configuration
$config = require __DIR__ . '/config.php';

use App\Mailer;

try {
    $mailer = new Mailer($config);
    $mailer->run();
} catch (\Throwable $e) {
    echo "[FATAL ERROR] " . $e->getMessage() . PHP_EOL;
    exit(1);
}
