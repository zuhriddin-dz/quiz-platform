import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * R5.9 — decides between the WASD layout and the touch grid.
 *
 * The test is the spec's exactly: `(hover: hover) and (pointer: fine)`. That
 * is a question about the INPUT DEVICE, not the screen size, which is why it
 * is not a width breakpoint — a narrow desktop window still has a mouse and a
 * keyboard, and a large tablet still does not.
 *
 * When it matches: W centred on top, A/S/D in a row beneath, key hints drawn
 * on the options, Enter to start or retry.
 * When it does not: a 2x2 grid, no hotkeys, no hints.
 */
const QUERY = '(hover: hover) and (pointer: fine)';

export function usePointerLayout() {
    const hasKeyboardLayout = ref(false);

    let media: MediaQueryList | null = null;

    const sync = (event?: MediaQueryListEvent): void => {
        hasKeyboardLayout.value = event ? event.matches : (media?.matches ?? false);
    };

    onMounted(() => {
        // Guard for SSR and for very old browsers.
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }

        media = window.matchMedia(QUERY);
        sync();

        // A device can change mid-session: a tablet gaining a keyboard case,
        // or a laptop switching to touch input.
        media.addEventListener('change', sync);
    });

    onBeforeUnmount(() => {
        media?.removeEventListener('change', sync);
    });

    return { hasKeyboardLayout };
}
