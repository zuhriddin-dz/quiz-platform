<script setup lang="ts">
/**
 * R10.4 — the error state.
 *
 * Before this, a 403 or a 404 fell through to Laravel's own error page: a
 * dead end with no top bar, no sidebar and no way back. Rendering them as an
 * Inertia page keeps the app's shell and gives the visitor somewhere to go.
 *
 * Only non-JSON requests reach here. The play loop's endpoints rely on real
 * status codes (403, 409, 422, 429), so `bootstrap/app.php` leaves those
 * alone — see docs/phase-10.md.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps<{ status: number }>();

const COPY: Record<number, { title: string; body: string }> = {
    403: {
        title: 'Not yours to open',
        body: 'This belongs to someone else, or it is a draft that has not been published.',
    },
    404: {
        title: 'Nothing here',
        body: 'That link may be mistyped, or the quiz may have been deleted while it was still a draft.',
    },
    419: {
        title: 'Your session expired',
        body: 'Sign in again and pick up where you left off. Nothing has been lost.',
    },
    429: {
        title: 'Slow down a moment',
        body: 'You have started a lot of runs very quickly. Wait a minute and try again.',
    },
    500: {
        title: 'Something broke on our side',
        body: 'Not your fault. Try again in a moment.',
    },
    503: {
        title: 'Back shortly',
        body: 'The site is being updated. Try again in a minute.',
    },
};

const copy = computed(
    () =>
        COPY[props.status] ?? {
            title: 'Something went wrong',
            body: 'Try again, or head back to Explore.',
        },
);
</script>

<template>
    <Head :title="copy.title" />

    <div class="rounded-xl border border-dashed border-border-subtle bg-surface/60 px-6 py-16 text-center">
        <p class="label-eyebrow">{{ status }}</p>
        <h1 class="mt-3 font-display text-2xl">{{ copy.title }}</h1>
        <p class="mx-auto mt-2 max-w-md text-sm text-text-muted">{{ copy.body }}</p>

        <Link
            href="/"
            class="mt-6 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-on-accent"
        >
            Back to Explore
        </Link>
    </div>
</template>
