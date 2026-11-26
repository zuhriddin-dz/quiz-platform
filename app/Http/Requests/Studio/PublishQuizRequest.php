<?php

declare(strict_types=1);

namespace App\Http\Requests\Studio;

use App\Actions\Quiz\CheckPublishable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * R4.1 + R4.3 — the publish dialog.
 *
 * `is_listed` is REQUIRED, not defaulted: the spec says the choice is made
 * again on every publish, including republishing an unpublished quiz. A
 * default would silently relist a quiz the creator had deliberately unlisted.
 */
final class PublishQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publish', $this->route('quiz'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_listed' => ['required', 'boolean'],
        ];
    }

    /**
     * Content validation runs after the field rules, so a malformed dialog
     * does not hide the real blockers.
     */
    public function after(CheckPublishable $checkPublishable): array
    {
        return [
            function (Validator $validator) use ($checkPublishable): void {
                foreach ($checkPublishable->blockers($this->route('quiz')) as $blocker) {
                    $validator->errors()->add('publish', $blocker);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_listed.required' => 'Choose whether the quiz is listed on Explore or shared by link only.',
        ];
    }
}
