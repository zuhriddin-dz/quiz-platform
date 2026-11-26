<script setup lang="ts">
/**
 * GET /plays — My plays. R7.6.
 *
 * Every quiz the user has attempted, most recent first. An unpublished quiz
 * keeps its attempts and best result but shows as "Unavailable" with no
 * "Play again" (R4.4) — nothing is lost, it just cannot be replayed.
 */
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import EmptyState from '@/components/EmptyState.vue';
import ModeBadge from '@/components/ModeBadge.vue';

defineOptions({ layout: AppLayout });

type Play = {
    ulid: string;
    title: string;
    creator: { name: string; avatar_url: string | null };
    mode: 'strict' | 'casual';
    mode_label: string;
    question_count: number;
    passed: boolean;
    attempts: number;
    best_reached: number;
    first_passed_at: string | null;
    last_played_at: string;
    is_playable: boolean;
};

defineProps<{ plays: Play[] }>();
</script>

<template>
    <Head title="My plays" />

    <div>
        <header class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight">My plays</h1>
            <p class="text-sm text-neutral-500">Everything you have attempted, most recent first.</p>
        </header>

        <EmptyState
            v-if="plays.length === 0"
            title="Nothing yet"
            body="Your attempts and best results will collect here once you have played something."
        >
            <template #action>
                <Link
                    href="/"
                    class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white"
                >
                    Find a quiz
                </Link>
            </template>
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="play in plays" :key="play.ulid" class="flex items-start gap-4 p-4">
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 font-medium">
                        <span class="truncate">{{ play.title }}</span>

                        <!-- Status (R7.6) -->
                        <span
                            v-if="play.passed"
                            class="shrink-0 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800"
                        >
                            Passed
                        </span>
                        <span
                            v-else
                            class="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-xs font-medium text-neutral-700"
                        >
                            Attempted
                        </span>

                        <span
                            v-if="!play.is_playable"
                            class="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800"
                        >
                            Unavailable
                        </span>
                    </p>

                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-500">
                        <ModeBadge :mode="play.mode" :label="play.mode_label" />
                        <span>{{ play.creator.name }}</span>
                        <span>{{ play.attempts }} attempt{{ play.attempts === 1 ? '' : 's' }}</span>
                        <span>Best: {{ play.best_reached }}/{{ play.question_count }}</span>
                        <span v-if="play.first_passed_at">First passed {{ play.first_passed_at }}</span>
                        <span v-else>Last played {{ play.last_played_at }}</span>
                    </p>
                </div>

                <Link
                    v-if="play.is_playable"
                    :href="`/q/${play.ulid}`"
                    class="shrink-0 text-sm font-medium underline"
                >
                    Play again
                </Link>
            </li>
        </ul>
    </div>
</template>
