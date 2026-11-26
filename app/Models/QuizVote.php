<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One upvote. The unique (user_id, quiz_id) pair in the schema is what makes
 * voting a toggle; this model is deliberately thin.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 */
class QuizVote extends Model
{
    /** @use HasFactory<\Database\Factories\QuizVoteFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quiz_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
