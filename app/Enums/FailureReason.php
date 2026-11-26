<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a run ended. Sudden death means any of these ends it immediately.
 */
enum FailureReason: string
{
    case WrongAnswer = 'wrong_answer';
    case Timeout = 'timeout';

    /** The tab was hidden while a question was showing and its timer ran. */
    case FocusLost = 'focus_lost';

    /**
     * Shown on the fail screen (R5.10). The picked option is highlighted for a
     * wrong answer, so that case has no banner of its own.
     */
    public function playerMessage(): ?string
    {
        return match ($this) {
            self::WrongAnswer => null,
            self::Timeout => "Time's up",
            self::FocusLost => 'Tab left',
        };
    }

    /**
     * A timeout and a focus loss have no chosen option, so
     * `run_answers.chosen_option_index` is null for both.
     */
    public function hasChosenOption(): bool
    {
        return $this === self::WrongAnswer;
    }
}
