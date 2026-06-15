<?php

namespace App\Infrastructure\Messaging;

class DomainEventSchemaCompatibility
{
    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public function breakingChanges(array $previous, array $current, string $path = '$'): array
    {
        $changes = [];

        if (array_key_exists('const', $previous) && array_key_exists('const', $current) && $previous['const'] !== $current['const']) {
            $changes[] = "{$path}: const changed";
        }

        if (array_key_exists('type', $previous) && array_key_exists('type', $current)) {
            $previousTypes = $this->types($previous['type']);
            $currentTypes = $this->types($current['type']);

            foreach (array_diff($previousTypes, $currentTypes) as $removedType) {
                $changes[] = "{$path}: type {$removedType} is no longer accepted";
            }
        }

        $previousRequired = $this->strings($previous['required'] ?? []);
        $currentRequired = $this->strings($current['required'] ?? []);

        foreach (array_diff($currentRequired, $previousRequired) as $required) {
            $changes[] = "{$path}.{$required}: field became required";
        }

        if (($previous['additionalProperties'] ?? true) !== false && ($current['additionalProperties'] ?? true) === false) {
            $changes[] = "{$path}: additional properties are no longer accepted";
        }

        $changes = [
            ...$changes,
            ...$this->narrowedEnum($previous, $current, $path),
            ...$this->tightenedLowerBound($previous, $current, $path, 'minimum'),
            ...$this->tightenedLowerBound($previous, $current, $path, 'minLength'),
            ...$this->tightenedLowerBound($previous, $current, $path, 'minItems'),
            ...$this->tightenedUpperBound($previous, $current, $path, 'maximum'),
            ...$this->tightenedUpperBound($previous, $current, $path, 'maxLength'),
            ...$this->tightenedUpperBound($previous, $current, $path, 'maxItems'),
        ];

        $previousProperties = $this->schemas($previous['properties'] ?? []);
        $currentProperties = $this->schemas($current['properties'] ?? []);

        foreach ($previousProperties as $name => $previousProperty) {
            if (array_key_exists($name, $currentProperties)) {
                $changes = [
                    ...$changes,
                    ...$this->breakingChanges($previousProperty, $currentProperties[$name], "{$path}.{$name}"),
                ];
            } elseif (($current['additionalProperties'] ?? true) === false) {
                $changes[] = "{$path}.{$name}: property is no longer accepted";
            }
        }

        if (is_array($previous['items'] ?? null) && is_array($current['items'] ?? null)) {
            $changes = [
                ...$changes,
                ...$this->breakingChanges($previous['items'], $current['items'], "{$path}[]"),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function narrowedEnum(array $previous, array $current, string $path): array
    {
        if (! is_array($previous['enum'] ?? null) || ! is_array($current['enum'] ?? null)) {
            return [];
        }

        foreach ($previous['enum'] as $value) {
            if (! in_array($value, $current['enum'], true)) {
                return ["{$path}: enum value ".json_encode($value).' is no longer accepted'];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function tightenedLowerBound(array $previous, array $current, string $path, string $keyword): array
    {
        if (is_numeric($current[$keyword] ?? null) && (! is_numeric($previous[$keyword] ?? null) || $current[$keyword] > $previous[$keyword])) {
            return ["{$path}: {$keyword} became stricter"];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function tightenedUpperBound(array $previous, array $current, string $path, string $keyword): array
    {
        if (is_numeric($current[$keyword] ?? null) && (! is_numeric($previous[$keyword] ?? null) || $current[$keyword] < $previous[$keyword])) {
            return ["{$path}: {$keyword} became stricter"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function types(mixed $types): array
    {
        return is_string($types) ? [$types] : $this->strings($types);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemas(mixed $values): array
    {
        return is_array($values) ? array_filter($values, 'is_array') : [];
    }
}
