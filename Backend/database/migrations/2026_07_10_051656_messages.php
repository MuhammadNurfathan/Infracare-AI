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
        Schema::create('messages', function (Blueprint $table) {
    $table->id();

    $table->foreignId('conversation_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->enum('sender', [
        'customer',
        'bot',
        'admin'
    ]);

    $table->longText('message');

    $table->decimal('confidence', 5, 2)
        ->nullable()
        ->comment('AI confidence score');

    $table->boolean('is_read')
        ->default(false);

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
