<?php

declare(strict_types=1);

namespace App\Enums;

enum RunStatus: string
{
    case InProgress = 'in_progress';
    case Passed = 'passed';
    case Failed = 'failed';

    /**
     * Set when a run is superseded — the same player started a new run on the
     * same quiz (R5.1) — or when the quiz was unpublished under them (R4.4).
     * Abandoned runs never count toward a quiz's public stats.
     */
    case Abandoned = 'abandoned';

    public function isFinished(): bool
    {
        return $this !== self::InProgress;
    }

    /**
     * Only finished runs that actually ran to a verdict are counted in
     * `runs_count` / `passes_count` (R5.11). An abandoned run is not a play.
     */
    public function isCounted(): bool
    {
        return $this === self::Passed || $this === self::Failed;
    }
}
