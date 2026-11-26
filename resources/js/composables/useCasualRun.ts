import { computed, ref } from 'vue';
import type {
    CasualAnswerEntry,
    CasualVerdict,
    PlayPhase,
    ShuffledQuestion,
    StartedCasualRun,
} from '@/types/play';
import { useCasualChecker } from '@/composables/useCasualChecker';
import { useFocusGuard } from '@/composables/useFocusGuard';
import { useRunTimer } from '@/composables/useRunTimer';

/**
 * The casual state machine.
 *
 *   idle -> countdown -> question -> question -> ... -> passed | failed
 *
 * Note what is missing: there is no `checking` phase (R6.2). Every answer is
 * resolved from a precomputed boolean, so the next question appears on the
 * same frame as the click.
 *
 * Everything else is shared with strict mode: the same timer, the same focus
 * guard, the same shared rules. The run ends on the first failure and the log
 * is posted once.
 */
const COUNTDOWN_FROM = 3;

export function useCasualRun(quizUlid: string) {
    const phase = ref<PlayPhase>('idle');
    const countdown = ref(COUNTDOWN_FROM);

    const runId = ref<string | null>(null);
    const questions = ref<ShuffledQuestion[]>([]);
    const index = ref(0);
    const secondsPerQuestion = ref(0);
    const reached = ref(0);

    const result = ref<CasualVerdict | null>(null);
    const error = ref<string | null>(null);
    const wrongIndex = ref<number | null>(null);

    const log: CasualAnswerEntry[] = [];
    let answeredThisQuestion = false;
    let shownAt = 0;

    const { prepare } = useCasualChecker();

    const timer = useRunTimer(() => answer(null, 'timeout'));

    // R5.7 — same rule as strict: fatal only on a live question.
    useFocusGuard(
        () => phase.value === 'question' && timer.running.value,
        () => answer(null, 'focus_lost'),
    );

    const question = computed<ShuffledQuestion | null>(() => questions.value[index.value] ?? null);

    const csrf = (): string =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const post = async <T>(url: string, body: unknown): Promise<T> => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body ?? {}),
        });

        if (!response.ok) {
            throw new Error(`Request failed with ${response.status}`);
        }

        return (await response.json()) as T;
    };

    const runCountdown = (): Promise<void> =>
        new Promise((resolve) => {
            phase.value = 'countdown';
            countdown.value = COUNTDOWN_FROM;

            const step = (): void => {
                if (countdown.value <= 1) {
                    resolve();

                    return;
                }

                countdown.value -= 1;
                window.setTimeout(step, 1000);
            };

            window.setTimeout(step, 1000);
        });

    const showQuestion = (): void => {
        answeredThisQuestion = false;
        wrongIndex.value = null;
        phase.value = 'question';
        shownAt = performance.now();
        timer.start(secondsPerQuestion.value);
    };

    const finish = async (): Promise<void> => {
        timer.stop();

        try {
            const verdict = await post<CasualVerdict>(`/runs/${runId.value}/submit`, {
                answers: log,
            });

            reached.value = verdict.reached;
            result.value = verdict;
            phase.value = verdict.verdict === 'passed' ? 'passed' : 'failed';
        } catch {
            phase.value = 'idle';
            error.value = 'Could not submit the run. Please try again.';
        }
    };

    /**
     * One entry per question, appended as it happens. The server re-checks
     * correctness anyway (R6.4), so what is recorded here is only a claim.
     */
    const answer = (displayedIndex: number | null, reason?: 'timeout' | 'focus_lost'): void => {
        if (answeredThisQuestion || phase.value !== 'question') {
            return;
        }

        const current = question.value;

        if (!current) return;

        answeredThisQuestion = true;
        timer.pause();

        const timeMs = Math.round(performance.now() - shownAt);

        if (reason) {
            log.push({ question_id: current.id, reason, time_ms: timeMs });
            void finish();

            return;
        }

        const chosen = displayedIndex as number;

        log.push({
            question_id: current.id,
            // The ORIGINAL index, mapped back from the client's own shuffle.
            chosen_option_index: current.originalIndexes[chosen],
            time_ms: timeMs,
        });

        // Instant: a precomputed boolean, no await (R6.2).
        if (!current.correctness[chosen]) {
            wrongIndex.value = chosen;
            void finish();

            return;
        }

        reached.value += 1;

        if (index.value >= questions.value.length - 1) {
            void finish();

            return;
        }

        index.value += 1;
        showQuestion();
    };

    const start = async (): Promise<void> => {
        if (phase.value === 'countdown' || phase.value === 'question') {
            return;
        }

        error.value = null;
        result.value = null;
        reached.value = 0;
        index.value = 0;
        log.length = 0;

        try {
            const started = await post<StartedCasualRun>(`/q/${quizUlid}/runs`, {});

            runId.value = started.run.id;
            secondsPerQuestion.value = started.run.seconds_per_question;

            // Hashing happens here, during the countdown, so the first click
            // is never waiting on crypto.
            const [prepared] = await Promise.all([
                prepare(started.questions, started.run.salt),
                runCountdown(),
            ]);

            questions.value = prepared;

            showQuestion();
        } catch {
            phase.value = 'idle';
            error.value = 'Could not start the run. Please try again.';
        }
    };

    const choose = (displayedIndex: number): void => answer(displayedIndex);

    const isPlaying = computed(() => phase.value === 'question');

    const questionCount = computed(() => questions.value.length);

    const questionNumber = computed(() => index.value + 1);

    return {
        phase,
        countdown,
        question,
        questionCount,
        questionNumber,
        secondsPerQuestion,
        reached,
        result,
        error,
        wrongIndex,
        isPlaying,
        timer,
        start,
        choose,
    };
}
