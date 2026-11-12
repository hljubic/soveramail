<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unsent reply/forward text, kept locally (never sent to Gmail until the user presses Send).
        Schema::create('local_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // reply|forward
            $table->json('to');
            $table->json('cc');
            $table->json('bcc');
            $table->text('subject')->nullable();
            $table->longText('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_drafts');
    }
};
