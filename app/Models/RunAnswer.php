<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answered question. Append-only: an event, never edited.
 *
 * @property int $id
 * @property string $quiz_run_id
 * @property int $question_id
 * @property int|null $chosen_option_index
 * @property bool $is_correct
 * @property FailureReason|null $failure_reason
 * @property int $time_ms
 */
class RunAnswer extends Model
{
    /** @use HasFactory<\Database\Factories\RunAnswerFactory> */
    use HasFactory;

    /** Events are never updated, so there is nothing for updated_at to say. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'quiz_run_id',
        'question_id',
        'chosen_option_index',
        'is_correct',
        'failure_reason',
        'time_ms',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'failure_reason' => FailureReason::class,
            'chosen_option_index' => 'integer',
            'time_ms' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(QuizRun::class, 'quiz_run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
