<?php

require __DIR__.'/../vendor/autoload.php';

/*
 * This container's real process environment (docker-compose env_file) sets DB_CONNECTION=pgsql,
 * CACHE_STORE=redis, etc. Those leak into $_SERVER at process start, which wins over phpunit.xml's
 * <env ... force="true"> overrides — force="true" only clears $_ENV/putenv(), not $_SERVER — so
 * Laravel's env() helper still resolved the real Postgres connection instead of the isolated
 * sqlite one phpunit.xml declares. Clear $_SERVER for exactly the keys phpunit.xml controls.
 */
foreach ([
    'APP_ENV',
    'APP_MAINTENANCE_DRIVER',
    'BCRYPT_ROUNDS',
    'BROADCAST_CONNECTION',
    'CACHE_STORE',
    'DB_CONNECTION',
    'DB_DATABASE',
    'DB_URL',
    'MAIL_MAILER',
    'QUEUE_CONNECTION',
    'SESSION_DRIVER',
    'PULSE_ENABLED',
    'TELESCOPE_ENABLED',
    'NIGHTWATCH_ENABLED',
] as $key) {
    unset($_SERVER[$key]);
}
