<?php

declare(strict_types=1);

namespace App\Support;

/**
 * R6.1 — the casual-mode answer hash, and the only place its format lives.
 *
 *     sha256(salt + ":" + question_id + ":" + correct_option_text)
 *
 * One class because the format is a contract between PHP and TypeScript: the
 * server hashes the stored correct option, the browser hashes whatever the
 * player clicked, and the two must agree byte for byte. If this string is
 * built in two places they will eventually drift, and the symptom would be
 * every casual answer reading as wrong.
 *
 * The option text is hashed EXACTLY as stored — no trimming, no case folding.
 * The client hashes exactly the string it was sent, so any normalisation here
 * would break the comparison. (Publish validation already guarantees the four
 * options are distinct after trimming and case folding, so there is no
 * collision to worry about.)
 *
 * What this buys, and what it does not: the per-run salt stops hashes being
 * reused across runs or precomputed from a word list. It does NOT hide the
 * answers — the browser holds the salt, so it can hash all four options and
 * see which matches. The spec is explicit that this is anti-casual-tampering
 * only, which is why casual results are labelled "self-reported" (R6.5).
 */
final class AnswerHash
{
    public static function for(string $salt, int $questionId, string $correctOptionText): string
    {
        return hash('sha256', $salt.':'.$questionId.':'.$correctOptionText);
    }

    /**
     * 32 hex characters. Fits `quiz_runs.salt` (64) with room to spare.
     */
    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(16));
    }
}
