<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('expires_at')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
