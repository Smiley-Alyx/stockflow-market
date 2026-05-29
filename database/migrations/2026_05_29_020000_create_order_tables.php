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
        Schema::create('orders_carts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('orders_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('orders_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
        });

        Schema::create('orders_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->nullable()->constrained('orders_carts')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('total_amount_minor')->default(0);
            $table->char('currency', 3)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('orders_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->string('sku');
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('line_amount_minor');
            $table->timestamps();

            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_order_items');
        Schema::dropIfExists('orders_orders');
        Schema::dropIfExists('orders_cart_items');
        Schema::dropIfExists('orders_carts');
    }
};
