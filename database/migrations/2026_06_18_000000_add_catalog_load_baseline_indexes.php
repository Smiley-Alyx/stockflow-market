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
        Schema::table('catalog_product_projections', function (Blueprint $table) {
            $table->index(['status', 'category_is_active', 'published_at', 'product_id'], 'catalog_projection_status_published_idx');
            $table->index(['category_id', 'status', 'category_is_active', 'published_at', 'product_id'], 'catalog_projection_category_published_idx');
            $table->index(['status', 'category_is_active', 'in_stock', 'published_at', 'product_id'], 'catalog_projection_stock_published_idx');
        });

        Schema::table('pricing_product_prices', function (Blueprint $table) {
            $table->index(['product_id', 'price_type', 'is_active', 'city_code', 'amount_minor'], 'pricing_prices_catalog_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_product_prices', function (Blueprint $table) {
            $table->dropIndex('pricing_prices_catalog_lookup_idx');
        });

        Schema::table('catalog_product_projections', function (Blueprint $table) {
            $table->dropIndex('catalog_projection_stock_published_idx');
            $table->dropIndex('catalog_projection_category_published_idx');
            $table->dropIndex('catalog_projection_status_published_idx');
        });
    }
};
