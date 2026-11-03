<?php

declare(strict_types=1);

namespace App\Enums;

enum JudgementVerdict: string
{
  case Pass = 'pass';
  case Fail = 'fail';
}
