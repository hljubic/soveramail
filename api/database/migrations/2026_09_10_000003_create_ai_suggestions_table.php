<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every "Compose with AI" result, and — once the mail is sent — what the user
        // actually sent plus the word-level diff. Feeds the writing-style profile.
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();   // the mail being answered
            $table->string('kind', 16);                                                              // reply | forward | new
            $table->string('model', 191)->nullable();
            $table->longText('suggestion');
            $table->longText('final_text')->nullable();
            $table->foreignId('sent_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->decimal('similarity', 5, 4)->nullable();
            $table->json('stats')->nullable();
            $table->json('edits')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
