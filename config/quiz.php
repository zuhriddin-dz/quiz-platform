<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Strict mode grace period
    |--------------------------------------------------------------------------
    |
    | R5.3 — the server's clock decides whether an answer was in time:
    |
    |     received_at - current_issued_at > seconds_per_question + grace
    |
    | The grace absorbs NETWORK LATENCY only — the round trip from issuing the
    | question to the answer arriving. It is not extra thinking time, and it is
    | why the app must not run on cold-starting serverless compute: a cold
    | start would eat the whole budget and fail honest players.
    |
    */

    'grace_seconds' => (float) env('QUIZ_GRACE_SECONDS', 1.5),

    /*
    |--------------------------------------------------------------------------
    | Suggestions
    |--------------------------------------------------------------------------
    |
    | R8.4 — the score, with its weights in config as the spec requires:
    |
    |     score = w_author * (same creator ? 1 : 0)
    |            + w_stats  * (1 - |pass_rate_a - pass_rate_b|)
    |            + w_votes  * log10(1 + upvotes_count)
    |
    | `neutral_stats_term` is R8.5: when either quiz has fewer than 10 counted
    | runs there is no meaningful pass rate to compare, so the term takes this
    | value rather than being guessed or dropped.
    |
    */

    'suggestions' => [
        'weights' => [
            'author' => (float) env('QUIZ_SUGGEST_W_AUTHOR', 3),
            'stats' => (float) env('QUIZ_SUGGEST_W_STATS', 2),
            'votes' => (float) env('QUIZ_SUGGEST_W_VOTES', 1),
        ],

        // R8.5
        'neutral_stats_term' => 0.5,

        // R8.3 — up to 12 in one mixed list.
        'limit' => 12,

        // R8.6 — at most 3 from the same creator.
        'per_creator' => 3,

        /*
         * How many rows the single candidate query may return before scoring
         * happens in PHP. Not in the spec; see docs/phase-8.md for why the
         * score is not computed in SQL. Same-creator quizzes are ordered
         * first, so the author bonus can never be cut off by this limit.
         */
        'candidate_limit' => (int) env('QUIZ_SUGGEST_CANDIDATES', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | R10.1 / §11 — starting runs is limited per user, or per guest token AND
    | IP; voting is limited per user.
    |
    | Starting a run is the expensive one: it shuffles, writes a row and
    | abandons the previous run, so an unthrottled loop is both a write
    | amplifier and a way to farm a quiz's `runs_count`.
    |
    | A guest's key combines the token and the IP on purpose. The token alone
    | is a cookie anyone can clear; the IP alone would throttle a whole school
    | or office sharing one address.
    |
    */

    'rate_limits' => [
        'start_run_per_minute' => (int) env('QUIZ_LIMIT_START_RUN', 30),
        'vote_per_minute' => (int) env('QUIZ_LIMIT_VOTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sound
    |--------------------------------------------------------------------------
    |
    | R10.2 — three slots, pointing at placeholder files to be replaced with
    | the real effects later. Served from `public/sounds/` rather than
    | `resources/sounds/` so a missing file degrades to a silent 404 instead
    | of failing the Vite build; see docs/phase-10.md.
    |
    */

    'sounds' => [
        'game_loop' => '/sounds/game-loop.mp3',
        'fail' => '/sounds/fail.mp3',
        'win' => '/sounds/win.mp3',
    ],

];
