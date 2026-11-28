<script setup lang="ts">
/**
 * R10.4 — one empty state, used by Explore, My plays and the Studio list.
 *
 * It exists for consistency rather than code saving: three hand-written dashed
 * boxes drift in padding, tone and text size, and an empty app is the first
 * thing a new user sees. The `action` slot keeps the useful ones from being
 * dead ends.
 */
defineProps<{ title: string; body?: string }>();
</script>

<template>
    <div class="rounded-xl border border-dashed border-border-subtle bg-surface/60 px-6 py-14 text-center">
        <p class="font-display text-xl">{{ title }}</p>
        <p v-if="body" class="mx-auto mt-2 max-w-sm text-sm text-text-muted">{{ body }}</p>

        <div v-if="$slots.action" class="mt-6">
            <slot name="action" />
        </div>
    </div>
</template>
