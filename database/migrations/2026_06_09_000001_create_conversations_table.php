<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('external_participant_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['workspace_id', 'channel', 'external_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
