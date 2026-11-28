<script setup lang="ts">
/**
 * R7.7 — top bar, left sidebar, content.
 *
 * Applied per page with `defineOptions({ layout: AppLayout })`, which keeps it
 * a persistent layout: Inertia does not re-mount it between visits, so the
 * sidebar does not flicker and the search box keeps focus.
 *
 * Colours come from semantic tokens only (see resources/css/app.css), so this
 * file names roles — surface, border-subtle, text-muted — and never a hue.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Sidebar from '@/components/Sidebar.vue';
import TopBar from '@/components/TopBar.vue';

const page = usePage();

/*
 * R10.4 — the loading state, in one place rather than per page. Explore's
 * filters, the Studio's actions and every navigation go through Inertia, so
 * listening here covers all of them. Deliberately slight: a full-page spinner
 * on a 60ms local visit is worse than nothing.
 */
const loading = ref(false);

let stopStart: (() => void) | null = null;
let stopFinish: (() => void) | null = null;

onMounted(() => {
    stopStart = router.on('start', () => {
        loading.value = true;
    });

    stopFinish = router.on('finish', () => {
        loading.value = false;
    });
});

onBeforeUnmount(() => {
    stopStart?.();
    stopFinish?.();
});

const search = computed<string | null>(
    () => (page.props.filters as { q?: string | null } | undefined)?.q ?? null,
);

const flash = computed(
    () => (page.props.flash ?? {}) as { status?: string | null; error?: string | null },
);
</script>

<template>
    <div class="min-h-screen bg-bg text-text">
        <!-- R10.4 — a thin indeterminate bar while a visit is in flight. -->
        <div
            v-if="loading"
            class="fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden bg-surface-sunken"
            role="status"
            aria-label="Loading"
        >
            <div class="loading-bar h-full w-1/3 bg-accent" />
        </div>

        <TopBar :search="search" />

        <!-- Flash messages from the lifecycle redirects (Phase 4). -->
        <div v-if="flash.status || flash.error" class="mx-auto max-w-6xl px-6 pt-5">
            <p
                v-if="flash.status"
                class="rounded-lg border border-border-subtle bg-surface px-4 py-3 text-sm"
                role="status"
            >
                {{ flash.status }}
            </p>
            <p
                v-if="flash.error"
                class="rounded-lg border border-danger-border bg-surface px-4 py-3 text-sm text-danger-text"
                role="alert"
            >
                {{ flash.error }}
            </p>
        </div>

        <div class="mx-auto flex max-w-6xl gap-12 px-6 py-10">
            <div class="hidden lg:block">
                <Sidebar />
            </div>

            <div class="min-w-0 flex-1" :aria-busy="loading">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
.loading-bar {
    animation: slide 1s var(--ease-out-quick) infinite;
}

@keyframes slide {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(300%);
    }
}

/* The global reduced-motion rule flattens the animation; give the bar a
   visible resting state so "loading" is still carried without movement. */
@media (prefers-reduced-motion: reduce) {
    .loading-bar {
        width: 100%;
        opacity: 0.5;
    }
}
</style>
