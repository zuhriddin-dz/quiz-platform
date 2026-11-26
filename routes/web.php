<?php

declare(strict_types=1);

use App\Http\Controllers\ExploreController;
use App\Http\Controllers\PlaysController;
use App\Http\Controllers\QuizPageController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
*/

/*
 * R7.1 — Explore is the home page for everyone, guests included. The route
 * keeps the name `home` because that is what the spec calls it ("Home page
 * for everyone"), and because sign-in and the lifecycle redirects point here.
 */
Route::get('/', [ExploreController::class, 'index'])->name('home');

/*
 * Play (Phases 5 and 6). The quiz page is Inertia; the run endpoints return
 * JSON, because a run is a sequence of small exchanges inside that page.
 *
 * Guests may play published quizzes, so none of this is behind `auth` —
 * ownership of a run is the session or the guest cookie (R5.12).
 */
Route::get('q/{quiz}', [QuizPageController::class, 'show'])->name('quiz.show');

// R8.1 — toggle an upvote. Signed-in only; the policy handles the rest.
// R10.1 — throttled per user.
Route::post('q/{quiz}/vote', VoteController::class)
    ->middleware(['auth', 'throttle:vote'])
    ->name('quiz.vote');

/*
 * R10.1 — only STARTING a run is throttled. Answering is not: a strict run
 * sends one request per question under a 2-10 second timer, so a limit tight
 * enough to matter would fail honest players on a fast quiz, and the run's own
 * sudden-death rules already bound how many answers a run can produce.
 */
Route::post('q/{quiz}/runs', [RunController::class, 'store'])
    ->middleware('throttle:start-run')
    ->name('runs.store');

Route::post('runs/{run}/answers', [RunController::class, 'answer'])->name('runs.answer');
Route::post('runs/{run}/submit', [RunController::class, 'submit'])->name('runs.submit');

// R7.6 — My plays. Guests have no history to show, so this one needs auth.
Route::get('plays', [PlaysController::class, 'index'])
    ->middleware('auth')
    ->name('plays');

require __DIR__.'/auth.php';
require __DIR__.'/studio.php';
