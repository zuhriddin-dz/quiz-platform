<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FailureReason;
use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * R6.3 — the casual answer log:
 *
 *     { "answers": [
 *         { "question_id": 12, "chosen_option_index": 2, "time_ms": 1840 },
 *         { "question_id": 15, "reason": "timeout", "time_ms": 5000 }
 *     ] }
 *
 * This checks SHAPE only. Whether the log makes sense — questions that belong
 * to the run, no repeats, a reason when it stops early — is SubmitCasualRun's
 * job, because those checks need the run.
 *
 * As in strict mode, `wrong_answer` is not an accepted reason: a client does
 * not get to declare itself wrong, that is the server's conclusion from the
 * index it was sent.
 */
final class SubmitCasualRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:15'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.chosen_option_index' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(Question::OPTION_COUNT - 1),
            ],
            'answers.*.reason' => [
                'nullable',
                'string',
                'in:'.FailureReason::Timeout->value.','.FailureReason::FocusLost->value,
            ],
            // Trusted, but bounded: this is a duration, not an opinion.
            'answers.*.time_ms' => ['required', 'integer', 'min:0', 'max:600000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('answers', []) as $index => $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $hasIndex = ($entry['chosen_option_index'] ?? null) !== null;
                    $hasReason = ($entry['reason'] ?? null) !== null;

                    if ($hasIndex === $hasReason) {
                        $validator->errors()->add(
                            "answers.{$index}.chosen_option_index",
                            'Each entry needs exactly one of chosen_option_index or reason.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function answerLog(): array
    {
        /** @var array<int, array<string, mixed>> $answers */
        $answers = $this->validated('answers');

        return array_values($answers);
    }
}
