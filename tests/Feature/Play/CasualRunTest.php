<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\RunStatus;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\User;
use App\Support\AnswerHash;
use App\Support\GuestToken;

/**
 * Phase 6 gate — R6.1, R6.3, R6.4, R6.5.
 */
function casualQuiz(int $questions = 5, int $timer = 5): Quiz
{
    return Quiz::factory()
        ->published()
        ->casual()
        ->withQuestions($questions)
        ->create(['seconds_per_question' => $timer]);
}

/** A complete, honest log of correct answers for every question. */
function honestLog(Quiz $quiz): array
{
    return $quiz->questions->map(fn ($question): array => [
        'question_id' => $question->id,
        'chosen_option_index' => $question->correct_option_index,
        'time_ms' => 1_200,
    ])->all();
}

// -------------------------------------------------------------------- R6.1

it('returns every question with options in original order', function () {
    $quiz = casualQuiz(5);

    $json = $this->actingAs(User::factory()->create())
        ->postJson(route('runs.store', $quiz))
        ->assertCreated()
        ->json();

    expect($json['questions'])->toHaveCount(5)
        ->and($json['run']['mode'])->toBe('casual')
        ->and($json['run']['salt'])->toBeString();

    $first = $quiz->questions->first();
    $payload = collect($json['questions'])->firstWhere('id', $first->id);

    // Original order, not shuffled: the client does the shuffling.
    expect($payload['options'])->toBe($first->options);
});

it('returns an answer hash per question that matches the documented format', function () {
    $quiz = casualQuiz(5);

    $json = $this->actingAs(User::factory()->create())
        ->postJson(route('runs.store', $quiz))
        ->json();

    $salt = $json['run']['salt'];

    foreach ($json['questions'] as $payload) {
        $question = $quiz->questions->firstWhere('id', $payload['id']);

        expect($payload['answer_hash'])->toBe(
            hash('sha256', $salt.':'.$question->id.':'.$question->correctOptionText())
        )->and($payload['answer_hash'])->toBe(
            AnswerHash::for($salt, $question->id, (string) $question->correctOptionText())
        );
    }
});

it('never sends a correct index, only a hash', function () {
    $json = $this->actingAs(User::factory()->create())
        ->postJson(route('runs.store', casualQuiz()))
        ->json();

    expect(json_encode($json))->not->toContain('correct_option_index');

    foreach ($json['questions'] as $payload) {
        expect($payload)->toHaveKeys(['id', 'prompt', 'options', 'answer_hash'])
            ->and($payload)->not->toHaveKey('correct_option_index');
    }
});

it('gives each run a different salt, so hashes are not reusable', function () {
    $quiz = casualQuiz();
    $player = User::factory()->create();

    $first = $this->actingAs($player)->postJson(route('runs.store', $quiz))->json('run.salt');
    $second = $this->actingAs($player)->postJson(route('runs.store', $quiz))->json('run.salt');

    expect($first)->not->toBe($second);
});

it('stores no option permutation for a casual run', function () {
    $this->actingAs(User::factory()->create())->postJson(route('runs.store', casualQuiz()));

    $run = QuizRun::sole();

    expect($run->option_orders)->toBeNull()
        ->and($run->salt)->not->toBeNull()
        ->and($run->current_issued_at)->toBeNull();
});

// -------------------------------------------------------------- R6.3 / R6.4

it('passes a run when every submitted answer is genuinely correct', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), ['answers' => honestLog($quiz)])
        ->assertOk()
        ->assertJsonPath('verdict', 'passed')
        ->assertJsonPath('reached', 5);

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Passed)
        ->and($run->reached)->toBe(5)
        ->and($run->answers)->toHaveCount(5)
        ->and($run->finished_at)->not->toBeNull();
});

it('rejects a tampered log that claims a wrong option was right', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $log = honestLog($quiz);
    // Flip the second answer to a wrong option, leaving the rest "correct".
    $log[1]['chosen_option_index'] = ($quiz->questions[1]->correct_option_index + 1) % 4;

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), ['answers' => $log])
        ->assertOk()
        ->assertJsonPath('verdict', 'failed')
        ->assertJsonPath('failure_reason', 'wrong_answer')
        // Sudden death: one correct answer before the lie.
        ->assertJsonPath('reached', 1);

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        // Entries after the failure are ignored, not recorded.
        ->and($run->fresh()->answers)->toHaveCount(2);
});

it('ignores extra correct answers smuggled in after a failure', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $log = honestLog($quiz);
    $log[0]['chosen_option_index'] = ($quiz->questions[0]->correct_option_index + 1) % 4;

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), ['answers' => $log])
        ->assertJsonPath('reached', 0)
        ->assertJsonPath('verdict', 'failed');

    expect($run->fresh()->answers)->toHaveCount(1);
});

it('trusts a reported timeout', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)->postJson(route('runs.submit', $run), [
        'answers' => [
            [
                'question_id' => $quiz->questions[0]->id,
                'chosen_option_index' => $quiz->questions[0]->correct_option_index,
                'time_ms' => 900,
            ],
            ['question_id' => $quiz->questions[1]->id, 'reason' => 'timeout', 'time_ms' => 5_000],
        ],
    ])
        ->assertJsonPath('verdict', 'failed')
        ->assertJsonPath('failure_reason', 'timeout')
        ->assertJsonPath('reached', 1);

    expect($run->fresh()->failure_reason)->toBe(FailureReason::Timeout);
});

