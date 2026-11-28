<script setup lang="ts">
/**
 * GET / — Explore. R7.1, R7.2.
 *
 * Published and listed quizzes only. Sorts, search and the mode filter all
 * live in the URL, so any view of Explore is a shareable link — which is why
 * each control does a GET rather than holding local state.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import EmptyState from '@/components/EmptyState.vue';
import QuizCard from '@/components/QuizCard.vue';

defineOptions({ layout: AppLayout });

type Card = {
    ulid: string;
    title: string;
    creator: { name: string; avatar_url: string | null };
    mode: 'strict' | 'casual';
    mode_label: string;
    question_count: number;
    seconds_per_question: number;
    upvotes_count: number;
    runs_count: number;
};

const props = defineProps<{
    filters: { q: string | null; mode: string; sort: string };
    quizzes: {
        data: Card[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        current_page: number;
        last_page: number;
    };
}>();

const SORTS = [
    { value: 'popular', label: 'Popular' },
    { value: 'played', label: 'Most played' },
    { value: 'newest', label: 'Newest' },
] as const;

const MODES = [
    { value: 'all', label: 'All' },
    { value: 'strict', label: 'Strict' },
    { value: 'casual', label: 'Casual' },
] as const;

/** Keeps the other filters when one changes, and drops defaults from the URL. */
const apply = (changes: Record<string, string | null>): void => {
    const next: Record<string, string> = {};
    const merged = { ...props.filters, ...changes };

    if (merged.q) next.q = merged.q;
    if (merged.mode && merged.mode !== 'all') next.mode = merged.mode;
    if (merged.sort && merged.sort !== 'popular') next.sort = merged.sort;

    router.get('/', next, { preserveState: false, preserveScroll: true });
};

/*
 * Segmented controls rather than selects: three options each, always visible,
 * one tap instead of two — and the current state is readable without opening
 * anything.
 */
const segment = (active: boolean): string =>
    active
        ? 'bg-surface text-text shadow-[var(--shadow-card)]'
        : 'text-text-muted hover:text-text';
</script>

<template>
    <Head title="Explore" />

    <div>
        <header class="mb-8">
            <h1 class="font-display text-3xl leading-tight">
                <template v-if="filters.q">
                    Results for “{{ filters.q }}”
                </template>
                <template v-else>Explore</template>
            </h1>

            <p class="mt-2 max-w-lg text-text-muted">
                <template v-if="filters.q">
                    {{ quizzes.total }} quiz{{ quizzes.total === 1 ? '' : 'zes' }} found.
                </template>
                <template v-else>
                    Time-limited, sudden-death vocabulary quizzes. One wrong answer ends the run.
                    No account needed to play.
                </template>
            </p>
        </header>

        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-3">
            <div class="flex items-center gap-2">
                <span id="mode-label" class="label-eyebrow">Mode</span>
                <div
                    role="group"
                    aria-labelledby="mode-label"
                    class="flex rounded-lg bg-surface-sunken p-0.5"
                >
                    <button
                        v-for="mode in MODES"
                        :key="mode.value"
                        type="button"
                        :aria-pressed="filters.mode === mode.value"
                        :class="segment(filters.mode === mode.value)"
                        class="rounded-md px-3 py-1 text-xs font-medium transition-colors"
                        @click="apply({ mode: mode.value })"
                    >
                        {{ mode.label }}
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span id="sort-label" class="label-eyebrow">Sort</span>
                <div
                    role="group"
                    aria-labelledby="sort-label"
                    class="flex rounded-lg bg-surface-sunken p-0.5"
                >
                    <button
                        v-for="sort in SORTS"
                        :key="sort.value"
                        type="button"
                        :aria-pressed="filters.sort === sort.value"
                        :class="segment(filters.sort === sort.value)"
                        class="rounded-md px-3 py-1 text-xs font-medium transition-colors"
                        @click="apply({ sort: sort.value })"
                    >
                        {{ sort.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- R10.4 — two different empty states, because they need different
             answers: a filter that matched nothing is the user's doing, an
             empty site is ours. -->
        <EmptyState
            v-if="quizzes.data.length === 0 && (filters.q || filters.mode !== 'all')"
            title="Nothing matches those filters"
            body="Try a different word, or widen the mode filter."
        >
            <template #action>
                <button
                    type="button"
                    class="rounded-lg border border-border-strong px-4 py-2 text-sm font-medium hover:bg-surface-soft"
                    @click="apply({ q: null, mode: 'all' })"
                >
                    Clear filters
                </button>
            </template>
        </EmptyState>

        <EmptyState
            v-else-if="quizzes.data.length === 0"
            title="No published quizzes yet"
            body="A quiz is a single set of 5 to 15 questions. Be the first to publish one."
        >
            <template #action>
                <Link
                    href="/studio/quizzes/create"
                    class="inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent-hover"
                >
                    Create a quiz
                </Link>
            </template>
        </EmptyState>

        <ul v-if="quizzes.data.length > 0" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <li v-for="quiz in quizzes.data" :key="quiz.ulid">
                <QuizCard :quiz="quiz" />
            </li>
        </ul>

        <nav
            v-if="quizzes.last_page > 1"
            class="mt-10 flex items-center justify-center gap-2"
            aria-label="Pagination"
        >
            <template v-for="link in quizzes.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    :class="
                        link.active
                            ? 'border-border-strong bg-surface font-medium'
                            : 'border-transparent text-text-muted hover:bg-surface-soft'
                    "
                    class="rounded-lg border px-3 py-1.5 text-sm"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="px-3 py-1.5 text-sm text-text-faint"
                    aria-hidden="true"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>
