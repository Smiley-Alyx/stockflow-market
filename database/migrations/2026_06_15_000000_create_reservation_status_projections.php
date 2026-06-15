<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders_reservation_status_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders_orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('orders_order_items')->cascadeOnDelete();
            $table->string('reservation_id')->unique();
            $table->uuid('correlation_id');
            $table->string('status');
            $table->text('reason')->nullable();
            $table->string('last_routing_key');
            $table->uuid('last_message_id');
            $table->timestamp('projected_at');
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['correlation_id', 'projected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders_reservation_status_projections');
    }
};