it('trusts a reported focus loss', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)->postJson(route('runs.submit', $run), [
        'answers' => [
            ['question_id' => $quiz->questions[0]->id, 'reason' => 'focus_lost', 'time_ms' => 400],
        ],
    ])->assertJsonPath('failure_reason', 'focus_lost');

    expect($run->fresh()->answers()->sole()->chosen_option_index)->toBeNull();
});

it('trusts the reported timings as given', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $log = honestLog($quiz);
    $log[0]['time_ms'] = 37;

    $this->actingAs($player)->postJson(route('runs.submit', $run), ['answers' => $log]);

    expect($run->fresh()->answers()->orderBy('id')->first()->time_ms)->toBe(37);
});

// ------------------------------------------------------- structurally invalid

it('rejects a log referring to a question from another quiz', function () {
    $quiz = casualQuiz(5);
    $other = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::where('quiz_id', $quiz->id)->sole();

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), ['answers' => honestLog($other)])
        ->assertStatus(422);

    expect($run->fresh()->status)->toBe(RunStatus::InProgress);
});

it('rejects a log containing the same question twice', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $entry = [
        'question_id' => $quiz->questions[0]->id,
        'chosen_option_index' => $quiz->questions[0]->correct_option_index,
        'time_ms' => 500,
    ];

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), ['answers' => [$entry, $entry]])
        ->assertStatus(422);
});

it('rejects an incomplete log that reports no reason for stopping', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), [
            'answers' => array_slice(honestLog($quiz), 0, 3),
        ])
        ->assertStatus(422);

    expect($run->fresh()->status)->toBe(RunStatus::InProgress);
});

it('rejects an entry with both an index and a reason', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.submit', QuizRun::sole()), [
            'answers' => [[
                'question_id' => $quiz->questions[0]->id,
                'chosen_option_index' => 0,
                'reason' => 'timeout',
                'time_ms' => 100,
            ]],
        ])
        ->assertStatus(422);
});

it('does not let a client declare itself wrong', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.submit', QuizRun::sole()), [
            'answers' => [[
                'question_id' => $quiz->questions[0]->id,
                'reason' => 'wrong_answer',
                'time_ms' => 100,
            ]],
        ])
        ->assertStatus(422);
});

it('rejects an empty log', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.submit', QuizRun::sole()), ['answers' => []])
        ->assertStatus(422);
});

// ------------------------------------------------------------- wrong endpoint

it('refuses a strict answer on a casual run', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.answer', QuizRun::sole()), ['displayed_index' => 0])
        ->assertStatus(422);
});

it('refuses a casual submit on a strict run', function () {
    $quiz = Quiz::factory()->published()->strict()->withQuestions(5)->create();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.submit', QuizRun::sole()), [
            'answers' => [[
                'question_id' => $quiz->questions[0]->id,
                'chosen_option_index' => 0,
                'time_ms' => 100,
            ]],
        ])
        ->assertStatus(422);
});

it('refuses a second submit', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)->postJson(route('runs.submit', $run), ['answers' => honestLog($quiz)]);

    $this->actingAs($player)
        ->postJson(route('runs.submit', $run), ['answers' => honestLog($quiz)])
        ->assertStatus(409);
});

// ------------------------------------------------------------------- R5.12

it('refuses a submit from a different user', function () {
    $quiz = casualQuiz(5);

    $this->actingAs(User::factory()->create())->postJson(route('runs.store', $quiz));

    $this->actingAs(User::factory()->create())
        ->postJson(route('runs.submit', QuizRun::sole()), ['answers' => honestLog($quiz)])
        ->assertForbidden();
});

it('lets a guest play and submit a casual run', function () {
    $quiz = casualQuiz(5);
    $token = GuestToken::generate();

    $this->withCredentials()->withCookie(GuestToken::COOKIE, $token)
        ->postJson(route('runs.store', $quiz))
        ->assertCreated();

    $this->withCredentials()->withCookie(GuestToken::COOKIE, $token)
        ->postJson(route('runs.submit', QuizRun::sole()), ['answers' => honestLog($quiz)])
        ->assertJsonPath('verdict', 'passed')
        // Guests have no stats row, so the result screen hides that line.
        ->assertJsonPath('attempt', null);
});

// --------------------------------------------------------- shared bookkeeping

it('reuses the same end-of-run bookkeeping as strict mode', function () {
    $quiz = casualQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.submit', QuizRun::sole()), ['answers' => honestLog($quiz)])
        ->assertJsonPath('attempt', 1)
        ->assertJsonPath('best_reached', 5)
        ->assertJsonPath('passed', true);

    $quiz->refresh();

    expect($quiz->runs_count)->toBe(1)
        ->and($quiz->passes_count)->toBe(1)
        ->and($quiz->reached_sum)->toBe(5);
});

it('excludes the creator’s own casual run from the counters', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->casual()->withQuestions(5)->create();

    $this->actingAs($creator)->postJson(route('runs.store', $quiz));
    $this->actingAs($creator)
        ->postJson(route('runs.submit', QuizRun::sole()), ['answers' => honestLog($quiz)]);

    expect($quiz->fresh()->runs_count)->toBe(0);
});

// -------------------------------------------------------------------- R6.5

it('keeps the run marked casual so analytics can label it self-reported', function () {
    $quiz = casualQuiz(5);

    $this->actingAs(User::factory()->create())->postJson(route('runs.store', $quiz));

    expect(QuizRun::sole()->mode->isSelfReported())->toBeTrue();
});
