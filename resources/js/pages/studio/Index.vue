<script setup lang="ts">
/**
 * R3.1 — my quizzes, with status, visibility and the lifecycle actions.
 *
 * Every action is gated on the row's own `can` flags, which come straight from
 * QuizPolicy, so the UI never offers something the server would refuse.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import EmptyState from '@/components/EmptyState.vue';

defineOptions({ layout: AppLayout });

type StudioQuiz = {
    ulid: string;
    title: string;
    status: 'draft' | 'published' | 'unpublished';
    status_label: string;
    mode_label: string;
    is_listed: boolean;
    seconds_per_question: number;
    questions_count: number;
    runs_count: number;
    upvotes_count: number;
    published_at: string | null;
    can: {
        update: boolean;
        publish: boolean;
        unpublish: boolean;
        duplicate: boolean;
        delete: boolean;
        view_stats: boolean;
    };
    publish_blockers: string[];
};

defineProps<{ quizzes: StudioQuiz[] }>();

const statusClass = (status: StudioQuiz['status']): string =>
    ({
        draft: 'bg-neutral-100 text-neutral-700',
        published: 'bg-emerald-100 text-emerald-800',
        unpublished: 'bg-amber-100 text-amber-800',
    })[status];

/* ---------------------------------------------------------------- publishing
 * R4.3 — Listed or Unlisted is chosen again on EVERY publish, including
 * republishing an unpublished quiz, so the dialog has no remembered default.
 */
const publishing = ref<StudioQuiz | null>(null);

const publishForm = useForm<{ is_listed: boolean | null }>({ is_listed: null });

const openPublishDialog = (quiz: StudioQuiz): void => {
    publishing.value = quiz;
    publishForm.reset();
    publishForm.clearErrors();
};

const confirmPublish = (): void => {
    if (!publishing.value) return;

    publishForm.post(`/studio/quizzes/${publishing.value.ulid}/publish`, {
        preserveScroll: true,
        onSuccess: () => {
            publishing.value = null;
        },
    });
};

/* ------------------------------------------------------------------- others */

const unpublish = (quiz: StudioQuiz): void => {
    if (!confirm(`Unpublish "${quiz.title}"? Players will no longer be able to open it. Your analytics stay.`)) {
        return;
    }

    router.post(`/studio/quizzes/${quiz.ulid}/unpublish`, {}, { preserveScroll: true });
};

const duplicate = (quiz: StudioQuiz): void => {
    router.post(`/studio/quizzes/${quiz.ulid}/duplicate`);
};

