import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * R5.8 — the client timer, which is FOR DISPLAY ONLY.
 *
 * The server decides whether an answer was in time (R5.3). This exists so the
 * player can see the pressure, and so the client can report `timeout` when it
 * reaches zero.
 *
 * Two behaviours that matter:
 *
 * 1. It PAUSES during the network round trip. Without that, the latency of
 *    answering question 3 would be charged to question 4, and a slow
 *    connection would feel like a shrinking timer.
 *
 * 2. It measures with `performance.now()` deltas rather than counting
 *    interval ticks. A background tab throttles timers to once a second or
 *    worse, so a tick count would drift badly — and this timer is the thing
 *    that fires `timeout`.
 */
export function useRunTimer(onExpire: () => void) {
    const durationMs = ref(0);
    const remainingMs = ref(0);
    const running = ref(false);

    let frame: number | null = null;
    let lastAt = 0;
    let fired = false;

    const stopLoop = (): void => {
        if (frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }
    };

    const tick = (): void => {
        const now = performance.now();
        const delta = now - lastAt;
        lastAt = now;

        remainingMs.value = Math.max(0, remainingMs.value - delta);

        if (remainingMs.value <= 0) {
            running.value = false;
            stopLoop();

            // Guard against a double fire: expiry must send exactly one answer.
            if (!fired) {
                fired = true;
                onExpire();
            }

            return;
        }

        frame = requestAnimationFrame(tick);
    };

    /** Start a fresh question's timer. */
    const start = (seconds: number): void => {
        stopLoop();
        durationMs.value = seconds * 1000;
        remainingMs.value = durationMs.value;
        fired = false;
        running.value = true;
        lastAt = performance.now();
        frame = requestAnimationFrame(tick);
    };

    /** Freeze the clock — used while "checking…" is on screen. */
    const pause = (): void => {
        running.value = false;
        stopLoop();
    };

    /** Abandon the timer entirely, e.g. when the run ends. */
    const stop = (): void => {
        pause();
        remainingMs.value = 0;
        durationMs.value = 0;
    };

    const secondsLeft = computed(() => Math.ceil(remainingMs.value / 1000));

    const fraction = computed(() =>
        durationMs.value === 0 ? 0 : remainingMs.value / durationMs.value,
    );

    onBeforeUnmount(stopLoop);

    return { start, pause, stop, running, remainingMs, secondsLeft, fraction };
}
