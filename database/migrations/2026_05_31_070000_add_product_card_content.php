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
        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->json('card_attribute_names')->nullable();
        });

        Schema::create('catalog_product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('storage_files')->cascadeOnDelete();
            $table->string('type');
            $table->string('title')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'file_id', 'type']);
            $table->index(['product_id', 'type', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_product_files');

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropColumn('card_attribute_names');
        });
    }
};
