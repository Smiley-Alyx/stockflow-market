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
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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

        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('inventory_stock_items')->cascadeOnDelete();
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
        Schema::dropIfExists('inventory_stock_items');
        Schema::dropIfExists('inventory_warehouses');
    }
};
