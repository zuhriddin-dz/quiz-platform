<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Quiz\ToggleVote;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * R8.1 — POST /q/{quiz}/vote
 *
 * Inertia, not JSON: a vote changes a number the page already displays, so
 * letting the page re-render is simpler than reconciling a count client-side,
 * and it keeps `has_voted` honest without a second source of truth.
 *
 * Rate limiting is Phase 10 (R11).
 */
final class VoteController
{
    public function __invoke(Request $request, Quiz $quiz, ToggleVote $toggleVote): RedirectResponse
    {
        // R8.1 — signed in, published and listed, not your own.
        abort_unless($request->user()->can('vote', $quiz), 403);

        $toggleVote->handle($request->user(), $quiz);

        // `back()` re-renders the quiz page, which recomputes the count, the
        // voted state and the suggestions in one go.
        return back();
    }
}
