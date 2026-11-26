<script setup lang="ts">
/**
 * R3.2 + R3.3 — the editor. One form, one save.
 *
 * Questions are edited in place and PUT as a whole list, because the spec's
 * route table has no question endpoints. Removing a row and saving deletes
 * that question; adding a row and saving creates it.
 *
 * Nothing here blocks an incomplete question (R3.4). The counters below tell
 * the creator what publishing will still require, but Save always works.
 */
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

type QuestionForm = {
    id: number | null;
    prompt: string;
    options: string[];
    correct_option_index: number | null;
};

const props = defineProps<{
    modes: { value: string; label: string }[];
    publishable_question_range: [number, number];
    quiz: {
        ulid: string;
        title: string;
        description: string | null;
        mode: string;
        seconds_per_question: number;
        questions: QuestionForm[];
    };
}>();

const OPTION_COUNT = 4;

const blankQuestion = (): QuestionForm => ({
    id: null,
    prompt: '',
    options: Array.from({ length: OPTION_COUNT }, () => ''),
    correct_option_index: null,
});

const form = useForm({
    title: props.quiz.title,
    description: props.quiz.description ?? '',
    mode: props.quiz.mode,
    seconds_per_question: props.quiz.seconds_per_question,
    questions: props.quiz.questions.map((question) => ({
        ...question,
        // A stored question may have fewer than four filled options; the
        // editor always shows four inputs.
        options: [...question.options, ...Array(OPTION_COUNT).fill('')].slice(0, OPTION_COUNT),
    })),
});

const [minQuestions, maxQuestions] = props.publishable_question_range;

const addQuestion = (): void => {
    if (form.questions.length < maxQuestions) {
        form.questions.push(blankQuestion());
    }
};

const removeQuestion = (index: number): void => {
    form.questions.splice(index, 1);
};

/** Mirrors Question::isComplete() on the server — advisory only. */
const isComplete = (question: QuestionForm): boolean => {
    if (question.prompt.trim() === '' || question.correct_option_index === null) {
        return false;
    }

    const options = question.options.map((option) => option.trim());

    if (options.length !== OPTION_COUNT || options.some((option) => option === '')) {
        return false;
    }

    return new Set(options.map((option) => option.toLowerCase())).size === OPTION_COUNT;
};

const completeCount = computed(() => form.questions.filter(isComplete).length);

const publishBlockers = computed<string[]>(() => {
    const blockers: string[] = [];

    if (form.questions.length < minQuestions) {
        blockers.push(`Needs at least ${minQuestions} questions (has ${form.questions.length}).`);
    }

    const incomplete = form.questions.length - completeCount.value;

    if (incomplete > 0) {
        blockers.push(
            `${incomplete} question${incomplete === 1 ? '' : 's'} still need a prompt, four distinct options and a correct answer.`,
        );
    }

    return blockers;
});

const submit = (): void => {
    form.put(`/studio/quizzes/${props.quiz.ulid}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Editing ${quiz.title}`" />

    <main class="mx-auto max-w-3xl px-6 py-10">
        <header class="mb-8 flex items-baseline justify-between gap-4">
            <h1 class="text-xl font-semibold tracking-tight">Edit draft</h1>
            <Link href="/studio" class="text-sm underline">Back to Studio</Link>
        </header>

        <form class="space-y-10" @submit.prevent="submit">
            <!-- Quiz fields (R3.2) -->
            <section class="space-y-6">
                <div>
                    <label for="title" class="block text-sm font-medium">Title</label>
                    <input
                        id="title"
                        v-model="form.title"
                        type="text"
                        maxlength="80"
                        class="mt-1 w-full rounded-md border px-3 py-2"
                    />
                    <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium">
                        Description <span class="font-normal text-neutral-500">(optional)</span>
                    </label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="2"
                        maxlength="500"
                        class="mt-1 w-full rounded-md border px-3 py-2"
                    />
                </div>

                <div class="flex flex-wrap items-end gap-6">
                    <div>
                        <label for="mode" class="block text-sm font-medium">Mode</label>
                        <select id="mode" v-model="form.mode" class="mt-1 rounded-md border px-3 py-2">
                            <option v-for="mode in modes" :key="mode.value" :value="mode.value">
                                {{ mode.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label for="seconds" class="block text-sm font-medium">Seconds per question</label>
                        <input
                            id="seconds"
                            v-model.number="form.seconds_per_question"
                            type="number"
                            min="2"
                            max="10"
                            class="mt-1 w-24 rounded-md border px-3 py-2"
                        />
                    </div>
                </div>
            </section>

            <!-- Questions (R3.3) -->
            <section>
                <div class="mb-4 flex items-baseline justify-between gap-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
                        Questions
                    </h2>
                    <p class="text-xs text-neutral-500">
                        {{ completeCount }} of {{ form.questions.length }} complete
                    </p>
                </div>

                <ol class="space-y-6">
                    <li
                        v-for="(question, index) in form.questions"
                        :key="question.id ?? `new-${index}`"
                        class="rounded-lg border p-4"
                    >
                        <div class="mb-3 flex items-center justify-between gap-4">
                            <span class="text-xs font-medium text-neutral-500">
                                Question {{ index + 1 }}
                                <span v-if="!isComplete(question)" class="text-amber-600">— incomplete</span>
                            </span>

                            <button
                                type="button"
                                class="text-xs text-red-600 underline"
                                @click="removeQuestion(index)"
                            >
                                Remove
                            </button>
                        </div>

                        <input
                            v-model="question.prompt"
                            type="text"
                            maxlength="200"
                            placeholder="Prompt"
                            class="w-full rounded-md border px-3 py-2"
                        />
                        <p
                            v-if="form.errors[`questions.${index}.prompt` as keyof typeof form.errors]"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors[`questions.${index}.prompt` as keyof typeof form.errors] }}
                        </p>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="optionIndex in [0, 1, 2, 3]"
                                :key="optionIndex"
                                class="flex items-center gap-2"
                            >
                                <!-- Picking the correct answer is a radio, so
                                     exactly one can be chosen. -->
                                <input
                                    v-model="question.correct_option_index"
                                    type="radio"
                                    :value="optionIndex"
                                    :name="`correct-${index}`"
                                    :aria-label="`Option ${optionIndex + 1} is correct`"
                                />
                                <input
                                    v-model="question.options[optionIndex]"
                                    type="text"
                                    maxlength="80"
                                    :placeholder="`Option ${optionIndex + 1}`"
                                    class="w-full rounded-md border px-3 py-1.5"
                                />
                            </label>
                        </div>
                    </li>
                </ol>

                <button
                    type="button"
                    :disabled="form.questions.length >= maxQuestions"
                    class="mt-4 rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                    @click="addQuestion"
                >
                    Add question
                </button>
            </section>

            <!-- Advisory only: saving an incomplete draft is allowed (R3.4). -->
            <aside v-if="publishBlockers.length > 0" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">Not ready to publish yet</p>
                <ul class="mt-1 list-inside list-disc">
                    <li v-for="blocker in publishBlockers" :key="blocker">{{ blocker }}</li>
                </ul>
            </aside>

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    Save draft
                </button>

                <span v-if="form.recentlySuccessful" class="text-sm text-emerald-700">Saved.</span>
            </div>
        </form>
    </main>
</template>
