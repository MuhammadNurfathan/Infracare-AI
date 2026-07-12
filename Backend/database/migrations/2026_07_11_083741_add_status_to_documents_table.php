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
    Schema::table('documents', function (Blueprint $table) {
        $table->enum('status', [
            'uploaded',
            'processing',
            'processed',
            'failed'
        ])->default('uploaded');
    });
}

public function down(): void
{
    Schema::table('documents', function (Blueprint $table) {
        $table->dropColumn('status');
    });
}
};
