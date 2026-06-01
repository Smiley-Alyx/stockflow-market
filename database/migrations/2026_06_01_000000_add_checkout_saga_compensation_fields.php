<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_checkout_sagas', function (Blueprint $table) {
            $table->string('refund_id')->nullable()->after('capture_id');
            $table->string('refund_status')->nullable()->after('refund_id');
            $table->text('compensation_failure_reason')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders_checkout_sagas', function (Blueprint $table) {
            $table->dropColumn(['refund_id', 'refund_status', 'compensation_failure_reason']);
        });
    }
};
