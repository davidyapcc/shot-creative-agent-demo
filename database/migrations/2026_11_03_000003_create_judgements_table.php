<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('judgements', function (Blueprint $table)
    {
      $table->id();
      $table->foreignId('shot_id')->constrained('shots')->cascadeOnDelete();
      $table->string('stage');
      $table->json('scores');
      $table->json('hard_fail_tags');
      $table->text('reason')->nullable();
      $table->string('verdict');
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('judgements');
  }
};
