<?php

declare(strict_types=1);

namespace App\Enums;

enum BriefState: string
{
    case Pending = 'pending';
    case Planning = 'planning';
    case Keyframes = 'keyframes';
    case Rendering = 'rendering';
    case Audio = 'audio';
    case Muxing = 'muxing';
    case Done = 'done';
    case Degraded = 'degraded';
    case Failed = 'failed';

    /**
     * Determine whether a transition to the target state is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Planning, self::Failed], true),
            self::Planning => in_array($target, [self::Keyframes, self::Degraded, self::Failed], true),
            self::Keyframes => in_array($target, [self::Rendering, self::Degraded, self::Failed], true),
            self::Rendering => in_array($target, [self::Audio, self::Degraded, self::Failed], true),
            self::Audio => in_array($target, [self::Muxing, self::Degraded, self::Failed], true),
            self::Muxing => in_array($target, [self::Done, self::Degraded, self::Failed], true),
            self::Done, self::Degraded, self::Failed => false,
        };
    }
}
