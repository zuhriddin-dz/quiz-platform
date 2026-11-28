<script setup lang="ts">
/**
 * The 3-2-1 before the first question.
 *
 * Focus loss is NOT fatal here (R5.7), which is the state machine's business,
 * not this component's — it only draws the number.
 */
defineProps<{ value: number }>();
</script>

<template>
    <div class="flex min-h-[60vh] flex-col items-center justify-center gap-4" aria-live="assertive">
        <!-- The key restarts the animation on each number. -->
        <span :key="value" class="countdown font-display text-4xl tabular-nums sm:text-[6rem]">
            {{ value }}
        </span>
        <p class="label-eyebrow">Get ready</p>
    </div>
</template>

<style scoped>
.countdown {
    animation: pop 900ms var(--ease-out-quick);
}

@keyframes pop {
    0% {
        opacity: 0;
        transform: scale(0.7);
    }
    30% {
        opacity: 1;
        transform: scale(1.08);
    }
    100% {
        opacity: 0.9;
        transform: scale(1);
    }
}
</style>
