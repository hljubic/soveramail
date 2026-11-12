<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the local AI knows about the user, beyond the writing style:
 *  - about:    who the user is (roles, organisations, teaching, projects, standing facts)
 *  - audience: how the user writes within one category ("Clients", a project, ...)
 *  - faq:      recurring questions in one category and how the user answers them
 *  - contact:  one card per frequent correspondent (who, how addressed, language, topics)
 * Built by the local model from the mailbox; `source = user` (edited by hand) is never
 * overwritten by a rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);            // about | audience | faq | contact
            $table->string('subject', 320)->default(''); // '' | category id | contact e-mail
            $table->string('title', 255)->nullable();
            $table->text('content');               // '' = looked, nothing worth keeping
            $table->string('source', 8)->default('ai'); // ai | user
            $table->string('model', 191)->nullable();
            $table->unsignedInteger('evidence')->default(0); // mails the model read
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'kind', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};
