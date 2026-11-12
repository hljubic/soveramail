<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Summarize" results, written ahead of time for unread mail (and on request for the rest),
 * so the button only shows what is stored.
 *  - scope thread:  the whole conversation; message_id = its newest message, so any
 *                   message of the thread finds it and a new reply makes it stale.
 *  - scope message: that one message.
 * A row counts only for the same model and conversation size (`messages`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 8);                 // thread | message
            $table->unsignedInteger('messages')->default(1);
            $table->string('model', 191);
            $table->text('summary');
            $table->timestamps();

            $table->unique(['message_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
