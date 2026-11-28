<script setup lang="ts">
/**
 * R7.7 — the left sidebar.
 *
 * Signed-in: the 5 most recently attempted quizzes and a "Show all" link.
 * Guest: the "Sign in to track your progress" card (spec §3). Not an empty
 * recent list — a guest's runs ARE recorded against their cookie, but they
 * produce no history rows, and the card is the honest thing to show.
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
    <aside class="w-52 shrink-0 text-sm">
        <template v-if="user()">
            <h2 class="label-eyebrow mb-3">Recent plays</h2>

            <p v-if="recent().length === 0" class="text-text-faint">
                Nothing yet. Pick a quiz and see how far you get.
            </p>

            <ul v-else class="space-y-0.5">
                <li v-for="play in recent()" :key="play.ulid">
                    <Link
                        v-if="play.is_playable"
                        :href="`/q/${play.ulid}`"
                        class="flex items-baseline gap-2 rounded-md px-2 py-1.5 -mx-2 hover:bg-surface-soft"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ play.title }}</span>
                        <span
                            class="shrink-0 text-xs tabular-nums"
                            :class="play.passed ? 'text-success-text' : 'text-text-faint'"
                        >
                            {{ play.passed ? 'passed' : play.best_reached }}
                        </span>
                    </Link>

                    <!-- Unpublished: still in history, no longer openable. -->
                    <span v-else class="flex items-baseline gap-2 px-2 py-1.5 -mx-2 text-text-faint">
                        <span class="min-w-0 flex-1 truncate">{{ play.title }}</span>
                        <span class="shrink-0 text-xs">unavailable</span>
                    </span>
                </li>
            </ul>

            <Link
                v-if="recent().length > 0"
                href="/plays"
                class="mt-4 inline-block text-xs font-medium text-text-muted underline underline-offset-4 hover:text-accent-text"
            >
                Show all
            </Link>
        </template>

        <!-- Guests (§3) -->
        <div v-else class="rounded-xl border border-border-subtle bg-surface p-5 shadow-[var(--shadow-card)]">
            <p class="font-display text-lg leading-snug">Sign in to track your progress</p>
            <p class="mt-2 text-xs leading-relaxed text-text-muted">
                Playing never needs an account. Signing in keeps your attempts and best results, and
                lets you upvote and create quizzes.
            </p>
            <a
                href="/auth/google/redirect"
                class="mt-4 inline-block rounded-lg border border-border-strong px-3 py-1.5 text-xs font-medium hover:bg-surface-soft"
            >
                Sign in with Google
            </a>
        </div>
    </aside>
</template>
