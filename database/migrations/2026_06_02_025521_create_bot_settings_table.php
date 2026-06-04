<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('business_name')->nullable();
            $table->text('business_description')->nullable();
            $table->unsignedSmallInteger('default_appointment_duration_minutes')->default(30);
            $table->string('booking_timezone')->default('UTC');
            $table->json('booking_hours')->nullable();
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->text('cancellation_instructions')->nullable();
            $table->text('bot_tone')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
