<?php

declare(strict_types=1);

namespace App\Enums;

enum ShotState: string
{
    case Pending = 'pending';
    case GeneratingImage = 'generating_image';
    case JudgingImage = 'judging_image';
    case GeneratingVideo = 'generating_video';
    case JudgingVideo = 'judging_video';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * Determine whether a transition to the target state is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::GeneratingImage, self::Failed], true),
            self::GeneratingImage => in_array($target, [self::JudgingImage, self::Failed], true),
            self::JudgingImage => in_array($target, [self::GeneratingImage, self::GeneratingVideo, self::Failed], true),
            self::GeneratingVideo => in_array($target, [self::JudgingVideo, self::Failed], true),
            self::JudgingVideo => in_array($target, [self::GeneratingVideo, self::Done, self::Failed], true),
            self::Done, self::Failed => false,
        };
    }
}
