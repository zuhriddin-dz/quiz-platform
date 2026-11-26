<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FailureReason;
use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * R5.2 — the answer body is one of two shapes, never both:
 *
 *     { "displayed_index": 0-3 }
 *     { "reason": "timeout" | "focus_lost" }
 *
 * `wrong_answer` is NOT an accepted reason. A client does not get to declare
 * itself wrong; that is the server's conclusion from the index it was sent.
 *
 * Ownership (R5.12) is checked in the controller, not here, so a 403 is not
 * confused with a validation error.
 */
final class AnswerRunRequest extends FormRequest
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
            'displayed_index' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(Question::OPTION_COUNT - 1),
            ],
            'reason' => [
                'nullable',
                'string',
                'in:'.FailureReason::Timeout->value.','.FailureReason::FocusLost->value,
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasIndex = $this->input('displayed_index') !== null;
                $hasReason = $this->input('reason') !== null;

                if ($hasIndex === $hasReason) {
                    $validator->errors()->add(
                        'displayed_index',
                        'Send exactly one of displayed_index or reason.',
                    );
                }
            },
        ];
    }

    public function reportedReason(): ?FailureReason
    {
        $reason = $this->input('reason');

        return is_string($reason) ? FailureReason::from($reason) : null;
    }

    public function displayedIndex(): ?int
    {
        $index = $this->input('displayed_index');

        return $index === null ? null : (int) $index;
    }
}
