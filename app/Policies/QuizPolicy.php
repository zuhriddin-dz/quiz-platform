<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

/**
 * R3.5 — every authorization rule about a quiz, in one place.
 *
 * Auto-discovered: App\Models\Quiz -> App\Policies\QuizPolicy, so nothing
 * needs registering in a provider.
 *
 * The lifecycle predicates come from QuizStatus rather than being re-spelled
 * here, so this policy and Phase 4's guards cannot disagree about what
 * "editable" means.
 *
 * Abilities beyond `view` / `create` / `update` are declared now and consumed
 * by later phases: delete, publish, unpublish and duplicate are Phase 4,
 * viewStats is Phase 9.
 */
final class QuizPolicy
{
    /**
     * Who may open a quiz page at all.
     *
     * Published quizzes are open to everyone, guests included — listed or
     * unlisted, since an unlisted quiz is shared by link. Drafts and
     * unpublished quizzes are the creator's alone (R3.5).
     */
    public function view(?User $user, Quiz $quiz): bool
    {
        if ($quiz->status->isPubliclyPlayable()) {
            return true;
        }

        return $quiz->isOwnedBy($user);
    }

    /**
     * Any signed-in user may create a quiz. "Creator" is not a role, just
     * ownership of a row.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * R3.5 + §5 — only the creator, and only while it is a draft. Published
     * quizzes are immutable permanently; Duplicate is the way to change one.
     */
    public function update(User $user, Quiz $quiz): bool
    {
        return $quiz->isOwnedBy($user) && $quiz->status->isEditable();
    }

    /**
     * R4.6 — drafts only, and only the creator's own. Published and
     * unpublished quizzes can never be deleted, because other players'
     * history points at them.
     */
    public function delete(User $user, Quiz $quiz): bool
    {
        return $quiz->isOwnedBy($user) && $quiz->status->isDeletable();
    }

    /**
     * R4.1 — a draft or an unpublished quiz can be (re)published. Validity of
     * the content is checked separately; this is only about who and when.
     */
    public function publish(User $user, Quiz $quiz): bool
    {
        return $quiz->isOwnedBy($user) && ! $quiz->status->isPubliclyPlayable();
    }

    public function unpublish(User $user, Quiz $quiz): bool
    {
        return $quiz->isOwnedBy($user) && $quiz->status->isPubliclyPlayable();
    }

    /**
     * R4.5 — duplicating is how a published quiz gets changed, so it is
     * available on anything the creator owns that is no longer a draft.
     */
    public function duplicate(User $user, Quiz $quiz): bool
    {
        return $quiz->isOwnedBy($user) && ! $quiz->status->isEditable();
    }

    /**
     * R8.1 — who may upvote.
     *
     * Three conditions, and the third is the interesting one:
     *   - signed in (guests get a sign-in prompt instead, R7.8)
     *   - the quiz is published AND listed — an unlisted quiz is shared by
     *     link, and a link-only quiz has no public ranking to influence
     *   - not your own quiz
     *
     * Having played is deliberately NOT required. The spec says so, and it
     * follows from the point of the vote: it marks a quiz worth finding, which
     * someone can judge from its page.
     */
    public function vote(User $user, Quiz $quiz): bool
    {
        return $quiz->status->isPubliclyPlayable()
            && $quiz->is_listed
            && ! $quiz->isOwnedBy($user);
    }

    /**
     * R9.5 — analytics stay available after unpublishing, so this follows
     * ownership alone and ignores status.
     */
    public function viewStats(User $user, Quiz $quiz): bool
    {
        return $quiz->isOwnedBy($user);
    }
}
