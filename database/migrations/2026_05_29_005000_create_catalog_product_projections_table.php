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
        Schema::create('catalog_product_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('catalog_categories')->restrictOnDelete();
            $table->string('category_slug');
            $table->boolean('category_is_active');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku');
            $table->string('status');
            $table->boolean('in_stock')->default(false);
            $table->unsignedBigInteger('available_quantity')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['status', 'category_is_active', 'name']);
            $table->index(['category_slug', 'status', 'category_is_active', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_product_projections');
    }
};
