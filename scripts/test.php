<?php

declare(strict_types=1);

$arguments = array_slice($argv, 1);

if (! extension_loaded('pdo_sqlite')) {
    passthru(buildCommand([
        'docker',
        'compose',
        'run',
        '--rm',
        '--no-deps',
        'php',
        'composer',
        'test',
        '--',
        ...$arguments,
    ]), $exitCode);

    exit($exitCode);
}

$environment = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($environment as $name => $value) {
    putenv("{$name}={$value}");
}

passthru(buildCommand([
    PHP_BINARY,
    'artisan',
    'test',
    ...$arguments,
]), $exitCode);

exit($exitCode);

function buildCommand(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}