const destroy = (quiz: StudioQuiz): void => {
    // R4.6 — a hard delete, and only ever a draft, so there is nothing to
    // restore afterwards. Say so plainly.
    if (!confirm(`Delete the draft "${quiz.title}"? This cannot be undone.`)) {
        return;
    }

    router.delete(`/studio/quizzes/${quiz.ulid}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Creator Studio" />

    <main class="mx-auto max-w-4xl px-6 py-10">
        <header class="mb-8 flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Creator Studio</h1>
                <p class="text-sm text-neutral-500">Your quizzes.</p>
            </div>

            <Link
                href="/studio/quizzes/create"
                class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white"
            >
                New quiz
            </Link>
        </header>

        <EmptyState
            v-if="quizzes.length === 0"
            title="No quizzes yet"
            body="A quiz is a single set of 5 to 15 questions, each with four options and one right answer."
        >
            <template #action>
                <Link
                    href="/studio/quizzes/create"
                    class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white"
                >
                    Create your first quiz
                </Link>
            </template>
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border">
            <li v-for="quiz in quizzes" :key="quiz.ulid" class="p-4">
                <div class="flex items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ quiz.title }}</p>

                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-500">
                            <span :class="statusClass(quiz.status)" class="rounded px-1.5 py-0.5 font-medium">
                                {{ quiz.status_label }}
                            </span>

                            <!-- Visibility only means something once published. -->
                            <span v-if="quiz.status === 'published'">
                                {{ quiz.is_listed ? 'Listed' : 'Unlisted' }}
                            </span>

                            <span>{{ quiz.mode_label }}</span>
                            <span>{{ quiz.questions_count }} questions</span>
                            <span>{{ quiz.seconds_per_question }}s each</span>
                            <span v-if="quiz.status !== 'draft'">{{ quiz.runs_count }} plays</span>
                            <span v-if="quiz.status !== 'draft'">{{ quiz.upvotes_count }} upvotes</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-3 text-sm">
                        <Link
                            v-if="quiz.can.update"
                            :href="`/studio/quizzes/${quiz.ulid}/edit`"
                            class="font-medium underline"
                        >
                            Edit
                        </Link>

                        <button
                            v-if="quiz.can.publish"
                            type="button"
                            :disabled="quiz.publish_blockers.length > 0"
                            class="font-medium underline disabled:cursor-not-allowed disabled:text-neutral-400 disabled:no-underline"
                            @click="openPublishDialog(quiz)"
                        >
                            {{ quiz.status === 'unpublished' ? 'Publish again' : 'Publish' }}
                        </button>

                        <button
                            v-if="quiz.can.unpublish"
                            type="button"
                            class="font-medium underline"
                            @click="unpublish(quiz)"
                        >
                            Unpublish
                        </button>

                        <button
                            v-if="quiz.can.duplicate"
                            type="button"
                            class="font-medium underline"
                            @click="duplicate(quiz)"
                        >
                            Duplicate
                        </button>

                        <!-- R9.5 — stays available after unpublishing. -->
                        <Link
                            v-if="quiz.can.view_stats && quiz.status !== 'draft'"
                            :href="`/studio/quizzes/${quiz.ulid}/stats`"
                            class="font-medium underline"
                        >
                            Stats
                        </Link>

                        <button
                            v-if="quiz.can.delete"
                            type="button"
                            class="font-medium text-red-600 underline"
                            @click="destroy(quiz)"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                <!-- R4.1 — say why Publish is disabled rather than just
                     greying it out. -->
                <ul
                    v-if="quiz.publish_blockers.length > 0"
                    class="mt-3 list-inside list-disc rounded-md bg-amber-50 p-3 text-xs text-amber-900"
                >
                    <li v-for="blocker in quiz.publish_blockers" :key="blocker">{{ blocker }}</li>
                </ul>
            </li>
        </ul>

        <!-- Publish dialog (R4.3) -->
        <div
            v-if="publishing"
            class="fixed inset-0 flex items-center justify-center bg-black/40 p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="publish-title"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-6">
                <h2 id="publish-title" class="text-lg font-semibold">Publish “{{ publishing.title }}”</h2>

                <p class="mt-2 text-sm text-neutral-600">
                    Once published, the questions, options, timer and mode are fixed permanently. To
                    change anything later, duplicate it as a draft.
                </p>

                <fieldset class="mt-5 space-y-3">
                    <legend class="text-sm font-medium">Who can find it?</legend>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="publishForm.is_listed" type="radio" :value="true" class="mt-1" />
                        <span>
                            <span class="font-medium">Listed</span>
                            <span class="block text-neutral-500">Shown on Explore to everyone.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="publishForm.is_listed" type="radio" :value="false" class="mt-1" />
                        <span>
                            <span class="font-medium">Unlisted</span>
                            <span class="block text-neutral-500">
                                Only reachable by its link. Anyone with the link can play it.
                            </span>
                        </span>
                    </label>
                </fieldset>

                <p v-if="publishForm.errors.is_listed" class="mt-2 text-sm text-red-600">
                    {{ publishForm.errors.is_listed }}
                </p>

                <!-- Server-side publish validation, in case the quiz changed
                     in another tab since this page loaded. -->
                <p v-if="(publishForm.errors as Record<string, string>).publish" class="mt-2 text-sm text-red-600">
                    {{ (publishForm.errors as Record<string, string>).publish }}
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="px-3 py-2 text-sm" @click="publishing = null">
                        Cancel
                    </button>

                    <button
                        type="button"
                        :disabled="publishForm.processing || publishForm.is_listed === null"
                        class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        @click="confirmPublish"
                    >
                        Publish
                    </button>
                </div>
            </div>
        </div>
    </main>
</template>
