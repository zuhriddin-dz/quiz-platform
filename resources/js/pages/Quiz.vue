<script setup lang="ts">
/**
 * GET /q/{ulid} — the quiz page. R7.3, R7.4, R7.5, and the host of the play
 * loop from Phases 5 and 6.
 *
 * Also the share link for unlisted quizzes, which is why nothing here assumes
 * the visitor arrived from Explore.
 *
 * Three states:
 *   - unavailable: an unpublished quiz, viewed by anyone but its creator
 *   - idle: the details, the stats and the Start button
 *   - playing: the run, which takes over the page
 *
 * Suggestions (§8) are added in Phase 8, below the fold.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Countdown from '@/components/play/Countdown.vue';
import ModeBadge from '@/components/ModeBadge.vue';
import OptionGrid from '@/components/play/OptionGrid.vue';
import QuizCard from '@/components/QuizCard.vue';
import RunResult from '@/components/play/RunResult.vue';
import { usePointerLayout } from '@/composables/usePointerLayout';
import { useCasualRun } from '@/composables/useCasualRun';
import { useSound } from '@/composables/useSound';
import { useStrictRun } from '@/composables/useStrictRun';

defineOptions({ layout: AppLayout });

type FullQuiz = {
    ulid: string;
    title: string;
    description: string | null;
    creator: { name: string; avatar_url: string | null };
    mode: 'strict' | 'casual';
    mode_label: string;
    seconds_per_question: number;
    question_count: number;
    is_playable: boolean;
    upvotes_count: number;
    runs_count: number;
    status: string;
    is_creator: boolean;
};

const props = defineProps<{
    unavailable: boolean;
    quiz: FullQuiz | { title: string };
    stats?: {
        has_enough_runs: boolean;
        minimum_runs: number;
        pass_rate: number | null;
        average_reached: number | null;
        runs_count: number;
    };
    your_stats?: {
        attempts: number;
        best_reached: number;
        passed: boolean;
        first_passed_at: string | null;
    } | null;
    can_vote?: boolean;
    has_voted?: boolean;
    /** R8.3 — absent on the unavailable page. */
    suggestions?: {
        ulid: string;
        title: string;
        creator: { name: string; avatar_url: string | null };
        mode: 'strict' | 'casual';
        mode_label: string;
        question_count: number;
        seconds_per_question: number;
        upvotes_count: number;
        runs_count: number;
    }[];
}>();

const quiz = computed(() => props.quiz as FullQuiz);

/* Both the play composables and the layout branch on `unavailable`, which is
 * constant for the page's lifetime — so instantiating conditionally is safe. */
const isCasual = !props.unavailable && quiz.value.mode === 'casual';

const { hasKeyboardLayout } = usePointerLayout();

const strict = props.unavailable || isCasual ? null : useStrictRun(quiz.value.ulid);
const casual = !props.unavailable && isCasual ? useCasualRun(quiz.value.ulid) : null;

const active = computed(() => (isCasual ? casual! : strict!));

const phase = computed(() => (props.unavailable ? 'idle' : active.value.phase.value));
const countdown = computed(() => active.value.countdown.value);
const error = computed(() => active.value.error.value);
const result = computed(() => active.value.result.value);
const isPlaying = computed(() => active.value.isPlaying.value);
const timer = computed(() => active.value.timer);

const prompt = computed(() =>
    isCasual ? (casual!.question.value?.prompt ?? '') : (strict!.question.value?.prompt ?? ''),
);

const options = computed<string[]>(() =>
    isCasual ? (casual!.question.value?.options ?? []) : (strict!.question.value?.options ?? []),
);

const questionNumber = computed(() =>
    isCasual ? casual!.questionNumber.value : (strict!.question.value?.number ?? 0),
);

const questionCount = computed(() =>
    isCasual ? casual!.questionCount.value : strict!.questionCount.value,
);

const wrongIndex = computed<number | null>(() => {
    if (isCasual) return casual!.wrongIndex.value;

    return strict!.result.value?.verdict === 'failed'
        ? strict!.result.value.chosen_displayed_index
        : null;
});

const start = (): void => void active.value.start();
const choose = (index: number): void => active.value.choose(index);

const showOptions = computed(
    () => options.value.length > 0 && ['question', 'checking', 'failed'].includes(phase.value),
);

