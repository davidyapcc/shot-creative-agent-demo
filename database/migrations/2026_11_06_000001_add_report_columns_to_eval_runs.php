<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eval_runs', function (Blueprint $table) {
            $table->string('judge_model')->nullable()->after('judge_driver');
            $table->string('rubric_version')->nullable()->after('judge_model');
            $table->string('status')->nullable()->after('dataset');
            $table->json('ledger')->nullable()->after('results');
        });
    }

    public function down(): void
    {
        Schema::table('eval_runs', function (Blueprint $table) {
            $table->dropColumn(['judge_model', 'rubric_version', 'status', 'ledger']);
        });
    }
};
