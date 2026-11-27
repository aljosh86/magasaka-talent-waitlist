<?php
declare(strict_types=1);

// Copy to resend.local.php (ignored by Git); never place this in the public folder.
return [
    'resend_api_key' => '',
    'resend_from' => 'Magasaka <onboarding@resend.dev>',
    // Required for local sandbox testing: the Resend account owner's inbox.
    // After verifying a production sending domain, clear this restriction.
    'resend_test_recipient' => '',
    // Publish this template in your own Resend account before enabling it.
    // Variables: CODE (string), EXPIRES_MINUTES (number).
    'resend_verification_template' => 'magasaka-waitlist-verification',
];
