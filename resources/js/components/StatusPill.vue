<script setup lang="ts">
/**
 * One definition for every status label in the app: Passed / Attempted on My
 * plays, Draft / Published / Unpublished in the Studio, Unavailable wherever a
 * quiz has been withdrawn.
 *
 * Why it exists: those seven labels previously carried three hand-picked
 * colour pairs between them, including an amber that now collides with the
 * accent. There is deliberately NO warning tone in the system — amber belongs
 * to the brand and the timer — so a status that used to be amber is stated in
 * words on a neutral pill instead.
 *
 * The word always carries the meaning. The tone only reinforces it, so the
 * pill still reads correctly in greyscale and to anyone who cannot separate
 * the hues.
 */
defineProps<{
    /** success: a good outcome. neutral: a plain fact. quiet: a withdrawal. */
    tone?: 'success' | 'neutral' | 'quiet';
}>();
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-border-subtle px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide"
        :class="{
            'text-success-text': tone === 'success',
            'text-text-muted': tone === 'neutral' || tone === undefined,
            'text-text-faint': tone === 'quiet',
        }"
    >
        <span
            v-if="tone === 'success'"
            aria-hidden="true"
            class="size-1.5 rounded-full bg-current"
        />
        <slot />
    </span>
</template>
