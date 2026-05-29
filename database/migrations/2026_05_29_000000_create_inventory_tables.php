<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('city_code');
            $table->string('city_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city_code', 'is_active']);
        });

        Schema::create('inventory_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->string('sku');
            $table->unsignedBigInteger('on_hand_quantity')->default(0);
            $table->unsignedBigInteger('reserved_quantity')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->unique(['warehouse_id', 'sku']);
            $table->index('product_id');
            $table->index('sku');
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('inventory_stock_items')->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('quantity');
            $table->string('status')->default('active');
            $table->timestamp('reservation_expires_at');
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'reservation_expires_at']);
            $table->index('stock_item_id');
        });

        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('inventory_stock_items')->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('inventory_reservations')->nullOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('quantity');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_stock_items');
        Schema::dropIfExists('inventory_warehouses');
    }
};
