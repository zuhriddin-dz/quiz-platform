import { onBeforeUnmount, onMounted } from 'vue';

/**
 * R5.7 — focus loss ends the run, but only in one precise window.
 *
 * It fires when the tab becomes hidden WHILE a question is showing and its
 * timer is running. It must NOT fire during:
 *   - the "checking…" state (the answer is already in flight)
 *   - the 3-2-1 countdown
 *   - either result screen
 *
 * Hence the `isArmed` predicate rather than a boolean prop: the caller owns
 * the state machine, and this only asks it whether now is a fatal moment.
 *
 * Only `visibilitychange` → hidden counts, as the spec says. Window `blur` is
 * deliberately ignored: clicking the devtools, another window or the URL bar
 * blurs the page without hiding it, and failing a run for that would be
 * indefensible.
 */
export function useFocusGuard(isArmed: () => boolean, onLost: () => void) {
    const handle = (): void => {
        if (document.visibilityState === 'hidden' && isArmed()) {
            onLost();
        }
    };

    onMounted(() => {
        document.addEventListener('visibilitychange', handle);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', handle);
    });
}
