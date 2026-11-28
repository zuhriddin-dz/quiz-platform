<script setup lang="ts">
/**
 * R7.2 — title, creator name and avatar, mode badge, question count, seconds
 * per question, upvotes, plays.
 *
 * That is the whole card, deliberately: no pass rate, no difficulty label
 * (§7 says there is none anywhere). Shared by Explore and the suggestions list.
 *
 * The title is serif and given room to be the largest thing in the card — on
 * a vocabulary site the words are what you are choosing between, so the
 * metadata stays quiet underneath.
 */
import { Link } from '@inertiajs/vue3';
import ModeBadge from '@/components/ModeBadge.vue';

defineProps<{
    quiz: {
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
}>();
</script>

<template>
    <Link
        :href="`/q/${quiz.ulid}`"
        class="group flex h-full flex-col gap-4 rounded-xl border border-border-subtle bg-surface p-5 shadow-[var(--shadow-card)] transition-colors duration-150 hover:border-border-strong"
    >
        <h3 class="font-display text-xl leading-snug group-hover:text-accent-text">
            {{ quiz.title }}
        </h3>

        <div class="flex items-center gap-2 text-xs text-text-faint">
            <img
                v-if="quiz.creator.avatar_url"
                :src="quiz.creator.avatar_url"
                alt=""
                class="size-5 rounded-full"
            />
            <span v-else class="size-5 rounded-full bg-surface-sunken" />
            <span class="truncate">{{ quiz.creator.name }}</span>
        </div>

        <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-text-faint">
            <!-- R6.6 — the badge belongs on cards as well as the play screen. -->
            <ModeBadge :mode="quiz.mode" :label="quiz.mode_label" />

            <span>{{ quiz.question_count }} questions</span>
            <span aria-hidden="true">·</span>
            <span>{{ quiz.seconds_per_question }}s each</span>

            <span class="ms-auto flex items-center gap-3">
                <span class="tabular-nums" :title="`${quiz.upvotes_count} upvotes`">
                    <span aria-hidden="true">▲</span> {{ quiz.upvotes_count }}
                    <span class="sr-only">upvotes</span>
                </span>
                <span class="tabular-nums">{{ quiz.runs_count }} plays</span>
            </span>
        </div>
    </Link>
</template>
