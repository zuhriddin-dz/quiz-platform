<?php

declare(strict_types=1);

use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;

/**
 * Phase 3 gate — R3.3 and R3.4 (the incomplete-draft half).
 *
 * The editor PUTs the whole question list, so these all go through
 * studio.quizzes.update.
 */
function saveDraft(User $user, Quiz $quiz, array $questions): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->put(route('studio.quizzes.update', $quiz), [
        'title' => $quiz->title,
        'description' => $quiz->description,
        'mode' => $quiz->mode->value,
        'seconds_per_question' => $quiz->seconds_per_question,
        'questions' => $questions,
    ]);
}

it('creates questions from rows without an id', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => 'First?', 'options' => ['a', 'b', 'c', 'd'], 'correct_option_index' => 2],
        ['prompt' => 'Second?', 'options' => ['w', 'x', 'y', 'z'], 'correct_option_index' => 0],
    ])->assertSessionHasNoErrors();

    $questions = $quiz->fresh()->questions;

    expect($questions)->toHaveCount(2)
        ->and($questions[0]->prompt)->toBe('First?')
        ->and($questions[0]->position)->toBe(1)
        ->and($questions[0]->correct_option_index)->toBe(2)
        ->and($questions[1]->position)->toBe(2);
});

it('updates a question that already has an id', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();
    $question = Question::factory()->for($quiz)->withOptions(['a', 'b', 'c', 'd'], 0)->create();

    saveDraft($user, $quiz, [
        ['id' => $question->id, 'prompt' => 'Changed?', 'options' => ['e', 'f', 'g', 'h'], 'correct_option_index' => 3],
    ]);

    expect(Question::count())->toBe(1)
        ->and($question->fresh()->prompt)->toBe('Changed?')
        ->and($question->fresh()->options)->toBe(['e', 'f', 'g', 'h'])
        ->and($question->fresh()->correct_option_index)->toBe(3);
});

it('deletes questions left out of the payload', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->withQuestions(3)->create();
    $keep = $quiz->questions->first();

    saveDraft($user, $quiz, [
        ['id' => $keep->id, 'prompt' => $keep->prompt, 'options' => $keep->options, 'correct_option_index' => 0],
    ]);

    expect(Question::count())->toBe(1)
        ->and(Question::sole()->id)->toBe($keep->id);
});

it('rewrites positions from the payload order', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->withQuestions(3)->create();
    [$first, $second, $third] = $quiz->questions->all();

    // Same three questions, reversed.
    saveDraft($user, $quiz, array_map(
        fn (Question $q): array => [
            'id' => $q->id,
            'prompt' => $q->prompt,
            'options' => $q->options,
            'correct_option_index' => $q->correct_option_index,
        ],
        [$third, $second, $first],
    ));

    expect($third->fresh()->position)->toBe(1)
        ->and($second->fresh()->position)->toBe(2)
        ->and($first->fresh()->position)->toBe(3);
});

it('ignores an id belonging to another quiz instead of reaching into it', function () {
    $me = User::factory()->create();
    $mine = Quiz::factory()->for($me)->create();

    $theirQuestion = Question::factory()
        ->for(Quiz::factory()->for(User::factory())->create())
        ->create(['prompt' => 'Untouched']);

    saveDraft($me, $mine, [
        ['id' => $theirQuestion->id, 'prompt' => 'Hijacked', 'options' => ['a', 'b', 'c', 'd'], 'correct_option_index' => 0],
    ]);

    expect($theirQuestion->fresh()->prompt)->toBe('Untouched')
        // Treated as a new row on my own quiz.
        ->and($mine->fresh()->questions)->toHaveCount(1)
        ->and($mine->fresh()->questions->first()->prompt)->toBe('Hijacked');
});

// -------------------------------------------------------------------- R3.4

it('saves an incomplete question', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => '', 'options' => ['', '', '', ''], 'correct_option_index' => null],
    ])->assertSessionHasNoErrors();

    $question = $quiz->fresh()->questions->first();

    expect($question->prompt)->toBe('')
        ->and($question->options)->toBe(['', '', '', ''])
        ->and($question->correct_option_index)->toBeNull()
        ->and($question->isComplete())->toBeFalse();
});

it('pads a short option list to four slots', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => 'Two so far?', 'options' => ['a', 'b'], 'correct_option_index' => null],
    ])->assertSessionHasNoErrors();

    expect($quiz->fresh()->questions->first()->options)->toBe(['a', 'b', '', '']);
});

it('saves duplicate options on a draft, because distinctness is a publish rule', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => 'Same?', 'options' => ['Apple', ' apple ', 'Pear', 'Plum'], 'correct_option_index' => 0],
    ])->assertSessionHasNoErrors();

    expect($quiz->fresh()->questions->first()->isComplete())->toBeFalse();
});

// ------------------------------------------------------------- length limits

it('rejects a prompt over 200 characters', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => str_repeat('a', 201), 'options' => ['a', 'b', 'c', 'd'], 'correct_option_index' => 0],
    ])->assertSessionHasErrors('questions.0.prompt');
});

it('rejects an option over 80 characters', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => 'Long option?', 'options' => [str_repeat('a', 81), 'b', 'c', 'd'], 'correct_option_index' => 0],
    ])->assertSessionHasErrors('questions.0.options.0');
});

it('rejects more than fifteen questions', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    $rows = array_fill(0, 16, [
        'prompt' => 'Filler?', 'options' => ['a', 'b', 'c', 'd'], 'correct_option_index' => 0,
    ]);

    saveDraft($user, $quiz, $rows)->assertSessionHasErrors('questions');
});

it('rejects a correct index outside 0 to 3', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    saveDraft($user, $quiz, [
        ['prompt' => 'Out of range?', 'options' => ['a', 'b', 'c', 'd'], 'correct_option_index' => 4],
    ])->assertSessionHasErrors('questions.0.correct_option_index');
});
