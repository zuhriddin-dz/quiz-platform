<script setup lang="ts">
/**
 * R10.4 — one empty state, used by Explore, My plays and the Studio list.
 *
 * It exists for consistency rather than for code saving: three hand-written
 * dashed boxes drift in padding, tone and text size, and an empty app is the
 * first thing a new user sees. The `action` slot keeps the useful ones from
 * being dead ends.
 */
defineProps<{ title: string; body?: string }>();
</script>

<template>
    <div class="rounded-lg border border-dashed p-10 text-center">
        <p class="text-sm font-medium">{{ title }}</p>
        <p v-if="body" class="mx-auto mt-1 max-w-sm text-sm text-neutral-500">{{ body }}</p>

        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
