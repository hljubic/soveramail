<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_label_id', 191);
            $table->string('name');
            $table->string('type', 16)->default('user'); // system|user
            $table->string('color_bg', 16)->nullable();
            $table->string('color_fg', 16)->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'provider_label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
