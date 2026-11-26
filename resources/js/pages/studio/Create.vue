<script setup lang="ts">
/**
 * R3.2 — the new-draft form. Quiz fields only; questions come next, in the
 * editor, because a new draft starts empty by design (R3.4).
 */
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps<{ modes: { value: string; label: string }[] }>();

const form = useForm({
    title: '',
    description: '',
    mode: 'strict',
    seconds_per_question: 6,
});

const submit = (): void => {
    form.post('/studio/quizzes');
};
</script>

<template>
    <Head title="New quiz" />

    <main class="mx-auto max-w-xl px-6 py-10">
        <h1 class="mb-8 text-xl font-semibold tracking-tight">New quiz</h1>

        <form class="space-y-6" @submit.prevent="submit">
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
                    rows="3"
                    maxlength="500"
                    class="mt-1 w-full rounded-md border px-3 py-2"
                />
                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                    {{ form.errors.description }}
                </p>
            </div>

            <fieldset>
                <legend class="text-sm font-medium">Mode</legend>

                <p class="mt-1 text-xs text-neutral-500">
                    Strict is refereed by the server, one question at a time. Casual is checked in the
                    browser and its results are labelled self-reported. This cannot be changed once
                    the quiz is published.
                </p>

                <div class="mt-2 space-y-2">
                    <label v-for="mode in modes" :key="mode.value" class="flex items-center gap-2 text-sm">
                        <input v-model="form.mode" type="radio" :value="mode.value" />
                        {{ mode.label }}
                    </label>
                </div>

                <p v-if="form.errors.mode" class="mt-1 text-sm text-red-600">{{ form.errors.mode }}</p>
            </fieldset>

            <div>
                <label for="seconds" class="block text-sm font-medium">
                    Seconds per question
                </label>
                <input
                    id="seconds"
                    v-model.number="form.seconds_per_question"
                    type="number"
                    min="2"
                    max="10"
                    class="mt-1 w-24 rounded-md border px-3 py-2"
                />
                <p class="mt-1 text-xs text-neutral-500">Between 2 and 10.</p>
                <p v-if="form.errors.seconds_per_question" class="mt-1 text-sm text-red-600">
                    {{ form.errors.seconds_per_question }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                Create draft
            </button>
        </form>
    </main>
</template>
