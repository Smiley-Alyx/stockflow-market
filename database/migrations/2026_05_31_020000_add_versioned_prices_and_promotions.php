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
        Schema::table('pricing_product_prices', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'price_type']);
            $table->dropIndex(['price_type', 'is_active']);

            $table->string('city_code')->nullable()->after('price_type');
            $table->unsignedInteger('price_version')->default(1)->after('city_code');
            $table->timestamp('active_from')->nullable()->after('is_active');
            $table->timestamp('active_until')->nullable()->after('active_from');

            $table->unique(['product_id', 'price_type', 'city_code', 'price_version']);
            $table->index(['price_type', 'city_code', 'is_active']);
            $table->index(['product_id', 'price_type', 'city_code', 'active_from']);
        });

        Schema::create('pricing_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('discount_type');
            $table->unsignedInteger('discount_value');
            $table->char('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['code', 'is_active']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_promotions');

        Schema::table('pricing_product_prices', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'price_type', 'city_code', 'price_version']);
            $table->dropIndex(['price_type', 'city_code', 'is_active']);
            $table->dropIndex(['product_id', 'price_type', 'city_code', 'active_from']);
            $table->dropColumn(['city_code', 'price_version', 'active_from', 'active_until']);

            $table->unique(['product_id', 'price_type']);
            $table->index(['price_type', 'is_active']);
        });
    }
};
