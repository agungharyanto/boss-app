<?php

declare(strict_types=1);

return [
    // Unified login — identical failure message for both the email (staff)
    // and phone-number (Referrer) paths; never reveals whether an identity
    // is registered or which path was attempted.
    'failed' => 'Email/phone number or password is incorrect.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
];
