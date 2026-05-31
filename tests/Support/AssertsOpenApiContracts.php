<?php

namespace Tests\Support;

trait AssertsOpenApiContracts
{
    private function assertContractDeclaresResponse(string $contract, string $path, string $status, string $schema): void
    {
        $contents = file_get_contents($contract);

        $this->assertIsString($contents);
        $this->assertStringContainsString($path.':', $contents, $contract);
        $this->assertStringContainsString("'".$status."':", $contents, $contract);
        $this->assertStringContainsString("\$ref: '#/components/schemas/".$schema."'", $contents, $contract);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSchemaMatchesPayload(string $contract, string $schema, array $payload): void
    {
        foreach ($this->requiredKeys($contract, $schema) as $key) {
            $this->assertArrayHasKey($key, $payload, $contract.' schema '.$schema);
        }

        foreach ($this->schemaProperties($contract, $schema) as $key => $property) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $this->assertValueMatchesSchema($payload[$key], $property, $contract.' schema '.$schema.' property '.$key);
        }
    }

    /**
     * @return array<int, string>
     */
    private function requiredKeys(string $contract, string $schema): array
    {
        $schemaBody = $this->schemaBody($contract, $schema);

        preg_match(
            '/^      required:\n(?<required>(?:        - .+\n)+)/m',
            $schemaBody,
            $matches,
        );

        $this->assertArrayHasKey('required', $matches, $contract.' schema '.$schema);

        return array_map(
            static fn (string $line): string => preg_replace('/^\s*-\s*/', '', trim($line)),
            array_filter(explode("\n", trim($matches['required']))),
        );
    }

    private function schemaBody(string $contract, string $schema): string
    {
        $contents = file_get_contents($contract);

        $this->assertIsString($contents);
        preg_match(
            '/^    '.preg_quote($schema, '/').":\n(?<body>(?:      .+\n|      \n)*)/m",
            $contents,
            $schemaMatches,
        );

        $this->assertArrayHasKey('body', $schemaMatches, $contract.' schema '.$schema);

        return $schemaMatches['body'];
    }

    /**
     * @return array<string, array{types: array<int, string>, ref: string|null, item_ref: string|null, format: string|null, enum: array<int, string>}>
     */
    private function schemaProperties(string $contract, string $schema): array
    {
        preg_match(
            '/^      properties:\n(?<properties>(?:        .+\n|          .+\n|            .+\n)*)/m',
            $this->schemaBody($contract, $schema),
            $matches,
        );

        $this->assertArrayHasKey('properties', $matches, $contract.' schema '.$schema);

        $properties = [];
        $currentProperty = null;
        $currentBody = [];

        foreach (explode("\n", rtrim($matches['properties'])) as $line) {
            if (preg_match('/^        (?<property>[A-Za-z0-9_]+):$/', $line, $propertyMatch) === 1) {
                if ($currentProperty !== null) {
                    $properties[$currentProperty] = $this->parsePropertySchema(implode("\n", $currentBody));
                }

                $currentProperty = $propertyMatch['property'];
                $currentBody = [];

                continue;
            }

            $currentBody[] = $line;
        }

        if ($currentProperty !== null) {
            $properties[$currentProperty] = $this->parsePropertySchema(implode("\n", $currentBody));
        }

        return $properties;
    }

