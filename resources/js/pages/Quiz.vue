<script setup lang="ts">
/**
 * GET /q/{ulid} — the quiz page, and the host of the play loop.
 *
 * Also the share link for unlisted quizzes, so nothing here assumes the
 * visitor arrived from Explore.
 *
 * DESIGN: the page is paper — calm, readable, the title in serif. The moment a
 * run starts it is replaced by a full-viewport `.night` surface. That is not
 * decoration: a run is 2-10 seconds per question under sudden death, and the
 * top bar, sidebar and suggestions are all things you could lose a run by
 * clicking. Taking the viewport removes them and leaves four options lit on a
 * dark field.
 *
 * `.night` repoints the semantic colour tokens (resources/css/app.css), so
 * OptionGrid, Countdown and RunResult need no dark-mode classes of their own.
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

/**
 * Leave the play surface and return to the quiz page. `phase` is a plain ref
 * on the composable, so the page can put the machine back to rest; the run
 * itself is already finished server-side by this point.
 */
const exitRun = (): void => {
    active.value.phase.value = 'idle';
};

const showOptions = computed(
    () => options.value.length > 0 && ['question', 'checking', 'failed'].includes(phase.value),
);

/** R6.2 — casual has no checking state, so this is always false there. */
const isChecking = computed(() => phase.value === 'checking');

/** The run has taken the viewport. */
const inRun = computed(() => !props.unavailable && phase.value !== 'idle');

/* ------------------------------------------------------------------- sound
 * R10.2 — the three slots, driven off the phase rather than off individual
 * events, so there is exactly one place that decides what is audible.
 */
const sound = useSound();

watch(phase, (now, before) => {
    if (props.unavailable) return;

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

    if (now === 'idle' && before !== undefined) {
        sound.stopLoop();
    }
});

onBeforeUnmount(() => sound.stopLoop());

/* ------------------------------------------------------------------ voting */
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

