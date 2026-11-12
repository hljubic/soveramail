<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local categories (they exist only in Sovera Mail, never in Gmail):
 *  - categories: what the user defined, with a description the local model reads
 *  - thread_categories: one main category per conversation (thread), set by the
 *    model ('ai') or by the user ('user', always wins)
 *  (messages.urgency, the other half of the sorting, is created with the messages table)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('color', 16)->default('#64748b');
            $table->unsignedInteger('position')->default(0);
            // "Other": where everything that fits nowhere else goes; cannot be deleted.
            $table->boolean('is_fallback')->default(false);
            $table->timestamps();
        });

        Schema::create('thread_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id', 191);
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('source', 8)->default('ai'); // ai | user
            $table->string('model', 191)->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'thread_id']);
            $table->index(['category_id', 'account_id']);
        });

        DB::table('categories')->insert([
            'name' => 'Other',
            'description' => 'Everything that does not clearly belong to any other category.',
            'color' => '#64748b',
            'position' => 1000,
            'is_fallback' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_categories');
        Schema::dropIfExists('categories');
    }
};