    /**
     * @return array{types: array<int, string>, ref: string|null, item_ref: string|null, format: string|null, enum: array<int, string>}
     */
    private function parsePropertySchema(string $body): array
    {
        preg_match('/^          \$ref: \'#\/components\/schemas\/(?<ref>[A-Za-z0-9_]+)\'$/m', $body, $refMatch);
        preg_match('/^\s+- \$ref: \'#\/components\/schemas\/(?<ref>[A-Za-z0-9_]+)\'$/m', $body, $oneOfRefMatch);
        preg_match('/^\s+type: (?<type>[A-Za-z0-9_]+)$/m', $body, $typeMatch);
        preg_match_all('/^\s+- \'?(?<type>[A-Za-z0-9_]+)\'?$/m', $body, $typeListMatches);
        preg_match_all('/^\s+- type: \'?(?<type>[A-Za-z0-9_]+)\'?$/m', $body, $oneOfTypeMatches);
        preg_match('/^            \$ref: \'#\/components\/schemas\/(?<ref>[A-Za-z0-9_]+)\'$/m', $body, $itemRefMatch);
        preg_match('/^\s+format: (?<format>[A-Za-z0-9_-]+)$/m', $body, $formatMatch);
        preg_match('/^\s+enum:\n(?<enum>(?:\s+- .+\n?)+)/m', $body, $enumMatch);

        $types = [];
        $enum = [];

        if (isset($typeMatch['type'])) {
            $types[] = $typeMatch['type'];
        }

        if (isset($typeListMatches['type'])) {
            $types = array_merge($types, $typeListMatches['type']);
        }

        if (isset($oneOfTypeMatches['type'])) {
            $types = array_merge($types, $oneOfTypeMatches['type']);
        }

        if (isset($enumMatch['enum'])) {
            $enum = array_map(
                static fn (string $line): string => trim(preg_replace('/^\s*-\s*/', '', trim($line)), "'\""),
                array_filter(explode("\n", trim($enumMatch['enum']))),
            );
        }

        return [
            'types' => array_values(array_unique($types)),
            'ref' => $refMatch['ref'] ?? $oneOfRefMatch['ref'] ?? null,
            'item_ref' => $itemRefMatch['ref'] ?? null,
            'format' => $formatMatch['format'] ?? null,
            'enum' => $enum,
        ];
    }

    /**
     * @param  array{types: array<int, string>, ref: string|null, item_ref: string|null, format: string|null, enum: array<int, string>}  $schema
     */
    private function assertValueMatchesSchema(mixed $value, array $schema, string $message): void
    {
        if ($value === null) {
            $this->assertContains('null', $schema['types'], $message);

            return;
        }

        if ($schema['ref'] !== null) {
            $this->assertIsArray($value, $message);
            $this->assertFalse(array_is_list($value), $message);

            return;
        }

        if (in_array('array', $schema['types'], true)) {
            $this->assertIsArray($value, $message);
            $this->assertTrue(array_is_list($value), $message);

            if ($schema['item_ref'] !== null) {
                foreach ($value as $item) {
                    $this->assertIsArray($item, $message);
                    $this->assertFalse(array_is_list($item), $message);
                }
            }

            return;
        }

        if (in_array('integer', $schema['types'], true)) {
            $this->assertIsInt($value, $message);

            return;
        }

        if (in_array('number', $schema['types'], true)) {
            $this->assertIsNumeric($value, $message);

            return;
        }

        if (in_array('string', $schema['types'], true)) {
            $this->assertIsString($value, $message);
            $this->assertStringFormatMatchesSchema($value, $schema['format'], $message);
            $this->assertStringEnumMatchesSchema($value, $schema['enum'], $message);

            return;
        }

        if (in_array('boolean', $schema['types'], true)) {
            $this->assertIsBool($value, $message);

            return;
        }

        if (in_array('object', $schema['types'], true)) {
            $this->assertIsArray($value, $message);
            $this->assertFalse(array_is_list($value), $message);

            return;
        }

        $this->fail($message.' has no supported OpenAPI type assertion.');
    }

    private function assertStringFormatMatchesSchema(string $value, ?string $format, string $message): void
    {
        if ($format === null) {
            return;
        }

        if ($format === 'date-time') {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                $value,
                $message,
            );

            return;
        }

        $this->fail($message.' has unsupported OpenAPI string format '.$format.'.');
    }

    /**
     * @param  array<int, string>  $enum
     */
    private function assertStringEnumMatchesSchema(string $value, array $enum, string $message): void
    {
        if ($enum === []) {
            return;
        }

        $this->assertContains($value, $enum, $message);
    }
}
