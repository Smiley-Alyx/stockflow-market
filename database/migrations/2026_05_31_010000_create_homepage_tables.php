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
        Schema::create('homepage_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('homepage_block_products', function (Blueprint $table) {
            $table->foreignId('homepage_block_id')->constrained('homepage_blocks')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->primary(['homepage_block_id', 'product_id']);
            $table->index(['homepage_block_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homepage_block_products');
        Schema::dropIfExists('homepage_blocks');
    }
};
