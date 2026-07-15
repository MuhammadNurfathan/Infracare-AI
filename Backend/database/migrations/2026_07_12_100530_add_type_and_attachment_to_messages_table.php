<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {

            $table->enum('type', [
                'text',
                'image',
                'document',
                'audio'
            ])->default('text')->after('sender');

            $table->string('attachment')->nullable()->after('message');

        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {

            $table->dropColumn([
                'type',
                'attachment'
            ]);

        });
    }
};