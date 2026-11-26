<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The quiz lifecycle: draft -> published <-> unpublished.
 *
 * The three predicates below are the spec's lifecycle table (§5) expressed
 * once, so Phase 3's policies and Phase 4's guards cannot drift apart.
 */
enum QuizStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    /**
     * Only drafts can be edited. Published quizzes are immutable permanently,
     * and an unpublished quiz is a published quiz that is merely hidden.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Only drafts can be deleted (R4.6). Published and unpublished quizzes can
     * never be deleted, because other players' history points at them.
     */
    public function isDeletable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Everyone, guests included, may play a published quiz. Drafts and
     * unpublished quizzes are playable only by their creator.
     */
    public function isPubliclyPlayable(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
        };
    }
}
