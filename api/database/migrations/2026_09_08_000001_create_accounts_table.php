<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('gmail');
            $table->string('email')->index();
            $table->string('display_name')->nullable();
            $table->string('avatar_url', 1024)->nullable();

            // OAuth tokens are stored encrypted (Eloquent `encrypted` cast).
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            // IMAP/SMTP server credentials, encrypted JSON (Eloquent cast).
            $table->text('credentials')->nullable();

            // Gmail incremental sync cursor (users.history.list startHistoryId).
            $table->string('history_id', 64)->nullable();
            $table->boolean('initial_sync_done')->default(false);

            $table->string('sync_status', 32)->default('idle'); // idle|queued|syncing|error|needs_reauth
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