const isChecking = computed(() => phase.value === 'checking');

/** R7.4 — rendered as whole percents; there is no difficulty label. */
const passRatePercent = computed(() =>
    props.stats?.pass_rate === null || props.stats?.pass_rate === undefined
        ? null
        : Math.round(props.stats.pass_rate * 100),
);

const averageReached = computed(() =>
    props.stats?.average_reached === null || props.stats?.average_reached === undefined
        ? null
        : props.stats.average_reached.toFixed(1),
);

/* ------------------------------------------------------------------- sound
 * R10.2 — the three slots, driven off the phase rather than off individual
 * events, so there is exactly one place that decides what is audible.
 */
const sound = useSound();

watch(phase, (now, before) => {
    if (props.unavailable) return;

    // The loop starts on the countdown, which is always inside the user
    // gesture that pressed Start — so autoplay policy is satisfied.
    if (now === 'countdown') {
        sound.startLoop();

        return;
    }

    if (now === 'passed') {
        sound.playWin();

        return;
    }

    if (now === 'failed') {
        sound.playFail();

        return;
    }

    // Back to idle from anywhere (a retry, or an error) silences everything.
    if (now === 'idle' && before !== undefined) {
        sound.stopLoop();
    }
});

onBeforeUnmount(() => sound.stopLoop());

/* ------------------------------------------------------------------ voting
 * R8.1 — a toggle, so the same button does both. `preserveScroll` because the
 * button is above the fold and the page re-renders with the new count.
 */
const voting = ref(false);

const toggleVote = (): void => {
    if (voting.value) return;

    voting.value = true;

    router.post(
        `/q/${quiz.value.ulid}/vote`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                voting.value = false;
            },
        },
    );
};

