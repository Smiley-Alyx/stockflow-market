<?php

namespace Tests\Unit;

use App\Infrastructure\Messaging\DomainEventSchemaCompatibility;
use PHPUnit\Framework\TestCase;

class DomainEventSchemaCompatibilityTest extends TestCase
{
    public function test_additive_schema_change_is_compatible(): void
    {
        $previous = $this->schema();
        $current = $this->schema();
        $current['properties']['optional'] = ['type' => 'string'];

        $this->assertSame([], $this->compatibility()->breakingChanges($previous, $current));
    }

    public function test_new_required_field_is_breaking(): void
    {
        $previous = $this->schema();
        $current = $this->schema();
        $current['required'][] = 'reason';
        $current['properties']['reason'] = ['type' => 'string'];

        $this->assertContains('$.reason: field became required', $this->compatibility()->breakingChanges($previous, $current));
    }

    public function test_narrowed_type_is_breaking(): void
    {
        $previous = $this->schema();
        $previous['properties']['occurred_at'] = ['type' => ['string', 'null']];
        $current = $this->schema();
        $current['properties']['occurred_at'] = ['type' => 'string'];

        $this->assertContains('$.occurred_at: type null is no longer accepted', $this->compatibility()->breakingChanges($previous, $current));
    }

    public function test_stricter_constraints_are_breaking(): void
    {
        $previous = $this->schema();
        $previous['properties']['status'] = ['type' => 'string', 'enum' => ['created', 'paid']];
        $current = $this->schema();
        $current['properties']['status'] = ['type' => 'string', 'enum' => ['paid'], 'minLength' => 2];

        $changes = $this->compatibility()->breakingChanges($previous, $current);

        $this->assertContains('$.status: enum value "created" is no longer accepted', $changes);
        $this->assertContains('$.status: minLength became stricter', $changes);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['event'],
            'properties' => [
                'event' => ['const' => 'orders.order.created'],
            ],
            'additionalProperties' => true,
        ];
    }

    private function compatibility(): DomainEventSchemaCompatibility
    {
        return new DomainEventSchemaCompatibility;
    }
}
