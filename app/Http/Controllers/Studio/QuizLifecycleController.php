<?php

declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use App\Actions\Quiz\DuplicateQuiz;
use App\Actions\Quiz\PublishQuiz;
use App\Actions\Quiz\UnpublishQuiz;
use App\Http\Requests\Studio\PublishQuizRequest;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * R4.1 to R4.6 — the lifecycle transitions, kept apart from authoring so each
 * controller has one job.
 *
 * Every method authorizes through QuizPolicy and then delegates; the rules
 * themselves live in the actions and the model guards.
 */
final class QuizLifecycleController
{
    /**
     * R4.1 + R4.3 — publish, or republish an unpublished quiz.
     *
     * Authorization and content validation both happen in the request, so a
     * failure comes back as validation errors on the dialog rather than a 403.
     */
    public function publish(PublishQuizRequest $request, Quiz $quiz, PublishQuiz $publishQuiz): RedirectResponse
    {
        $publishQuiz->handle($quiz, $request->boolean('is_listed'));

        return redirect()
            ->route('studio.index')
            ->with('status', $quiz->is_listed
                ? 'Published and listed on Explore.'
                : 'Published. Share it with its link.');
    }

    /**
     * R4.4 — unpublish. Other players' in-progress runs close on their next
     * request, not here.
     */
    public function unpublish(Request $request, Quiz $quiz, UnpublishQuiz $unpublishQuiz): RedirectResponse
    {
        abort_unless($request->user()->can('unpublish', $quiz), 403);

        $unpublishQuiz->handle($quiz);

        return redirect()
            ->route('studio.index')
            ->with('status', 'Unpublished. Your analytics are still here.');
    }

    /**
     * R4.5 — duplicate as draft, then open the editor, since changing
     * something is the only reason to duplicate.
     */
    public function duplicate(Request $request, Quiz $quiz, DuplicateQuiz $duplicateQuiz): RedirectResponse
    {
        abort_unless($request->user()->can('duplicate', $quiz), 403);

        $copy = $duplicateQuiz->handle($quiz);

        return redirect()
            ->route('studio.quizzes.edit', $copy)
            ->with('status', 'Duplicated as a draft. The copy has no plays, votes or analytics.');
    }

    /**
     * R4.6 — hard delete, drafts only. The policy refuses anything else, and
     * the model guard refuses it again.
     */
    public function destroy(Request $request, Quiz $quiz): RedirectResponse
    {
        abort_unless($request->user()->can('delete', $quiz), 403);

        $quiz->delete();

        return redirect()
            ->route('studio.index')
            ->with('status', 'Draft deleted.');
    }
}
