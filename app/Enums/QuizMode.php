<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a run is refereed.
 *
 * Stored as a string column, not a database enum type: Postgres enum types and
 * SQLite have no common ground, and migrations must run on both (R0.4). The
 * enum lives in PHP and is applied as a cast.
 */
enum QuizMode: string
{
    /** Server-authoritative. One question at a time, server clock decides. */
    case Strict = 'strict';

    /** Client-checked against answer hashes, re-verified on submit. */
    case Casual = 'casual';

    public function label(): string
    {
        return match ($this) {
            self::Strict => 'Strict',
            self::Casual => 'Casual',
        };
    }

    /**
     * Casual results are labelled "self-reported" in creator analytics (R6.5,
     * R9.4), because the client reports its own timings.
     */
    public function isSelfReported(): bool
    {
        return $this === self::Casual;
    }
}
