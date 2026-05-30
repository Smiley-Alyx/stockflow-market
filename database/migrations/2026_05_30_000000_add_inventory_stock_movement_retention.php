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
        Schema::table('inventory_stock_movements', function (Blueprint $table) {
            $table->index(['stock_item_id', 'occurred_at', 'id'], 'inventory_movements_stock_cursor_idx');
            $table->index(['occurred_at', 'id'], 'inventory_movements_retention_idx');
            $table->index(['stock_item_id', 'type', 'occurred_at', 'id'], 'inventory_movements_stock_type_cursor_idx');
        });

        Schema::create('inventory_stock_movement_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id')->unique();
            $table->unsignedBigInteger('stock_item_id');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->string('type');
            $table->unsignedBigInteger('quantity');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('archived_at');

            $table->index(['stock_item_id', 'occurred_at', 'original_id'], 'inventory_archived_movements_stock_cursor_idx');
            $table->index(['occurred_at', 'original_id'], 'inventory_archived_movements_retention_idx');
            $table->index(['reference_type', 'reference_id'], 'inventory_archived_movements_reference_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movement_archives');

        Schema::table('inventory_stock_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_movements_stock_cursor_idx');
            $table->dropIndex('inventory_movements_retention_idx');
            $table->dropIndex('inventory_movements_stock_type_cursor_idx');
        });
    }
};
