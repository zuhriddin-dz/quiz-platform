<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuizMode;
use App\Enums\QuizStatus;
use App\Exceptions\ImmutableQuizException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property QuizMode $mode
 * @property int $seconds_per_question
 * @property QuizStatus $status
 * @property bool $is_listed
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int $upvotes_count
 * @property int $runs_count
 * @property int $passes_count
 * @property int $reached_sum
 */
class Quiz extends Model
{
    /** @use HasFactory<\Database\Factories\QuizFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'mode',
        'seconds_per_question',
        'status',
        'is_listed',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => QuizMode::class,
            'status' => QuizStatus::class,
            'is_listed' => 'boolean',
            'published_at' => 'datetime',
            'seconds_per_question' => 'integer',
            'upvotes_count' => 'integer',
            'runs_count' => 'integer',
            'passes_count' => 'integer',
            'reached_sum' => 'integer',
        ];
    }

    /**
     * R4.2 — the content fields that freeze on publish, permanently.
     *
     * Everything NOT listed here stays writable on a published quiz, and has
     * to: `status` and `is_listed` are how publish and unpublish work,
     * `published_at` is set by the first publish, and the four counters are
     * incremented by every finished run.
     *
     * @var list<string>
     */
    public const IMMUTABLE_ATTRIBUTES = [
        'title',
        'description',
        'mode',
        'seconds_per_question',
        'user_id',
        'ulid',
    ];

    protected static function booted(): void
    {
        // R2.2 — every quiz gets its public identifier at creation, so no code
        // path can produce a quiz without one.
        static::creating(function (self $quiz): void {
            $quiz->ulid ??= (string) Str::ulid();
        });

        /*
         * R4.2 — the second of the two layers. QuizPolicy::update already
         * refuses these at the request layer; this one catches everything
         * else, including our own future code, a tinker session and a seeder.
         *
         * It checks the ORIGINAL status, not the new one, so the publish
         * transition itself is allowed while the content it publishes is not.
         */
        static::updating(function (self $quiz): void {
            if ($quiz->statusBeforeSave()->isEditable()) {
                return;
            }

            $changed = array_keys(array_intersect_key(
                $quiz->getDirty(),
                array_flip(self::IMMUTABLE_ATTRIBUTES),
            ));

            if ($changed !== []) {
                throw ImmutableQuizException::forAttributes($changed);
            }
        });

        /*
         * R4.6 — only drafts can be deleted. The policy says so too; this
         * makes it true of every code path, so no cascade can be triggered by
         * accident on a quiz other players have in their history.
         */
        static::deleting(function (self $quiz): void {
            if (! $quiz->status->isDeletable()) {
                throw ImmutableQuizException::forDeletion();
            }
        });
    }

    /**
     * The status this row had before the current save. `getOriginal()` applies
     * casts, so it may hand back an enum or the raw string depending on how
     * the model was hydrated — normalise both.
     */
    public function statusBeforeSave(): QuizStatus
    {
        $original = $this->getOriginal('status');

        if ($original instanceof QuizStatus) {
            return $original;
        }

        return $original === null
            ? QuizStatus::Draft
            : QuizStatus::from((string) $original);
    }

    /**
     * R2.2 — `route('quiz.show', $quiz)` must produce the ULID, never the id.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    // ---------------------------------------------------------------- relations

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Authoring order. Play order is shuffled per run and stored there.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(QuizVote::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(QuizRun::class);
    }

    public function userStats(): HasMany
    {
        return $this->hasMany(QuizUserStat::class);
    }

    // --------------------------------------------------------------- derived

    /**
     * Below 10 counted runs the quiz page shows "Not enough plays yet" instead
     * of a pass rate (R7.4), and suggestions use a neutral 0.5 (R8.5).
     */
    public const MINIMUM_COUNTED_RUNS = 10;

    public function hasEnoughRuns(): bool
    {
        return $this->runs_count >= self::MINIMUM_COUNTED_RUNS;
    }

    /**
     * Null until there are enough counted runs to mean anything.
     */
    public function passRate(): ?float
    {
        if (! $this->hasEnoughRuns()) {
            return null;
        }

        return $this->passes_count / $this->runs_count;
    }

    /**
     * avg_reached = reached_sum / runs_count (spec §4).
     */
    public function averageReached(): ?float
    {
        if (! $this->hasEnoughRuns()) {
            return null;
        }

        return $this->reached_sum / $this->runs_count;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $user->id === $this->user_id;
    }
}
