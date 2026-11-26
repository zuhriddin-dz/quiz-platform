<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Run\AnswerQuestion;
use App\Actions\Run\StartCasualRun;
use App\Actions\Run\StartRun;
use App\Actions\Run\SubmitCasualRun;
use App\Enums\QuizMode;
use App\Http\Requests\AnswerRunRequest;
use App\Http\Requests\SubmitCasualRunRequest;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Support\GuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The play loop. These endpoints return JSON, not Inertia pages: the quiz page
 * is an Inertia page, but a run is a sequence of small exchanges inside it, and
 * re-rendering a page per question would make the timing worse and the code
 * stranger.
 *
 * They still live in the web middleware group, because a run's identity is the
 * session (for users) or the guest cookie — not a token.
 *
 * Rate limits on starting runs are Phase 10 (R10.1).
 */
final class RunController
{
    /**
     * R5.1 — POST /q/{quiz}/runs
     */
    public function store(
        Request $request,
        Quiz $quiz,
        StartRun $startRun,
        StartCasualRun $startCasualRun,
    ): JsonResponse {
        // Published -> anyone, guests included. Draft or unpublished -> the
        // creator only, which is how test-playing a draft works.
        abort_unless($request->user()?->can('view', $quiz) ?? $quiz->status->isPubliclyPlayable(), 403);

        abort_unless($startRun->canStart($quiz), 422, 'This quiz has no questions yet.');

        $user = $request->user();
        $guestToken = GuestToken::fromRequest($request);

        /*
         * One route, two payloads. The mode is the quiz's, never the client's:
         * a player cannot ask for the casual payload on a strict quiz and
         * walk away with the answers.
         */
        $payload = $quiz->mode === QuizMode::Casual
            ? $startCasualRun->handle($quiz, $user, $guestToken)
            : $startRun->handle($quiz, $user, $guestToken);

        return response()->json($payload, 201);
    }

    /**
     * R5.2 — POST /runs/{run}/answers
     */
    public function answer(
        AnswerRunRequest $request,
        QuizRun $run,
        AnswerQuestion $answerQuestion,
    ): JsonResponse {
        // R5.12 — the run's owner, and nobody else.
        abort_unless(
            $run->isOwnedBy($request->user(), GuestToken::fromRequest($request)),
            403,
        );

        abort_if($run->mode !== QuizMode::Strict, 422, 'This run is not a strict run.');

        // A finished run is gone, not an error worth a 500.
        abort_if($run->status->isFinished(), 409, 'This run has already finished.');

        /*
         * R5.3 — the moment the request ARRIVED, not the moment we got round
         * to it. REQUEST_TIME_FLOAT is set by PHP at the start of the request,
         * so our own queueing and boot time are not charged to the player.
         */
        $receivedAt = (float) ($request->server('REQUEST_TIME_FLOAT') ?? microtime(true));

        $payload = $answerQuestion->handle(
            $run,
            $request->displayedIndex(),
            $request->reportedReason(),
            $receivedAt,
        );

        return response()->json($payload);
    }

    /**
     * R6.3 — POST /runs/{run}/submit
     *
     * The whole casual run arrives at once. There is no partial submit: a
     * casual run is one exchange out and one back.
     */
    public function submit(
        SubmitCasualRunRequest $request,
        QuizRun $run,
        SubmitCasualRun $submitCasualRun,
    ): JsonResponse {
        // R5.12 applies to every run endpoint, casual included.
        abort_unless(
            $run->isOwnedBy($request->user(), GuestToken::fromRequest($request)),
            403,
        );

        abort_if($run->mode !== QuizMode::Casual, 422, 'This run is not a casual run.');

        abort_if($run->status->isFinished(), 409, 'This run has already finished.');

        return response()->json(
            $submitCasualRun->handle($run, $request->answerLog())
        );
    }
}
