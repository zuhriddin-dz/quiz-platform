<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * R4.2 — thrown when any code path tries to change the content of a quiz that
 * is no longer a draft.
 *
 * It is a LogicException, not a validation error, on purpose: the request
 * layer already refuses these via QuizPolicy, so reaching this means a bug in
 * our own code rather than bad input. It should surface as a 500 in
 * development, loudly.
 */
final class ImmutableQuizException extends LogicException
{
    /**
     * @param  array<int, string>  $attributes
     */
    public static function forAttributes(array $attributes): self
    {
        return new self(sprintf(
            'Cannot change [%s] on a quiz that is no longer a draft. Duplicate it instead.',
            implode(', ', $attributes),
        ));
    }

    public static function forQuestions(): self
    {
        return new self(
            'Cannot add, change or remove questions on a quiz that is no longer a draft. '
            .'Duplicate it instead.'
        );
    }

    public static function forDeletion(): self
    {
        return new self(
            'Only drafts can be deleted. Published and unpublished quizzes are kept forever, '
            .'because other players\' history points at them.'
        );
    }
}
