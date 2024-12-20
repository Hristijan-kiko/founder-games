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
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('video_url');
            $table->string('transcription_id');
            $table->json('text')->nullable(); // Text of the transcription
            $table->json('summary')->nullable();
            $table->json('keypoints')->nullable();
            $table->string('status'); // processing, completed, failed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
