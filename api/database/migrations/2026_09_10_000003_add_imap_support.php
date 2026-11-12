<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-folder sync cursor of an IMAP account (UIDVALIDITY + highest synced UID).
 * The server credentials live on the account (accounts.credentials).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('path', 512);
            $table->string('role', 16)->default('other'); // inbox|sent|drafts|trash|junk|archive|other
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_folders');
    }
};
