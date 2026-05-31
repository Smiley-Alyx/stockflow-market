<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->char('delivery_country_code', 2)->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_postal_code')->nullable();
            $table->string('delivery_address_line_1')->nullable();
            $table->string('delivery_address_line_2')->nullable();
            $table->timestamp('checkout_at')->nullable();
        });

        Schema::create('orders_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders_orders')->cascadeOnDelete();
            $table->string('delivery_service');
            $table->timestamps();
        });

        Schema::create('orders_shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('orders_shipments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('orders_order_items')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['shipment_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders_shipment_items');
        Schema::dropIfExists('orders_shipments');

        Schema::table('orders_orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'recipient_name',
                'recipient_phone',
                'delivery_country_code',
                'delivery_city',
                'delivery_postal_code',
                'delivery_address_line_1',
                'delivery_address_line_2',
                'checkout_at',
            ]);
        });
    }
};
