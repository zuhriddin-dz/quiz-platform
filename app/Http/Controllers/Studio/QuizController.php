<?php

declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use App\Actions\Quiz\CheckPublishable;
use App\Actions\Quiz\SyncQuestions;
use App\Enums\QuizMode;
use App\Http\Requests\Studio\StoreQuizRequest;
use App\Http\Requests\Studio\UpdateQuizRequest;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creator Studio — R3.1, R3.2, R3.3.
 *
 * Authorization is the policy's job throughout; nothing here re-checks
 * ownership by hand.
 *
 * Publish, unpublish, duplicate, delete and stats are Phase 4 and Phase 9.
 */
final class QuizController
{
    /**
     * R3.1 — my quizzes, with status and visibility.
     */
    public function index(Request $request, CheckPublishable $checkPublishable): Response
    {
        $quizzes = $request->user()
            ->quizzes()
            // Eager-loaded rather than counted: the publish check reads the
            // questions themselves, so counting too would be a second pass.
            ->with('questions')
            ->latest('updated_at')
            ->get()
            ->map(fn (Quiz $quiz): array => [
                'ulid' => $quiz->ulid,
                'id' => $quiz->id,
                'title' => $quiz->title,
                'status' => $quiz->status->value,
                'status_label' => $quiz->status->label(),
                'mode' => $quiz->mode->value,
                'mode_label' => $quiz->mode->label(),
                'is_listed' => $quiz->is_listed,
                'seconds_per_question' => $quiz->seconds_per_question,
                'questions_count' => $quiz->questions->count(),
                'runs_count' => $quiz->runs_count,
                'upvotes_count' => $quiz->upvotes_count,
                'published_at' => $quiz->published_at?->toDateString(),

                // Drives which actions the row offers, so the UI never links
                // somewhere the policy would refuse.
                'can' => [
                    'update' => $request->user()->can('update', $quiz),
                    'publish' => $request->user()->can('publish', $quiz),
                    'unpublish' => $request->user()->can('unpublish', $quiz),
                    'duplicate' => $request->user()->can('duplicate', $quiz),
                    'delete' => $request->user()->can('delete', $quiz),
                    // R9.5 — available whatever the status, so an unpublished
                    // quiz keeps its stats link.
                    'view_stats' => $request->user()->can('viewStats', $quiz),
                ],

                // R4.1 — why the publish button is disabled, if it is. Only
                // computed for quizzes that could be published at all.
                'publish_blockers' => $request->user()->can('publish', $quiz)
                    ? $checkPublishable->blockers($quiz)
                    : [],
            ]);

        return Inertia::render('studio/Index', [
            'quizzes' => $quizzes,
        ]);
    }

    /**
     * The new-draft form.
     */
    public function create(): Response
    {
        return Inertia::render('studio/Create', [
            'modes' => $this->modeOptions(),
        ]);
    }

    /**
     * R3.2 — create the draft, then go straight to the editor, because a new
     * draft has no questions yet and that is the next thing to do.
     */
    public function store(StoreQuizRequest $request): RedirectResponse
    {
        $quiz = $request->user()->quizzes()->create($request->validated());

        return redirect()
            ->route('studio.quizzes.edit', $quiz)
            ->with('status', 'Draft created.');
    }

    /**
     * R3.3 — the editor. Drafts only; the policy enforces that.
     */
    public function edit(Quiz $quiz): Response
    {
        $this->authorizeUpdate($quiz);

        $quiz->load('questions');

        return Inertia::render('studio/Edit', [
            'modes' => $this->modeOptions(),
            'quiz' => [
                'ulid' => $quiz->ulid,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'mode' => $quiz->mode->value,
                'seconds_per_question' => $quiz->seconds_per_question,
                'questions' => $quiz->questions->map(fn ($question): array => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    'options' => $question->options,
                    'correct_option_index' => $question->correct_option_index,
                ])->all(),
            ],
            // Phase 4 turns this into the publish button's enabled state.
            'publishable_question_range' => [5, 15],
        ]);
    }

    /**
     * R3.2 + R3.3 — one save for the quiz fields and the whole question list.
     */
    public function update(
        UpdateQuizRequest $request,
        Quiz $quiz,
        SyncQuestions $syncQuestions,
    ): RedirectResponse {
        $quiz->update($request->safe()->only([
            'title',
            'description',
            'mode',
            'seconds_per_question',
        ]));

        $syncQuestions->handle($quiz, $request->validated('questions'));

        return back()->with('status', 'Draft saved.');
    }

    /**
     * Route-model binding resolves by ULID, so a 404 and a 403 are told apart
     * by the policy rather than by guessing an id.
     */
    private function authorizeUpdate(Quiz $quiz): void
    {
        abort_unless(request()->user()->can('update', $quiz), 403);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function modeOptions(): array
    {
        return array_map(
            static fn (QuizMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ],
            QuizMode::cases(),
        );
    }
}