const onKeydown = (event: KeyboardEvent): void => {
    if (props.unavailable || !hasKeyboardLayout.value || event.key !== 'Enter') return;
    if (phase.value === 'countdown' || isPlaying.value) return;

    event.preventDefault();
    start();
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head :title="quiz.title" />

    <!-- R4.4 — unpublished, and not the creator -->
    <div v-if="unavailable" class="rounded-lg border border-dashed p-10 text-center">
        <h1 class="text-lg font-semibold">{{ quiz.title }}</h1>
        <p class="mt-2 text-sm text-neutral-500">This quiz is no longer available.</p>
    </div>

    <div v-else class="max-w-2xl">
        <!-- R7.3 -->
        <header class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight">{{ quiz.title }}</h1>

            <div class="mt-2 flex items-center gap-2 text-sm text-neutral-500">
                <img
                    v-if="quiz.creator.avatar_url"
                    :src="quiz.creator.avatar_url"
                    :alt="quiz.creator.name"
                    class="size-6 rounded-full"
                />
                <span v-else class="size-6 rounded-full bg-neutral-200" />
                <span>{{ quiz.creator.name }}</span>
            </div>

            <p v-if="quiz.description" class="mt-3 text-sm">{{ quiz.description }}</p>

            <div class="mt-3 flex flex-wrap items-start gap-x-3 gap-y-1 text-sm text-neutral-500">
                <ModeBadge :mode="quiz.mode" :label="quiz.mode_label" with-hint />
                <span>{{ quiz.question_count }} questions</span>
                <span>{{ quiz.seconds_per_question }}s each</span>
                <span>{{ quiz.runs_count }} plays</span>
            </div>

            <p v-if="quiz.status !== 'published'" class="mt-3 text-xs text-amber-700">
                Only you can see this — it is a {{ quiz.status }}.
            </p>
        </header>

        <!-- Upvote (R7.3, R8.1) -->
        <div class="mb-6 flex items-center gap-3">
            <button
                v-if="can_vote"
                type="button"
                :disabled="voting"
                :aria-pressed="has_voted"
                :class="
                    has_voted
                        ? 'border-neutral-900 bg-neutral-900 text-white'
                        : 'hover:border-neutral-400'
                "
                class="rounded-md border px-3 py-1.5 text-sm font-medium transition disabled:opacity-60"
                :title="has_voted ? 'Remove your upvote' : 'Upvote this quiz'"
                @click="toggleVote"
            >
                ▲ {{ quiz.upvotes_count }}
            </button>

            <!-- R7.8 — clicking upvote as a guest prompts sign-in. -->
            <a
                v-else-if="!quiz.is_creator"
                href="/auth/google/redirect"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
            >
                ▲ {{ quiz.upvotes_count }}
            </a>

            <span v-else class="text-sm text-neutral-500">
                ▲ {{ quiz.upvotes_count }} — you cannot upvote your own quiz
            </span>
        </div>

        <!-- R7.4 -->
        <section v-if="stats" class="mb-6 rounded-lg border p-4 text-sm">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                How players do
            </h2>

            <div v-if="stats.has_enough_runs" class="flex flex-wrap gap-x-8 gap-y-2">
                <p>
                    <span class="font-medium">{{ passRatePercent }}%</span>
                    <span class="text-neutral-500"> pass rate</span>
                </p>
                <p>
                    <span class="font-medium">{{ averageReached }}</span>
                    <span class="text-neutral-500">
                        of {{ quiz.question_count }} reached on average
                    </span>
                </p>
            </div>

            <p v-else class="text-neutral-500">Not enough plays yet.</p>
        </section>

        <!-- R7.5 -->
        <section v-if="your_stats" class="mb-6 rounded-lg border p-4 text-sm">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Your stats
            </h2>

            <div class="flex flex-wrap gap-x-8 gap-y-2">
                <p>
                    <span class="font-medium">{{ your_stats.attempts }}</span>
                    <span class="text-neutral-500">
                        attempt{{ your_stats.attempts === 1 ? '' : 's' }}
                    </span>
                </p>
                <p>
                    <span class="font-medium">
                        {{ your_stats.best_reached }}/{{ quiz.question_count }}
                    </span>
                    <span class="text-neutral-500"> best</span>
                </p>
                <p v-if="your_stats.passed" class="text-emerald-700">
                    Passed<span v-if="your_stats.first_passed_at">
                        on {{ your_stats.first_passed_at }}</span
                    >
                </p>
            </div>
        </section>

        <p v-if="error" class="mb-6 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

        <!-- Play -->
        <section v-if="phase === 'idle'" class="py-4">
            <button
                type="button"
                :disabled="!quiz.is_playable"
                class="w-full rounded-md bg-neutral-900 px-8 py-4 text-base font-medium text-white disabled:opacity-50 sm:w-auto"
                @click="start"
            >
                Start
            </button>

            <p v-if="!quiz.is_playable" class="mt-3 text-sm text-neutral-500">
                This quiz has no questions yet.
            </p>
            <p v-else class="mt-3 text-xs text-neutral-400">
                One wrong answer, one timeout or leaving the tab ends the run.
                <span v-if="hasKeyboardLayout">Press Enter to start.</span>
            </p>
        </section>

        <Countdown v-else-if="phase === 'countdown'" :value="countdown" />

        <section v-else>
            <div v-if="phase === 'question' || phase === 'checking'" class="mb-6">
                <div class="mb-2 flex items-baseline justify-between text-sm text-neutral-500">
                    <span>Question {{ questionNumber }} of {{ questionCount }}</span>
                    <span class="tabular-nums">
                        {{ isChecking ? 'checking…' : `${timer.secondsLeft.value}s` }}
                    </span>
                </div>

                <div class="h-1 overflow-hidden rounded bg-neutral-200">
                    <div
                        class="h-full bg-neutral-900 transition-[width] duration-100 ease-linear"
                        :style="{ width: `${timer.fraction.value * 100}%` }"
                    />
                </div>

                <p class="mt-6 text-lg font-medium">{{ prompt }}</p>
            </div>

            <OptionGrid
                v-if="showOptions"
                :options="options"
                :has-keyboard-layout="hasKeyboardLayout"
                :disabled="phase !== 'question'"
                :wrong-index="wrongIndex"
                @choose="choose"
            />

            <RunResult v-if="result" :result="result" class="mt-8" @retry="start" />
        </section>

        <!--
            R8.3 — one mixed list of up to 12, hidden while a run is in
            progress: a grid of links next to a live timer is an invitation to
            lose the run by clicking one.
        -->
        <section v-if="suggestions?.length && !isPlaying" class="mt-12">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                Try next
            </h2>

            <ul class="grid gap-4 sm:grid-cols-2">
                <li v-for="suggestion in suggestions" :key="suggestion.ulid">
                    <QuizCard :quiz="suggestion" />
                </li>
            </ul>
        </section>
    </div>
</template>