/** R7.4 — whole percents; there is no difficulty label. */
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
    <div
        v-if="unavailable"
        class="rounded-xl border border-dashed border-border-subtle bg-surface/60 px-6 py-16 text-center"
    >
        <h1 class="font-display text-2xl">{{ quiz.title }}</h1>
        <p class="mt-2 text-sm text-text-muted">This quiz is no longer available.</p>
    </div>

    <div v-else class="max-w-2xl">
        <!-- ============================ the quiz page (paper) ============ -->
        <header class="mb-8">
            <h1 class="font-display text-3xl leading-tight sm:text-4xl">{{ quiz.title }}</h1>

            <div class="mt-3 flex items-center gap-2 text-sm text-text-muted">
                <img
                    v-if="quiz.creator.avatar_url"
                    :src="quiz.creator.avatar_url"
                    alt=""
                    class="size-6 rounded-full"
                />
                <span v-else class="size-6 rounded-full bg-surface-sunken" />
                <span>{{ quiz.creator.name }}</span>
            </div>

            <p v-if="quiz.description" class="mt-4 text-lg leading-relaxed text-text-muted">
                {{ quiz.description }}
            </p>

            <div class="mt-5 flex flex-wrap items-start gap-x-4 gap-y-2 text-sm text-text-faint">
                <ModeBadge :mode="quiz.mode" :label="quiz.mode_label" with-hint />
                <span>{{ quiz.question_count }} questions</span>
                <span aria-hidden="true">·</span>
                <span>{{ quiz.seconds_per_question }}s each</span>
                <span aria-hidden="true">·</span>
                <span class="tabular-nums">{{ quiz.runs_count }} plays</span>
            </div>

            <p v-if="quiz.status !== 'published'" class="mt-4 text-xs text-text-muted">
                Only you can see this — it is a {{ quiz.status }}.
            </p>
        </header>

        <!-- Start (R7.3) -->
        <section class="mb-10">
            <button
                type="button"
                :disabled="!quiz.is_playable"
                class="w-full rounded-xl bg-accent px-8 py-4 text-base font-medium text-on-accent transition-colors hover:bg-accent-hover disabled:opacity-50 sm:w-auto sm:px-12"
                @click="start"
            >
                Start
            </button>

            <p v-if="!quiz.is_playable" class="mt-3 text-sm text-text-muted">
                This quiz has no questions yet.
            </p>
            <p v-else class="mt-3 text-xs text-text-faint">
                One wrong answer, one timeout or leaving the tab ends the run.
                <span v-if="hasKeyboardLayout">Press Enter to start.</span>
            </p>

            <p v-if="error" class="mt-4 text-sm text-danger-text">{{ error }}</p>
        </section>

        <!-- Upvote (R7.3, R8.1) -->
        <div class="mb-10 flex items-center gap-3 border-y border-border-subtle py-4">
            <button
                v-if="can_vote"
                type="button"
                :disabled="voting"
                :aria-pressed="has_voted"
                :class="
                    has_voted
                        ? 'border-accent bg-accent text-on-accent'
                        : 'border-border-strong hover:bg-surface-soft'
                "
                class="rounded-lg border border-border-subtle px-4 py-1.5 text-sm font-medium tabular-nums transition-colors disabled:opacity-60"
                :title="has_voted ? 'Remove your upvote' : 'Upvote this quiz'"
                @click="toggleVote"
            >
                <span aria-hidden="true">▲</span> {{ quiz.upvotes_count }}
                <span class="sr-only">{{ has_voted ? 'upvoted' : 'upvotes' }}</span>
            </button>

            <!-- R7.8 — clicking upvote as a guest prompts sign-in. -->
            <a
                v-else-if="!quiz.is_creator"
                href="/auth/google/redirect"
                class="rounded-lg border border-border-strong px-4 py-1.5 text-sm font-medium tabular-nums hover:bg-surface-soft"
            >
                <span aria-hidden="true">▲</span> {{ quiz.upvotes_count }}
            </a>

            <span v-else class="text-sm text-text-faint tabular-nums">
                <span aria-hidden="true">▲</span> {{ quiz.upvotes_count }} — you cannot upvote your
                own quiz
            </span>
        </div>

        <!-- R7.4 / R7.5 -->
        <div class="mb-10 grid gap-8 sm:grid-cols-2">
            <section v-if="stats">
                <h2 class="label-eyebrow mb-3">How players do</h2>

                <div v-if="stats.has_enough_runs" class="space-y-1">
                    <p class="font-display text-2xl tabular-nums">{{ passRatePercent }}%</p>
                    <p class="text-sm text-text-muted">
                        pass rate · {{ averageReached }} of {{ quiz.question_count }} reached on
                        average
                    </p>
                </div>

                <p v-else class="text-sm text-text-muted">Not enough plays yet.</p>
            </section>

            <section v-if="your_stats">
                <h2 class="label-eyebrow mb-3">Your stats</h2>

                <div class="space-y-1">
                    <p class="font-display text-2xl tabular-nums">
                        {{ your_stats.best_reached }}/{{ quiz.question_count }}
                    </p>
                    <p class="text-sm text-text-muted">
                        best · {{ your_stats.attempts }} attempt{{
                            your_stats.attempts === 1 ? '' : 's'
                        }}
                    </p>
                    <p v-if="your_stats.passed" class="text-sm text-success-text">
                        Passed<span v-if="your_stats.first_passed_at">
                            on {{ your_stats.first_passed_at }}</span
                        >
                    </p>
                </div>
            </section>
        </div>

        <!-- R8.3 — one mixed list of up to 12 -->
        <section v-if="suggestions?.length" class="mt-12">
            <h2 class="label-eyebrow mb-4">Try next</h2>

            <ul class="grid gap-4 sm:grid-cols-2">
                <li v-for="suggestion in suggestions" :key="suggestion.ulid">
                    <QuizCard :quiz="suggestion" />
                </li>
            </ul>
        </section>
    </div>

    <!-- ======================= the run takes the viewport ================= -->
    <Teleport to="body">
        <div
            v-if="inRun"
            class="night fixed inset-0 z-50 overflow-y-auto bg-bg text-text"
            role="dialog"
            aria-modal="true"
            :aria-label="`Playing ${quiz.title}`"
        >
            <!-- The timer is the brightest, widest thing on screen. It freezes
                 during 'checking', which is the honest thing to show (R5.8). -->
            <div
                v-if="phase === 'question' || phase === 'checking'"
                class="fixed inset-x-0 top-0 h-1 bg-surface-sunken"
            >
                <div
                    class="h-full bg-accent transition-[width] duration-100 ease-linear"
                    :style="{ width: `${timer.fraction.value * 100}%` }"
                />
            </div>

            <div class="mx-auto flex min-h-full max-w-3xl flex-col px-6 py-10">
                <div class="mb-10 flex items-baseline justify-between text-xs text-text-faint">
                    <span class="truncate">{{ quiz.title }}</span>

                    <span v-if="phase === 'question' || phase === 'checking'" class="tabular-nums">
                        {{ questionNumber }} / {{ questionCount }}
                        <span class="ms-3 text-text-muted">
                            {{ isChecking ? 'checking…' : `${timer.secondsLeft.value}s` }}
                        </span>
                    </span>
                </div>

                <Countdown v-if="phase === 'countdown'" :value="countdown" />

                <div v-else class="flex flex-1 flex-col justify-center">
                    <!-- Kept on the fail screen too: the options stay visible
                         so you can see what you picked, and four answers with
                         no question above them is a riddle. It dims rather
                         than disappears, so the result is what draws the eye. -->
                    <p
                        v-if="showOptions"
                        class="mb-10 text-center font-display text-2xl leading-snug transition-opacity sm:text-3xl"
                        :class="phase === 'failed' ? 'opacity-60' : ''"
                    >
                        {{ prompt }}
                    </p>

                    <OptionGrid
                        v-if="showOptions"
                        :options="options"
                        :has-keyboard-layout="hasKeyboardLayout"
                        :disabled="phase !== 'question'"
                        :wrong-index="wrongIndex"
                        @choose="choose"
                    />

                    <RunResult
                        v-if="result"
                        :result="result"
                        class="mt-10"
                        @retry="start"
                        @exit="exitRun"
                    />
                </div>
            </div>
        </div>
    </Teleport>
</template>
