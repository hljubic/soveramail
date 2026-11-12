<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // Gmail id, or "<folder path>::<uid>" for IMAP (deep folder paths need the room).
            $table->string('provider_message_id', 512);
            $table->string('thread_id', 191)->nullable()->index();
            $table->string('history_id', 64)->nullable();

            // RFC 5322 identifiers (threading / later "Reply Like Me" retrieval).
            $table->string('message_id_header', 998)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->text('references_header')->nullable();

            $table->text('subject')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable()->index();
            $table->json('to_recipients');
            $table->json('cc_recipients');
            $table->json('bcc_recipients');
            $table->json('reply_to');

            $table->text('snippet')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('headers');

            $table->json('label_ids');
            $table->boolean('is_unread')->default(false);
            $table->boolean('is_starred')->default(false);
            // high | medium | low, judged once by the local model for unread mail.
            $table->string('urgency', 8)->nullable();
            $table->timestamp('urgency_at')->nullable();
            $table->boolean('has_attachments')->default(false);
            // Attachment screening: danger (blocked until acknowledged) / caution (archives).
            $table->boolean('is_risky')->default(false)->index();
            $table->timestamp('risk_acknowledged_at')->nullable();
            $table->boolean('has_caution')->default(false);
            $table->unsignedInteger('size_estimate')->default(0);

            $table->timestamp('sent_at')->nullable();      // Date: header
            $table->timestamp('received_at')->index();     // Gmail internalDate
            // Gone on the server: hidden locally, never erased.
            $table->timestamp('remote_deleted_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['account_id', 'provider_message_id']);
            $table->index(['account_id', 'received_at']);
            $table->index(['account_id', 'thread_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE messages ADD FULLTEXT messages_fulltext (subject, snippet, from_name, from_email, body_text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
