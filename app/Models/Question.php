<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImmutableQuizException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $quiz_id
 * @property int $position
 * @property string $prompt
 * @property array<int, string> $options
 * @property int|null $correct_option_index
 */
class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory;

    /** Every question has exactly four options once published (R4.1). */
    public const OPTION_COUNT = 4;

    protected $fillable = [
        'quiz_id',
        'position',
        'prompt',
        'options',
        'correct_option_index',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'position' => 'integer',
            'correct_option_index' => 'integer',
        ];
    }

    /**
     * R4.2 — questions are content, so they freeze with their quiz.
     *
     * Covers create, update and delete: once a quiz leaves draft, its question
     * set is fixed. `SyncQuestions` only ever runs on drafts (the policy sees
     * to that), so this never fires in normal use — it is here to make the
     * guarantee unconditional.
     */
    protected static function booted(): void
    {
        $guard = function (self $question): void {
            $quiz = $question->quiz;

            // A question being created alongside a brand-new quiz, or one
            // whose quiz row is already gone (a cascading delete), has nothing
            // to protect.
            if ($quiz === null) {
                return;
            }

            if (! $quiz->status->isEditable()) {
                throw ImmutableQuizException::forQuestions();
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RunAnswer::class);
    }

    /**
     * The correct option's TEXT, which is what the casual-mode answer hash is
     * built from (R6.1) — not its index, so the client can verify a choice it
     * shuffled itself without knowing the original order.
     */
    public function correctOptionText(): ?string
    {
        if ($this->correct_option_index === null) {
            return null;
        }

        return $this->options[$this->correct_option_index] ?? null;
    }

    /**
     * Publish-readiness of this one question (R4.1). The full check, including
     * the 5-15 question count, belongs to Phase 4.
     */
    public function isComplete(): bool
    {
        if (trim($this->prompt) === '' || $this->correct_option_index === null) {
            return false;
        }

        $options = array_map(static fn (mixed $o): string => trim((string) $o), $this->options);

        if (count($options) !== self::OPTION_COUNT) {
            return false;
        }

        if (in_array('', $options, strict: true)) {
            return false;
        }

        // Distinct after trimming and ignoring case.
        $normalised = array_map(static fn (string $o): string => mb_strtolower($o), $options);

        return count(array_unique($normalised)) === self::OPTION_COUNT;
    }
}
