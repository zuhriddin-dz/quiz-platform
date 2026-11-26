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
    <div class="flex min-h-64 items-center justify-center" aria-live="assertive">
        <!-- The key restarts the animation on each number. -->
        <span :key="value" class="countdown text-7xl font-bold tabular-nums"> {{ value }} </span>
    </div>
</template>

<style scoped>
.countdown {
    animation: pop 900ms ease-out;
}

@keyframes pop {
    0% {
        opacity: 0;
        transform: scale(0.6);
    }
    30% {
        opacity: 1;
        transform: scale(1.1);
    }
    100% {
        opacity: 0.85;
        transform: scale(1);
    }
}

/* Respect a reduced-motion preference: the number still changes, it just
   does not leap about. */
@media (prefers-reduced-motion: reduce) {
    .countdown {
        animation: none;
    }
}
</style>
