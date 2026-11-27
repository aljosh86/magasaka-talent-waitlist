<?php
declare(strict_types=1);

// Copy to config.local.php (ignored by Git), outside the public website folder.
// Use a database user with SELECT, INSERT, UPDATE and DELETE privileges only.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'magasaka_waitlist',
    'db_user' => 'magasaka_waitlist_app',
    'db_password' => 'replace-with-your-local-password',
    'app_key' => 'replace-with-a-random-secret-at-least-32-characters-long',
    // Exact frontend origins when a separately hosted preview calls this API.
    // Same-origin requests work automatically; never use '*'.
    'allowed_origins' => [],
    // Resend sending-only key. Prefer RESEND_API_KEY in production.
    'resend_api_key' => '',
    // Use your verified sending domain in production.
    'resend_from' => '',
    // Restrict the local sandbox to your own inbox; leave empty in production.
    'resend_test_recipient' => '',
    // Optional published template ID/alias with CODE and EXPIRES_MINUTES variables.
    'resend_verification_template' => '',
];
