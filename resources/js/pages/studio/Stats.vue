<script setup lang="ts">
/**
 * GET /studio/quizzes/{quiz}/stats — R9.1 to R9.4.
 *
 * Three tables, one population. The banner at the top says which runs are
 * counted, because a creator who has test-played their own quiz will
 * otherwise wonder where those runs went.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ModeBadge from '@/components/ModeBadge.vue';

defineOptions({ layout: AppLayout });

type QuestionRow = {
    number: number;
    prompt: string;
    answers: number;
    correct: number;
    correct_rate: number | null;
    average_time_ms: number | null;
    most_picked_wrong_option: { index: number; text: string; picks: number } | null;
    failures: { wrong_answer: number; timeout: number; focus_lost: number };
};

type PlayerRow = {
    name: string;
    avatar_url: string | null;
    attempts: number;
    best_reached: number;
    passed: boolean;
    first_passed_at: string | null;
    last_played_at: string;
};

const props = defineProps<{
    quiz: {
        ulid: string;
        title: string;
        status: string;
        status_label: string;
        mode: 'strict' | 'casual';
        mode_label: string;
        seconds_per_question: number;
        is_self_reported: boolean;
    };
    analytics: {
        totals: {
            runs: number;
            passes: number;
            unique_players: number;
            unique_users: number;
            unique_guests: number;
            pass_rate: number | null;
        };
        questions: QuestionRow[];
        players: PlayerRow[];
        guests: {
            name: string;
            players: number;
            attempts: number;
            best_reached: number;
            passes: number;
            first_passed_at: string | null;
        } | null;
    };
}>();

const questionCount = computed(() => props.analytics.questions.length);

const percent = (value: number | null): string =>
    value === null ? '—' : `${Math.round(value * 100)}%`;

const seconds = (ms: number | null): string => (ms === null ? '—' : `${(ms / 1000).toFixed(1)}s`);

/** A rough bar, so the eye can find the hard question without reading rates. */
const barWidth = (value: number | null): string => (value === null ? '0%' : `${value * 100}%`);
</script>

