<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One writing-style profile per mailbox, built from the user's own sent mail:
        // deterministic statistics (greetings, sign-offs, signature, vocabulary, length, …)
        // plus an optional style guide written by the local model. Fed to every AI draft.
        Schema::create('style_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('stats')->nullable();
            $table->text('summary')->nullable();
            $table->string('summary_model', 191)->nullable();
            $table->timestamp('summary_built_at')->nullable();
            $table->boolean('summary_edited')->default(false);
            $table->unsignedInteger('sample_count')->default(0);
            $table->timestamp('analysed_until')->nullable();   // newest sent_at that went into the stats
            $table->timestamp('built_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_profiles');
    }
};
