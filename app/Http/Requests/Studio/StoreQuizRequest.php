<?php

declare(strict_types=1);

namespace App\Http\Requests\Studio;

use App\Enums\QuizMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a new draft. Quiz fields only — a new draft starts with no
 * questions, which is the point of R3.4.
 */
final class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Quiz::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * R3.4 says drafts validate "length limits only". Read literally
             * that would allow a draft with no mode and no timer — but both
             * columns are NOT NULL, and R3.2 lists them as draft fields. So
             * the quiz's own four fields are validated here with their limits
             * and ranges; it is the QUESTIONS that may be incomplete.
             *
             * `title` is required because this is the only place it is ever
             * checked: publish validation (R4.1) does not re-check it.
             */
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'mode' => ['required', Rule::enum(QuizMode::class)],
            'seconds_per_question' => ['required', 'integer', 'min:2', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'seconds_per_question.min' => 'The timer must be at least 2 seconds.',
            'seconds_per_question.max' => 'The timer cannot be longer than 10 seconds.',
        ];
    }
}
