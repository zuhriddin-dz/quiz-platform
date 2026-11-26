<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A signed-in player. Created from Google only (R1.1) — there is no password
 * column, no email verification and no nickname.
 *
 * @property int $id
 * @property string $google_id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_url
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    /**
     * R1.3: name and avatar are refreshed from Google on every login, so both
     * must stay mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'google_id',
        'name',
        'email',
        'avatar_url',
    ];

    /**
     * R1.4: the email is private and is never shown to anyone. Hiding it here
     * is the backstop; HandleInertiaRequests allowlists the props that
     * actually reach the frontend.
     *
     * @var list<string>
     */
    protected $hidden = [
        'email',
        'google_id',
        'remember_token',
    ];

    // ---------------------------------------------------------------- relations

    /**
     * Quizzes this user created. "Creator" is not a flag on the user — it is
     * ownership of a row, which is why there is no role column anywhere.
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(QuizVote::class);
    }

    /**
     * Runs played while signed in. Runs played as a guest are keyed by cookie
     * token instead and are never migrated here (spec §3).
     */
    public function runs(): HasMany
    {
        return $this->hasMany(QuizRun::class);
    }

    /**
     * Per-quiz standing: attempts, best result, passed. Drives My plays and
     * the sidebar's recent list.
     */
    public function quizStats(): HasMany
    {
        return $this->hasMany(QuizUserStat::class);
    }
}
