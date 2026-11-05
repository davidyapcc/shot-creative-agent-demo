<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

class InvalidStateTransitionException extends DomainException
{
    public static function fromStates(string $from, string $to, string $entity): self
    {
        return new self("Cannot transition {$entity} from '{$from}' to '{$to}'.");
    }

    public static function mismatchedStateType(string $entity, string $expected, string $given): self
    {
        return new self("{$entity} expects a {$expected}, got {$given}.");
    }
}
