<?php

namespace Tests\Feature;

use App\Infrastructure\Messaging\InboxConsumer;
use App\Infrastructure\Messaging\InboxMessage;
use App\Infrastructure\Observability\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboxConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_does_not_process_fresh_claim_twice(): void
    {
        InboxMessage::query()->create([
            'message_id' => 'msg-demo-001',
            'consumer' => self::class,
            'status' => InboxMessage::STATUS_PROCESSING,
        ]);
        $handled = false;

        $this->app->make(InboxConsumer::class)->consume('msg-demo-001', self::class, function () use (&$handled): void {
            $handled = true;
        });

        $this->assertFalse($handled);
    }

    public function test_inbox_reclaims_stale_processing_claim(): void
    {
        $message = InboxMessage::query()->create([
            'message_id' => 'msg-demo-002',
            'consumer' => self::class,
            'status' => InboxMessage::STATUS_PROCESSING,
        ]);
        DB::table('messaging_inbox')->where('id', $message->id)->update([
            'updated_at' => now()->subMinutes(2),
        ]);
        $handled = false;

        $this->app->make(InboxConsumer::class)->consume('msg-demo-002', self::class, function () use (&$handled): void {
            $handled = true;
        });

        $this->assertTrue($handled);
        $this->assertDatabaseHas('messaging_inbox', [
            'id' => $message->id,
            'status' => InboxMessage::STATUS_PROCESSED,
            'last_error' => null,
        ]);
        $this->assertSame(1, $this->app->make(MetricsCollector::class)->value(
            'stockflow_messaging_stale_claim_recoveries_total',
            ['store' => 'inbox'],
        ));
    }
}
