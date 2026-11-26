<script setup lang="ts">
/**
 * R7.7 — the left sidebar.
 *
 * Signed-in: the 5 most recently attempted quizzes and a "Show all" link to
 * My plays.
 *
 * Guest: the "Sign in to track your progress" card instead (spec §3). Not an
 * empty recent list — a guest's runs ARE recorded against their cookie, but
 * they produce no history rows, and the card is the honest thing to show.
 */
import { Link, usePage } from '@inertiajs/vue3';

type RecentPlay = {
    ulid: string;
    title: string;
    best_reached: number;
    passed: boolean;
    is_playable: boolean;
};

const page = usePage();

const user = () => page.props.auth?.user ?? null;
const recent = () => (page.props.recent_plays ?? []) as RecentPlay[];
</script>

<template>
    <aside class="w-56 shrink-0 text-sm">
        <template v-if="user()">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Recent plays
            </h2>

            <p v-if="recent().length === 0" class="text-neutral-500">
                Nothing yet. Pick a quiz and see how far you get.
            </p>

            <ul v-else class="space-y-1">
                <li v-for="play in recent()" :key="play.ulid">
                    <Link
                        v-if="play.is_playable"
                        :href="`/q/${play.ulid}`"
                        class="block truncate rounded px-2 py-1 hover:bg-neutral-50"
                    >
                        <span class="truncate">{{ play.title }}</span>
                        <span class="ml-1 text-xs text-neutral-400">
                            {{ play.passed ? 'passed' : play.best_reached }}
                        </span>
                    </Link>

                    <!-- Unpublished: still in history, no longer openable. -->
                    <span v-else class="block truncate px-2 py-1 text-neutral-400">
                        {{ play.title }}
                        <span class="ml-1 text-xs">unavailable</span>
                    </span>
                </li>
            </ul>

            <Link
                v-if="recent().length > 0"
                href="/plays"
                class="mt-3 inline-block text-xs font-medium underline"
            >
                Show all
            </Link>
        </template>

        <!-- Guests (§3) -->
        <div v-else class="rounded-lg border p-4">
            <p class="font-medium">Sign in to track your progress</p>
            <p class="mt-1 text-xs text-neutral-500">
                Playing never needs an account. Signing in keeps your attempts and best results, and
                lets you upvote and create quizzes.
            </p>
            <a
                href="/auth/google/redirect"
                class="mt-3 inline-block rounded-md border px-3 py-1.5 text-xs font-medium"
            >
                Sign in with Google
            </a>
        </div>
    </aside>
</template>
