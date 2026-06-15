<?php

declare(strict_types=1);

use App\Infrastructure\Messaging\DomainEventSchemaCompatibility;

require dirname(__DIR__).'/vendor/autoload.php';

$baseRef = $argv[1] ?? 'origin/main';
$manifestPath = 'services/gateway/contracts/domain-events/manifest.json';

if (! gitRefExists($baseRef)) {
    fwrite(STDERR, "Base git ref does not exist: {$baseRef}\n");
    exit(2);
}

$previousManifest = gitJson($baseRef, $manifestPath);

if ($previousManifest === null) {
    echo "No domain event contract baseline exists at {$baseRef}.\n";
    exit(0);
}

$currentManifest = fileJson(dirname(__DIR__).'/'.$manifestPath);
$previousEvents = events($previousManifest);
$currentEvents = events($currentManifest);
$compatibility = new DomainEventSchemaCompatibility;
$breakingChanges = [];

foreach ($previousEvents as $eventName => $previousRelativePath) {
    $currentRelativePath = $currentEvents[$eventName] ?? null;

    if ($currentRelativePath === null) {
        $breakingChanges[] = "{$eventName}: event was removed";

        continue;
    }

    $previousSchema = gitJson($baseRef, dirname($manifestPath).'/'.$previousRelativePath);
    $currentSchema = fileJson(dirname(__DIR__).'/'.dirname($manifestPath).'/'.$currentRelativePath);

    if ($previousSchema === null) {
        $breakingChanges[] = "{$eventName}: previous schema cannot be read";

        continue;
    }

    foreach ($compatibility->breakingChanges($previousSchema, $currentSchema) as $change) {
        $breakingChanges[] = "{$eventName} {$change}";
    }
}

if ($breakingChanges !== []) {
    fwrite(STDERR, "Breaking domain event contract changes detected:\n");

    foreach ($breakingChanges as $change) {
        fwrite(STDERR, "- {$change}\n");
    }

    exit(1);
}

echo "Domain event contracts are backward compatible with {$baseRef}.\n";

/**
 * @param  array<string, mixed>  $manifest
 * @return array<string, string>
 */
function events(array $manifest): array
{
    $events = $manifest['events'] ?? null;

    if (! is_array($events)) {
        throw new RuntimeException('Contract manifest must define events.');
    }

    foreach ($events as $eventName => $path) {
        if (! is_string($eventName) || ! is_string($path)) {
            throw new RuntimeException('Contract manifest events must map names to schema paths.');
        }
    }

    return $events;
}

/**
 * @return array<string, mixed>
 */
function fileJson(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read JSON file: {$path}");
    }

    return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>|null
 */
function gitJson(string $ref, string $path): ?array
{
    exec('git show '.escapeshellarg($ref.':'.$path).' 2>/dev/null', $output, $exitCode);

    if ($exitCode !== 0) {
        return null;
    }

    return json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
}

function gitRefExists(string $ref): bool
{
    exec('git rev-parse --verify --quiet '.escapeshellarg($ref.'^{commit}'), output: $output, result_code: $exitCode);

    return $exitCode === 0;
}
