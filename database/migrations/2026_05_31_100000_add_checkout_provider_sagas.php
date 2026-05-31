<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders_checkout_sagas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders_orders')->cascadeOnDelete();
            $table->uuid('correlation_id')->unique();
            $table->string('payment_id')->unique();
            $table->string('authorization_id')->nullable();
            $table->string('capture_id')->nullable();
            $table->string('status');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::create('orders_checkout_saga_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_saga_id')->constrained('orders_checkout_sagas')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('orders_order_items')->cascadeOnDelete();
            $table->string('reservation_id')->unique();
            $table->string('status');
            $table->timestamps();

            $table->unique(['checkout_saga_id', 'order_item_id']);
        });

        Schema::create('messaging_provider_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('routing_key');
            $table->uuid('message_id')->unique();
            $table->uuid('correlation_id');
            $table->uuid('causation_id')->nullable();
            $table->string('idempotency_key');
            $table->json('headers');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id']);
            $table->index(['correlation_id', 'created_at']);
        });

        Schema::table('orders_shipments', function (Blueprint $table) {
            $table->string('provider_shipment_id')->nullable()->unique();
            $table->string('provider_status')->nullable();
            $table->string('tracking_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders_shipments', function (Blueprint $table) {
            $table->dropUnique(['provider_shipment_id']);
            $table->dropColumn(['provider_shipment_id', 'provider_status', 'tracking_number']);
        });

        Schema::dropIfExists('messaging_provider_outbox');
        Schema::dropIfExists('orders_checkout_saga_reservations');
        Schema::dropIfExists('orders_checkout_sagas');
    }
};
