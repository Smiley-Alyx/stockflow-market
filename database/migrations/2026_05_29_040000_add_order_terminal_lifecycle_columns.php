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
            $table->timestamp('paid_at')->nullable()->after('confirmed_at');
            $table->timestamp('cancelled_at')->nullable()->after('paid_at');
            $table->timestamp('expired_at')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_orders', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'cancelled_at', 'expired_at']);
        });
    }
};
