<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed-in player's standing on one quiz. Upserted inside the end-of-run
 * transaction (R5.11). Guests have no row here.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 * @property int $attempts
 * @property int $best_reached
 * @property bool $passed
 * @property \Illuminate\Support\Carbon|null $first_passed_at
 * @property \Illuminate\Support\Carbon $last_played_at
 */
class QuizUserStat extends Model
{
    /** @use HasFactory<\Database\Factories\QuizUserStatFactory> */
    use HasFactory;

    /** `last_played_at` is this row's updated_at (see the migration). */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'quiz_id',
        'attempts',
        'best_reached',
        'passed',
        'first_passed_at',
        'last_played_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'best_reached' => 'integer',
            'passed' => 'boolean',
            'first_passed_at' => 'datetime',
            'last_played_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
