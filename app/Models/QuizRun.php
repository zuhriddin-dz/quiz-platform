<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureReason;
use App\Enums\QuizMode;
use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One attempt at one quiz.
 *
 * @property string $id
 * @property int $quiz_id
 * @property int|null $user_id
 * @property string|null $guest_token
 * @property QuizMode $mode
 * @property array<int, int> $question_order
 * @property array<int, array<int, int>>|null $option_orders
 * @property string|null $salt
 * @property int $current_index
 * @property \Illuminate\Support\Carbon|null $current_issued_at
 * @property RunStatus $status
 * @property FailureReason|null $failure_reason
 * @property int $reached
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class QuizRun extends Model
{
    /** @use HasFactory<\Database\Factories\QuizRunFactory> */
    use HasFactory;

    // R2.2 — the primary key is a ULID because it appears in run URLs.
    use HasUlids;

    /** `started_at` / `finished_at` are this row's clock (spec §4). */
    public $timestamps = false;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'guest_token',
        'mode',
        'question_order',
        'option_orders',
        'salt',
        'current_index',
        'current_issued_at',
        'status',
        'failure_reason',
        'reached',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => QuizMode::class,
            'status' => RunStatus::class,
            'failure_reason' => FailureReason::class,
            'question_order' => 'array',
            'option_orders' => 'array',
            'current_index' => 'integer',
            'reached' => 'integer',
            // Millisecond precision: the timeout decision subtracts this from
            // the request's arrival time (R5.3).
            'current_issued_at' => 'datetime:Y-m-d H:i:s.v',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * R2.3 — exactly one of user_id / guest_token.
         *
         * Enforced here rather than as a CHECK constraint: Postgres and SQLite
         * spell check constraints differently, and R0.4 bars driver-specific
         * SQL. A guard on save covers every code path that goes through
         * Eloquent, which is all of them.
         */
        static::saving(function (self $run): void {
            $hasUser = $run->user_id !== null;
            $hasGuest = $run->guest_token !== null && $run->guest_token !== '';

            if ($hasUser === $hasGuest) {
                throw new LogicException(
                    'A quiz run must belong to exactly one of a user or a guest token.'
                );
            }
        });
    }

    // ---------------------------------------------------------------- relations

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RunAnswer::class);
    }

    // ----------------------------------------------------------------- state

    public function isGuestRun(): bool
    {
        return $this->user_id === null;
    }

    /**
     * R5.12 — run endpoints accept requests only from the run's owner.
     * A guest token is compared in constant time: it is a bearer secret.
     */
    public function isOwnedBy(?User $user, ?string $guestToken): bool
    {
        if ($this->user_id !== null) {
            return $user !== null && $user->id === $this->user_id;
        }

        return $guestToken !== null
            && $this->guest_token !== null
            && hash_equals($this->guest_token, $guestToken);
    }

    public function questionCount(): int
    {
        return count($this->question_order);
    }

    public function isOnLastQuestion(): bool
    {
        return $this->current_index >= $this->questionCount() - 1;
    }

    /**
     * The id of the question currently showing, per this run's own shuffle.
     */
    public function currentQuestionId(): ?int
    {
        return $this->question_order[$this->current_index] ?? null;
    }
}
