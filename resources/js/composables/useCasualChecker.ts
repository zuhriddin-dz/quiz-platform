import type { CasualQuestion, ShuffledQuestion } from '@/types/play';

/**
 * R6.2 — precompute everything on load, so checking an answer is instant and
 * there is no "checking…" state at all.
 *
 * For each question it hashes all four options once, compares each against the
 * question's `answer_hash`, and stores the result as a plain boolean per
 * display position. By the time the player can click, the answer is already a
 * lookup — no await, no network, no spinner.
 *
 * SubtleCrypto needs a secure context. `https://` and `http://localhost` both
 * qualify, so dev and production are fine; an app served over plain http on a
 * LAN address would not be.
 */

const encoder = new TextEncoder();

async function sha256Hex(value: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', encoder.encode(value));

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

/** Fisher-Yates over an index list, so two arrays can be permuted together. */
function shuffledIndexes(length: number): number[] {
    const indexes = Array.from({ length }, (_, index) => index);

    for (let i = indexes.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [indexes[i], indexes[j]] = [indexes[j], indexes[i]];
    }

    return indexes;
}

export function useCasualChecker() {
    /**
     * Shuffle the questions and each question's options, and resolve which
     * display position is correct — all before the first question shows.
     */
    const prepare = async (
        questions: CasualQuestion[],
        salt: string,
    ): Promise<ShuffledQuestion[]> => {
        const questionOrder = shuffledIndexes(questions.length);

        return Promise.all(
            questionOrder.map(async (questionIndex) => {
                const question = questions[questionIndex];
                const optionOrder = shuffledIndexes(question.options.length);

                const options = optionOrder.map((original) => question.options[original]);

                // The hash is built exactly as the server builds it; see
                // app/Support/AnswerHash.php, which owns the format.
                const hashes = await Promise.all(
                    options.map((text) => sha256Hex(`${salt}:${question.id}:${text}`)),
                );

                return {
                    id: question.id,
                    prompt: question.prompt,
                    options,
                    originalIndexes: optionOrder,
                    correctness: hashes.map((hash) => hash === question.answer_hash),
                } satisfies ShuffledQuestion;
            }),
        );
    };

    return { prepare };
}
