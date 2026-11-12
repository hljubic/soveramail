<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->text('provider_attachment_id')->nullable();
            $table->string('part_id', 64)->nullable();
            $table->string('filename', 512)->nullable();
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('content_id', 512)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->string('risk_level', 16)->nullable(); // null | caution | danger
            $table->string('risk_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
