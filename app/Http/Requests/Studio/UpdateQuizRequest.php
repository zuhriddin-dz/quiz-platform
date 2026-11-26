<?php

declare(strict_types=1);

namespace App\Http\Requests\Studio;

use App\Enums\QuizMode;
use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving the editor. One PUT carries the quiz fields AND the whole question
 * list, because the spec's route table (§10) has no question endpoints — the
 * editor is a single form, and saving it syncs the questions.
 *
 * R3.4 — LENGTH LIMITS ONLY on questions. Every question field is nullable
 * here on purpose:
 *   - a prompt may be blank while the creator is still thinking
 *   - options may be half-filled
 *   - no correct answer need be chosen yet
 *
 * "5-15 questions, four distinct non-empty options, a correct index" is
 * publish validation (R4.1), enforced in Phase 4. A draft that cannot publish
 * must still save, or the editor is unusable.
 */
final class UpdateQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quiz'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'mode' => ['required', Rule::enum(QuizMode::class)],
            'seconds_per_question' => ['required', 'integer', 'min:2', 'max:10'],

            // A guard rail, not publish validation: 15 is the published
            // maximum, so there is no reason to let a draft grow past it.
            'questions' => ['present', 'array', 'max:15'],

            // Present means "this row already exists"; absent means "create".
            // Ownership of the id is checked in the sync action, not here.
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.prompt' => ['nullable', 'string', 'max:200'],

            'questions.*.options' => ['present', 'array', 'max:'.Question::OPTION_COUNT],
            'questions.*.options.*' => ['nullable', 'string', 'max:80'],

            'questions.*.correct_option_index' => [
                'nullable', 'integer', 'min:0', 'max:'.(Question::OPTION_COUNT - 1),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'questions.*.prompt' => 'question',
            'questions.*.options.*' => 'option',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'questions.max' => 'A quiz cannot have more than 15 questions.',
            'questions.*.options.max' => 'A question has exactly 4 options.',
        ];
    }
}
