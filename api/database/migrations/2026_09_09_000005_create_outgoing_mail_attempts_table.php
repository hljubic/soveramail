<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_mail_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 32)->default('processing');
            $table->string('provider_message_id', 512)->nullable()->index();
            $table->foreignId('local_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_mail_attempts');
    }
};
