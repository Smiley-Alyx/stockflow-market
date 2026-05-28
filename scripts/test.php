<?php

declare(strict_types=1);

$arguments = array_slice($argv, 1);
$databaseEnvironment = databaseEnvironment();

if ($databaseEnvironment === null) {
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
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
] + $databaseEnvironment;

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

/**
 * @return array<string, string>|null
 */
function databaseEnvironment(): ?array
{
    if (extension_loaded('pdo_sqlite')) {
        return [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ];
    }

    if (extension_loaded('pdo_pgsql')) {
        $environment = [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '5432',
            'DB_DATABASE' => getenv('DB_DATABASE') ?: 'stockflow',
            'DB_USERNAME' => getenv('DB_USERNAME') ?: 'stockflow',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'secret',
        ];

        if (canConnectToPostgres($environment)) {
            return $environment;
        }
    }

    return null;
}

/**
 * @param  array<string, string>  $environment
 */
function canConnectToPostgres(array $environment): bool
{
    try {
        new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $environment['DB_HOST'],
                $environment['DB_PORT'],
                $environment['DB_DATABASE'],
            ),
            $environment['DB_USERNAME'],
            $environment['DB_PASSWORD'],
        );
    } catch (Throwable) {
        return false;
    }

    return true;
}
