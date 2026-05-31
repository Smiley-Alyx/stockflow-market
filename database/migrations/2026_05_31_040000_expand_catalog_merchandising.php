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
        Schema::create('catalog_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->json('filterable_attributes')->nullable();
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('catalog_brands')->nullOnDelete();
            $table->text('short_description')->nullable()->after('description');
            $table->string('image_url')->nullable()->after('short_description');
            $table->decimal('rating', 3, 2)->default(0)->after('image_url');
            $table->unsignedInteger('rating_count')->default(0)->after('rating');
        });

        Schema::create('catalog_product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('sku')->unique();
            $table->string('status')->default('active');
            $table->string('image_url')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_product_offers');

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['short_description', 'image_url', 'rating', 'rating_count']);
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropColumn('filterable_attributes');
        });

        Schema::dropIfExists('catalog_brands');
    }
};
