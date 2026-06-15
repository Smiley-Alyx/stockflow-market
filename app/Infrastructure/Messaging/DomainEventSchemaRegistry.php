<?php

namespace App\Infrastructure\Messaging;

use InvalidArgumentException;
use RuntimeException;

class DomainEventSchemaRegistry
{
    /**
     * @return array<string, string>
     */
    public function events(): array
    {
        $manifest = $this->readJson($this->manifestPath());
        $events = $manifest['events'] ?? null;

        if (($manifest['schema_version'] ?? null) !== DomainEventContractRegistry::VERSION || ! is_array($events)) {
            throw new RuntimeException('Invalid domain event contract manifest.');
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $eventName): array
    {
        $relativePath = $this->events()[$eventName] ?? null;

        if (! is_string($relativePath) || $relativePath === '') {
            throw new InvalidArgumentException("Missing JSON Schema for domain event: {$eventName}");
        }

        return $this->readJson(dirname($this->manifestPath()).'/'.$relativePath);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(string $eventName, array $payload): void
    {
        $this->validateValue($payload, $this->schema($eventName), '$');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateValue(mixed $value, array $schema, string $path): void
    {
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            throw new InvalidArgumentException("Domain event payload field {$path} must equal ".json_encode($schema['const']).'.');
        }

        if (array_key_exists('type', $schema) && ! $this->matchesType($value, $schema['type'])) {
            $expected = is_array($schema['type']) ? implode('|', $schema['type']) : $schema['type'];

            throw new InvalidArgumentException("Domain event payload field {$path} must have type {$expected}.");
        }

        if (! is_array($value)) {
            return;
        }

        if (($schema['type'] ?? null) === 'array') {
            $itemSchema = $schema['items'] ?? null;

            if (is_array($itemSchema)) {
                foreach ($value as $index => $item) {
                    $this->validateValue($item, $itemSchema, "{$path}[{$index}]");
                }
            }

            return;
        }

        foreach ($schema['required'] ?? [] as $required) {
            if (! is_string($required) || ! array_key_exists($required, $value)) {
                throw new InvalidArgumentException("Missing domain event payload field: {$path}.{$required}");
            }
        }

        $properties = $schema['properties'] ?? [];

        if (is_array($properties)) {
            foreach ($properties as $name => $propertySchema) {
                if (array_key_exists($name, $value) && is_array($propertySchema)) {
                    $this->validateValue($value[$name], $propertySchema, "{$path}.{$name}");
                }
            }
        }
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if ($this->matchesType($value, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'array' => is_array($value) && array_is_list($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'null' => $value === null,
            'number' => is_int($value) || is_float($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'string' => is_string($value),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Domain event contract does not exist: {$path}");
        }

        $contents = file_get_contents($path);
        $decoded = json_decode($contents === false ? '' : $contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Domain event contract must contain a JSON object: {$path}");
        }

        return $decoded;
    }

    private function manifestPath(): string
    {
        return base_path('services/gateway/contracts/domain-events/manifest.json');
    }
}
