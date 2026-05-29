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
        Schema::create('messaging_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_name');
            $table->string('event_class');
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable();
            $table->json('payload');
            $table->longText('serialized_event');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id']);
            $table->index(['event_name', 'created_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('messaging_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('message_id');
            $table->string('consumer');
            $table->string('status')->default('processing');
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'consumer']);
            $table->index(['consumer', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messaging_inbox');
        Schema::dropIfExists('messaging_outbox');
    }
};
