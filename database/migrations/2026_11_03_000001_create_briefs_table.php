<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->text('prompt');
            $table->string('state')->default('pending');
            $table->text('style_anchor')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('final_video_path')->nullable();
            $table->unsignedInteger('budget_used')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefs');
    }
};
