import { computed, ref } from 'vue';
import type {
    AnswerVerdict,
    FailedVerdict,
    PassedVerdict,
    PlayPhase,
    PlayQuestion,
    StartedRun,
} from '@/types/play';
import { useFocusGuard } from '@/composables/useFocusGuard';
import { useRunTimer } from '@/composables/useRunTimer';

/**
 * The strict-mode state machine, in one place.
 *
 *   idle -> countdown -> question -> checking -> question -> ... -> passed|failed
 *
 * Every transition is here rather than in the component, because the rules
 * that make strict mode fair are transitions: the timer pauses on `checking`,
 * focus loss is only fatal on `question`, and exactly one answer may be sent
 * per question.
 */
const COUNTDOWN_FROM = 3;

export function useStrictRun(quizUlid: string) {
    const phase = ref<PlayPhase>('idle');
    const countdown = ref(COUNTDOWN_FROM);

    const runId = ref<string | null>(null);
    const question = ref<PlayQuestion | null>(null);
    const questionCount = ref(0);
    const secondsPerQuestion = ref(0);
    const reached = ref(0);

    const result = ref<PassedVerdict | FailedVerdict | null>(null);
    const error = ref<string | null>(null);

    /**
     * One answer per question, enforced here rather than by disabling buttons:
     * a click, a keypress, the timer and the focus guard can all fire within
     * the same frame.
     */
    let answerSent = false;

    const timer = useRunTimer(() => {
        void submit({ reason: 'timeout' });
    });

    // R5.7 — fatal only while a question is showing and its timer runs.
    useFocusGuard(
        () => phase.value === 'question' && timer.running.value,
        () => void submit({ reason: 'focus_lost' }),
    );

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

    /** The 3-2-1 before the first question (R5.6 shared rules). */
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

    const showQuestion = (next: PlayQuestion): void => {
        question.value = next;
        answerSent = false;
        phase.value = 'question';
        timer.start(secondsPerQuestion.value);
    };

    const start = async (): Promise<void> => {
        if (phase.value === 'countdown' || phase.value === 'question' || phase.value === 'checking') {
            return;
        }

        error.value = null;
        result.value = null;
        reached.value = 0;

        try {
            // Started BEFORE the countdown, so the first question is already
            // in hand when it reaches zero and there is no stall. The server
            // issues its clock on the question it hands back, and the client
            // timer only starts when the question is actually shown.
            const started = await post<StartedRun>(`/q/${quizUlid}/runs`, {});

            runId.value = started.run.id;
            questionCount.value = started.run.question_count;
            secondsPerQuestion.value = started.run.seconds_per_question;

            await runCountdown();

            showQuestion(started.question);
        } catch {
            phase.value = 'idle';
            error.value = 'Could not start the run. Please try again.';
        }
    };

    const submit = async (body: { displayed_index: number } | { reason: 'timeout' | 'focus_lost' }): Promise<void> => {
        if (answerSent || runId.value === null) {
            return;
        }

        if (phase.value !== 'question') {
            return;
        }

        answerSent = true;

        // R5.8 — pause the display timer for the round trip and say so.
        timer.pause();
        phase.value = 'checking';

        try {
            const verdict = await post<AnswerVerdict>(`/runs/${runId.value}/answers`, body);

            reached.value = verdict.reached;

            if (verdict.verdict === 'correct') {
                showQuestion(verdict.question);

                return;
            }

            timer.stop();
            result.value = verdict;
            phase.value = verdict.verdict === 'passed' ? 'passed' : 'failed';
        } catch {
            // The answer may or may not have landed. Re-asking would risk
            // answering the NEXT question by accident, so stop and say so.
            timer.stop();
            phase.value = 'idle';
            error.value = 'Lost connection mid-answer. Start again to be sure.';
        }
    };

    const choose = (displayedIndex: number): void => {
        void submit({ displayed_index: displayedIndex });
    };

    const isPlaying = computed(() => phase.value === 'question' || phase.value === 'checking');

    return {
        phase,
        countdown,
        question,
        questionCount,
        secondsPerQuestion,
        reached,
        result,
        error,
        isPlaying,
        timer,
        start,
        choose,
    };
}
