<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every address seen in the account's mail (from/to/cc/reply-to), for recipient autocomplete.
        // Per account: address books are never shared between mailboxes.
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('email', 320);
            $table->string('name')->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->unsignedInteger('sent_count')->default(0);   // how often the user wrote TO this address
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'email']);
            $table->index(['account_id', 'sent_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
