<script setup lang="ts">
/**
 * R7.2 — title, creator name and avatar, mode badge, question count, seconds
 * per question, upvotes, plays.
 *
 * That is the whole card, and deliberately so: no pass rate, no difficulty
 * label (§7 says there is none anywhere). Shared by Explore and, in Phase 8,
 * by the suggestions list.
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
        class="flex h-full flex-col gap-3 rounded-lg border p-4 transition hover:border-neutral-400"
    >
        <h3 class="font-medium leading-snug">{{ quiz.title }}</h3>

        <div class="flex items-center gap-2 text-xs text-neutral-500">
            <img
                v-if="quiz.creator.avatar_url"
                :src="quiz.creator.avatar_url"
                :alt="quiz.creator.name"
                class="size-5 rounded-full"
            />
            <span v-else class="size-5 rounded-full bg-neutral-200" />
            <span class="truncate">{{ quiz.creator.name }}</span>
        </div>

        <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-500">
            <!-- R6.6 — the badge belongs on cards as well as the play screen. -->
            <ModeBadge :mode="quiz.mode" :label="quiz.mode_label" />
            <span>{{ quiz.question_count }} questions</span>
            <span>{{ quiz.seconds_per_question }}s each</span>
            <span>{{ quiz.upvotes_count }} upvotes</span>
            <span>{{ quiz.runs_count }} plays</span>
        </div>
    </Link>
</template>
