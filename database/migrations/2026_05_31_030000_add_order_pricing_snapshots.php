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
        Schema::table('orders_orders', function (Blueprint $table) {
            $table->string('city_code')->nullable()->after('status');
            $table->string('promo_code')->nullable()->after('city_code');
            $table->unsignedBigInteger('subtotal_amount_minor')->default(0)->after('promo_code');
            $table->unsignedBigInteger('discount_amount_minor')->default(0)->after('subtotal_amount_minor');
        });

        Schema::table('orders_order_items', function (Blueprint $table) {
            $table->foreignId('pricing_product_price_id')
                ->nullable()
                ->after('product_id')
                ->constrained('pricing_product_prices')
                ->nullOnDelete();
            $table->string('price_type')->default('retail')->after('quantity');
            $table->string('price_city_code')->nullable()->after('price_type');
            $table->unsignedInteger('price_version')->default(1)->after('price_city_code');
            $table->timestamp('price_active_from')->nullable()->after('price_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_product_price_id');
            $table->dropColumn(['price_type', 'price_city_code', 'price_version', 'price_active_from']);
        });

        Schema::table('orders_orders', function (Blueprint $table) {
            $table->dropColumn(['city_code', 'promo_code', 'subtotal_amount_minor', 'discount_amount_minor']);
        });
    }
};
