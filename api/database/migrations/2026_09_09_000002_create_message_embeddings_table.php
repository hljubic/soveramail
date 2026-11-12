<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Several vectors per message (pieces of ~2000 characters, `chunk`), stored as
        // packed float32 (BLOB); similarity is computed in PHP per account, which is
        // plenty for a personal mailbox. `version` marks how the text was cut
        // (MessageEmbedding::VERSION). The VectorStore interface allows sqlite-vec /
        // MySQL VECTOR later.
        Schema::create('message_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chunk')->default(0);
            $table->unsignedTinyInteger('version')->default(1);
            $table->string('model', 191);
            $table->unsignedSmallInteger('dims');
            $table->string('content_hash', 64);
            $table->binary('vector');
            $table->timestamps();

            $table->index(['account_id', 'model']);
            $table->unique(['message_id', 'chunk'], 'message_embeddings_message_chunk_unique');
            $table->index(['account_id', 'model', 'version'], 'message_embeddings_account_model_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_embeddings');
    }
};
