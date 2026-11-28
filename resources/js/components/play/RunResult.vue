<script setup lang="ts">
/**
 * R5.10 — both result screens.
 *
 * The correct answer is NEVER shown. On a wrong answer the picked option is
 * marked by OptionGrid (which stays on screen behind this); a timeout or focus
 * loss gets a banner instead, because nothing was picked.
 *
 * "Attempt #N — Best: X/Y" appears only for signed-in players: a guest has no
 * stats row, so the server sends nulls.
 *
 * The confetti is a ~30-line canvas, deliberately not a dependency: one more
 * package for one moment of celebration is not worth the install.
 */
import { onMounted, ref } from 'vue';
import type { CasualVerdict, FailedVerdict, PassedVerdict } from '@/types/play';

const props = defineProps<{ result: PassedVerdict | FailedVerdict | CasualVerdict }>();

defineEmits<{ (event: 'retry'): void; (event: 'exit'): void }>();

const canvas = ref<HTMLCanvasElement | null>(null);

const banner = (): string | null => {
    if (props.result.verdict !== 'failed') return null;

    const reason = 'failure_reason' in props.result ? props.result.failure_reason : null;

    if (reason === null) return null;

    // A wrong answer has no banner: the picked option is marked instead.
    return { wrong_answer: null, timeout: "Time's up", focus_lost: 'Tab left' }[reason];
};

const confetti = (): void => {
    const element = canvas.value;

    if (!element) return;

    // Honour a reduced-motion preference rather than overriding it.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const context = element.getContext('2d');

    if (!context) return;

    element.width = element.offsetWidth;
    element.height = element.offsetHeight;

    const pieces = Array.from({ length: 90 }, () => ({
        x: Math.random() * element.width,
        y: -20 - Math.random() * element.height,
        size: 4 + Math.random() * 5,
        speed: 1.5 + Math.random() * 2.5,
        drift: -0.6 + Math.random() * 1.2,
        spin: -0.1 + Math.random() * 0.2,
        angle: Math.random() * Math.PI,
        hue: Math.floor(Math.random() * 360),
    }));

    const started = performance.now();

    const draw = (): void => {
        context.clearRect(0, 0, element.width, element.height);

        for (const piece of pieces) {
            piece.y += piece.speed;
            piece.x += piece.drift;
            piece.angle += piece.spin;

            context.save();
            context.translate(piece.x, piece.y);
            context.rotate(piece.angle);
            context.fillStyle = `hsl(${piece.hue} 80% 65%)`;
            context.fillRect(-piece.size / 2, -piece.size / 2, piece.size, piece.size * 0.6);
            context.restore();
        }

        // Three seconds, then stop — a flourish, not a screensaver.
        if (performance.now() - started < 3000) {
            requestAnimationFrame(draw);
        } else {
            context.clearRect(0, 0, element.width, element.height);
        }
    };

    requestAnimationFrame(draw);
};

onMounted(() => {
    if (props.result.verdict === 'passed') confetti();
});
</script>

<template>
    <section class="relative text-center" aria-live="polite">
        <canvas
            v-if="result.verdict === 'passed'"
            ref="canvas"
            class="pointer-events-none absolute inset-0 h-full w-full"
            aria-hidden="true"
        />

        <div class="relative space-y-3 py-8">
            <template v-if="result.verdict === 'passed'">
                <p class="label-eyebrow text-success-text">Perfect run</p>
                <p class="font-display text-3xl">
                    {{ result.reached }}/{{ result.question_count }}
                </p>
            </template>

            <template v-else>
                <p v-if="banner()" class="label-eyebrow text-danger-text">{{ banner() }}</p>
                <p v-else class="label-eyebrow text-danger-text">Wrong answer</p>
                <p class="font-display text-3xl">
                    Reached {{ result.reached }}/{{ result.question_count }}
                </p>
            </template>

            <!-- Signed-in players only; guests get nulls from the server. -->
            <p v-if="result.attempt !== null" class="text-sm text-text-muted tabular-nums">
                Attempt #{{ result.attempt }} · Best {{ result.best_reached }}/{{
                    result.question_count
                }}
            </p>

            <div class="flex flex-wrap items-center justify-center gap-3 pt-4">
                <button
                    type="button"
                    class="rounded-lg bg-accent px-6 py-2.5 text-sm font-medium text-on-accent transition-colors hover:bg-accent-hover"
                    @click="$emit('retry')"
                >
                    Retry
                </button>

                <button
                    type="button"
                    class="rounded-lg border border-border-strong px-5 py-2.5 text-sm font-medium transition-colors hover:bg-surface"
                    @click="$emit('exit')"
                >
                    Back to quiz
                </button>
            </div>

            <p class="pt-1 text-xs text-text-faint">
                Every retry reshuffles the questions and options.
            </p>
        </div>
    </section>
</template>
