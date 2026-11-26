<script setup lang="ts">
/**
 * R7.7 — top bar, left sidebar, content.
 *
 * Applied per page with `defineOptions({ layout: AppLayout })`, which keeps it
 * a persistent layout: Inertia does not re-mount it between visits, so the
 * sidebar does not flicker and the search box keeps focus.
 *
 * The sidebar is hidden below `lg`. The spec describes a desktop layout and
 * says nothing about small screens, so this is a judgment call: on a phone the
 * recent-plays list would push the quiz grid off the fold, and the same links
 * are in the avatar menu.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Sidebar from '@/components/Sidebar.vue';
import TopBar from '@/components/TopBar.vue';

const page = usePage();

/*
 * R10.4 — the loading state, in one place rather than per page.
 *
 * Explore's filters, the Studio's actions and every navigation go through
 * Inertia, so listening here covers all of them. The bar is deliberately
 * slight: a full-page spinner on a 60ms local visit is worse than nothing.
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
    <div class="min-h-screen bg-white text-neutral-900">
        <!-- R10.4 — a thin indeterminate bar while a visit is in flight. -->
        <div
            v-if="loading"
            class="fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden bg-neutral-200"
            role="status"
            aria-label="Loading"
        >
            <div class="loading-bar h-full w-1/3 bg-neutral-900" />
        </div>

        <TopBar :search="search" />

        <!-- Flash messages from the lifecycle redirects (Phase 4). -->
        <div v-if="flash.status || flash.error" class="mx-auto max-w-6xl px-6 pt-4">
            <p
                v-if="flash.status"
                class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800"
                role="status"
            >
                {{ flash.status }}
            </p>
            <p v-if="flash.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">
                {{ flash.error }}
            </p>
        </div>

        <div class="mx-auto flex max-w-6xl gap-8 px-6 py-8">
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
    animation: slide 1s ease-in-out infinite;
}

@keyframes slide {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(300%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .loading-bar {
        animation: none;
        width: 100%;
        opacity: 0.4;
    }
}
</style>
