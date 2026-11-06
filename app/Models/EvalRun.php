<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvalRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'judge_driver',
        'judge_model',
        'rubric_version',
        'dataset',
        'status',
        'metrics',
        'results',
        'ledger',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'results' => 'array',
            'ledger' => 'array',
        ];
    }
}
