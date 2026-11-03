<?php

declare(strict_types=1);

namespace App\Enums;

enum JudgementStage: string
{
  case Image = 'image';
  case Video = 'video';
}
