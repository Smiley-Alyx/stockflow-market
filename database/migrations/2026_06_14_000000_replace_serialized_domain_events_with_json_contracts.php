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
        Schema::table('messaging_outbox', function (Blueprint $table) {
            $table->unsignedInteger('schema_version')->default(1)->after('event_name');
        });

        Schema::table('messaging_outbox', function (Blueprint $table) {
            $table->dropColumn(['event_class', 'serialized_event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messaging_outbox', function (Blueprint $table) {
            $table->string('event_class')->nullable()->after('event_name');
            $table->longText('serialized_event')->nullable()->after('payload');
            $table->dropColumn('schema_version');
        });
    }
};
