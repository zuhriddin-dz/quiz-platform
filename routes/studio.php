<?php

declare(strict_types=1);

use App\Http\Controllers\Studio\QuizController;
use App\Http\Controllers\Studio\QuizLifecycleController;
use App\Http\Controllers\Studio\StatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Creator Studio
|--------------------------------------------------------------------------
|
| R3.5 — the whole group is signed-in only. Per-quiz authorization is the
| QuizPolicy's job, not a middleware's: "the creator, and only while it is a
| draft" is not something a route can express.
|
| Phase 3 added the authoring routes, Phase 4 the lifecycle ones and Phase 9
| the stats page. This group now matches the spec's route table (§10) in full.
|
| Note there are no question endpoints, by design: the spec's table has none.
| The editor is one form, and PUT /studio/quizzes/{quiz} syncs its questions.
|
*/

Route::middleware('auth')->prefix('studio')->name('studio.')->group(function (): void {
    Route::get('/', [QuizController::class, 'index'])->name('index');

    Route::get('quizzes/create', [QuizController::class, 'create'])->name('quizzes.create');
    Route::post('quizzes', [QuizController::class, 'store'])->name('quizzes.store');

    Route::get('quizzes/{quiz}/edit', [QuizController::class, 'edit'])->name('quizzes.edit');
    Route::put('quizzes/{quiz}', [QuizController::class, 'update'])->name('quizzes.update');

    // Phase 4 — lifecycle. POST, never GET: each one changes state.
    Route::post('quizzes/{quiz}/publish', [QuizLifecycleController::class, 'publish'])
        ->name('quizzes.publish');
    Route::post('quizzes/{quiz}/unpublish', [QuizLifecycleController::class, 'unpublish'])
        ->name('quizzes.unpublish');
    Route::post('quizzes/{quiz}/duplicate', [QuizLifecycleController::class, 'duplicate'])
        ->name('quizzes.duplicate');
    Route::delete('quizzes/{quiz}', [QuizLifecycleController::class, 'destroy'])
        ->name('quizzes.destroy');

    // Phase 9 — analytics. Available whatever the status (R9.5).
    Route::get('quizzes/{quiz}/stats', [StatsController::class, 'show'])
        ->name('quizzes.stats');
});