<template>
    <Head :title="`Stats — ${quiz.title}`" />

    <div>
        <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ quiz.title }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-500">
                    <ModeBadge :mode="quiz.mode" :label="quiz.mode_label" />
                    <span>{{ quiz.status_label }}</span>
                    <span>{{ questionCount }} questions</span>
                    <span>{{ quiz.seconds_per_question }}s each</span>
                </div>
            </div>

            <Link href="/studio" class="text-sm underline">Back to Studio</Link>
        </header>

        <!-- R9.4 — casual results are self-reported -->
        <p
            v-if="quiz.is_self_reported"
            class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900"
        >
            <strong>Self-reported.</strong> This is a casual quiz, so the browser ran the timer and
            reported the results. Correctness was re-checked on the server, but the times below are
            whatever each player's browser said they were.
        </p>

        <!-- The population, stated plainly. -->
        <p class="mb-8 text-xs text-neutral-500">
            Counts finished runs only — abandoned runs are excluded, and so are your own test plays.
        </p>

        <!-- R9.1 -->
        <section class="mb-10">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Totals
            </h2>

            <dl class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border p-4">
                    <dt class="text-xs text-neutral-500">Runs</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ analytics.totals.runs }}
                    </dd>
                </div>

                <div class="rounded-lg border p-4">
                    <dt class="text-xs text-neutral-500">Unique players</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ analytics.totals.unique_players }}
                    </dd>
                    <dd class="mt-1 text-xs text-neutral-400">
                        {{ analytics.totals.unique_users }} signed in,
                        {{ analytics.totals.unique_guests }} guest{{
                            analytics.totals.unique_guests === 1 ? '' : 's'
                        }}
                    </dd>
                </div>

                <div class="rounded-lg border p-4">
                    <dt class="text-xs text-neutral-500">Pass rate</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ percent(analytics.totals.pass_rate) }}
                    </dd>
                    <dd class="mt-1 text-xs text-neutral-400">
                        {{ analytics.totals.passes }} of {{ analytics.totals.runs }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- R9.2 -->
        <section class="mb-10">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Per question
            </h2>

            <p v-if="analytics.totals.runs === 0" class="rounded-lg border border-dashed p-6 text-sm text-neutral-500">
                No finished runs yet.
            </p>

            <ol v-else class="space-y-3">
                <li
                    v-for="question in analytics.questions"
                    :key="question.number"
                    class="rounded-lg border p-4"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium">
                            <span class="text-neutral-400">{{ question.number }}.</span>
                            {{ question.prompt }}
                        </p>

                        <p class="text-sm tabular-nums">
                            <span class="font-semibold">{{ percent(question.correct_rate) }}</span>
                            <span class="text-neutral-500"> correct</span>
                        </p>
                    </div>

                    <div class="mt-2 h-1 overflow-hidden rounded bg-neutral-200">
                        <div class="h-full bg-neutral-900" :style="{ width: barWidth(question.correct_rate) }" />
                    </div>

                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-500">
                        <div class="flex gap-1">
                            <dt>Reached by</dt>
                            <dd class="font-medium text-neutral-700 tabular-nums">
                                {{ question.answers }}
                            </dd>
                        </div>

                        <div class="flex gap-1">
                            <dt>Average time</dt>
                            <dd class="font-medium text-neutral-700 tabular-nums">
                                {{ seconds(question.average_time_ms) }}
                            </dd>
                        </div>

                        <div class="flex gap-1">
                            <dt>Wrong</dt>
                            <dd class="font-medium text-neutral-700 tabular-nums">
                                {{ question.failures.wrong_answer }}
                            </dd>
                        </div>

                        <div class="flex gap-1">
                            <dt>Timed out</dt>
                            <dd class="font-medium text-neutral-700 tabular-nums">
                                {{ question.failures.timeout }}
                            </dd>
                        </div>

                        <div class="flex gap-1">
                            <dt>Left the tab</dt>
                            <dd class="font-medium text-neutral-700 tabular-nums">
                                {{ question.failures.focus_lost }}
                            </dd>
                        </div>
                    </dl>

                    <p v-if="question.most_picked_wrong_option" class="mt-2 text-xs text-neutral-500">
                        Most-picked wrong answer:
                        <span class="font-medium text-neutral-700">
                            “{{ question.most_picked_wrong_option.text }}”
                        </span>
                        ({{ question.most_picked_wrong_option.picks }}
                        pick{{ question.most_picked_wrong_option.picks === 1 ? '' : 's' }})
                    </p>
                </li>
            </ol>
        </section>

        <!-- R9.3 -->
        <section>
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Per player
            </h2>

            <p
                v-if="analytics.players.length === 0 && !analytics.guests"
                class="rounded-lg border border-dashed p-6 text-sm text-neutral-500"
            >
                Nobody has finished a run yet.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left font-medium">Player</th>
                            <th scope="col" class="px-4 py-2 text-right font-medium">Attempts</th>
                            <th scope="col" class="px-4 py-2 text-right font-medium">Best</th>
                            <th scope="col" class="px-4 py-2 text-left font-medium">First passed</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y">
                        <tr v-for="player in analytics.players" :key="player.name + player.last_played_at">
                            <td class="px-4 py-2">
                                <span class="flex items-center gap-2">
                                    <img
                                        v-if="player.avatar_url"
                                        :src="player.avatar_url"
                                        :alt="player.name"
                                        class="size-6 rounded-full"
                                    />
                                    <span v-else class="size-6 rounded-full bg-neutral-200" />
                                    <span>{{ player.name }}</span>
                                    <span
                                        v-if="player.passed"
                                        class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800"
                                    >
                                        Passed
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ player.attempts }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ player.best_reached }}/{{ questionCount }}
                            </td>
                            <td class="px-4 py-2">{{ player.first_passed_at ?? '—' }}</td>
                        </tr>

                        <!-- R9.3 — one row for every guest, together. -->
                        <tr v-if="analytics.guests" class="bg-neutral-50">
                            <td class="px-4 py-2">
                                <span class="font-medium">Guests</span>
                                <span class="ml-2 text-xs text-neutral-500">
                                    {{ analytics.guests.players }} player{{
                                        analytics.guests.players === 1 ? '' : 's'
                                    }}<span v-if="analytics.guests.passes > 0">
                                        , {{ analytics.guests.passes }} passed</span
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ analytics.guests.attempts }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ analytics.guests.best_reached }}/{{ questionCount }}
                            </td>
                            <td class="px-4 py-2">{{ analytics.guests.first_passed_at ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-2 text-xs text-neutral-400">
                Guests are grouped together — they have no account to attribute a run to.
            </p>
        </section>
    </div>
</template>
