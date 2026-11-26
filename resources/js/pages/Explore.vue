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

/** Keeps the other filters when one changes, and drops page 1 from the URL. */
const apply = (changes: Record<string, string | null>): void => {
    const next: Record<string, string> = {};

    const merged = { ...props.filters, ...changes };

    if (merged.q) next.q = merged.q;
    if (merged.mode && merged.mode !== 'all') next.mode = merged.mode;
    if (merged.sort && merged.sort !== 'popular') next.sort = merged.sort;

    router.get('/', next, { preserveState: false, preserveScroll: true });
};
</script>

<template>
    <Head title="Explore" />

    <div>
        <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Explore</h1>
                <p class="text-sm text-neutral-500">
                    <template v-if="filters.q">
                        {{ quizzes.total }} result{{ quizzes.total === 1 ? '' : 's' }} for
                        “{{ filters.q }}”
                    </template>
                    <template v-else>
                        Time-limited, sudden-death vocabulary quizzes. No account needed to play.
                    </template>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <label for="mode" class="text-xs font-medium text-neutral-500">Mode</label>
                    <select
                        id="mode"
                        :value="filters.mode"
                        class="rounded-md border px-2 py-1 text-sm"
                        @change="apply({ mode: ($event.target as HTMLSelectElement).value })"
                    >
                        <option v-for="mode in MODES" :key="mode.value" :value="mode.value">
                            {{ mode.label }}
                        </option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label for="sort" class="text-xs font-medium text-neutral-500">Sort</label>
                    <select
                        id="sort"
                        :value="filters.sort"
                        class="rounded-md border px-2 py-1 text-sm"
                        @change="apply({ sort: ($event.target as HTMLSelectElement).value })"
                    >
                        <option v-for="sort in SORTS" :key="sort.value" :value="sort.value">
                            {{ sort.label }}
                        </option>
                    </select>
                </div>
            </div>
        </header>

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
                    class="rounded-md border px-3 py-1.5 text-sm font-medium"
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
                    class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white"
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

        <nav v-if="quizzes.last_page > 1" class="mt-8 flex flex-wrap justify-center gap-1">
            <template v-for="link in quizzes.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :class="link.active ? 'bg-neutral-900 text-white' : 'hover:bg-neutral-50'"
                    class="rounded border px-3 py-1 text-sm"
                    v-html="link.label"
                />
                <span v-else class="rounded px-3 py-1 text-sm text-neutral-300" v-html="link.label" />
            </template>
        </nav>
    </div>
</template>
