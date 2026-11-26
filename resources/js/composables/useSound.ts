import { readonly, ref } from 'vue';

/**
 * R10.2, R10.3 — three sound slots and a mute toggle.
 *
 * State is MODULE-SCOPED, not per-call: the toggle lives in the top bar and
 * playback happens on the quiz page, and they have to agree. Calling
 * `useSound()` twice gives you the same mute flag and the same audio elements.
 *
 * The files are placeholders, to be replaced with the real effects later. They
 * are served from `public/sounds/` rather than bundled from
 * `resources/sounds/`, so a missing file is a silent 404 rather than a failed
 * Vite build — which is what you want while they are still placeholders. See
 * docs/phase-10.md.
 *
 * Autoplay: browsers block audio until a user gesture. The first gesture here
 * is always the Start click, so the loop starting on `countdown` is already
 * inside one. Nothing plays on page load, by design as much as by policy.
 */

const STORAGE_KEY = 'quiz.muted';

const SOURCES = {
    gameLoop: '/sounds/game-loop.mp3',
    fail: '/sounds/fail.mp3',
    win: '/sounds/win.mp3',
} as const;

type Slot = keyof typeof SOURCES;

/**
 * R10.3 — remembered per device, and every localStorage access is wrapped.
 * Safari in private mode throws on `setItem`, and an exception here would take
 * the whole play screen down over a mute button.
 */
function readMuted(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeMuted(value: boolean): void {
    try {
        localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
    } catch {
        // Nothing to do: the toggle still works for this session.
    }
}

const muted = ref(readMuted());

/** Created lazily, so a page that never plays a sound never fetches one. */
const elements = new Map<Slot, HTMLAudioElement>();

function element(slot: Slot): HTMLAudioElement | null {
    if (typeof Audio === 'undefined') return null;

    if (!elements.has(slot)) {
        const audio = new Audio(SOURCES[slot]);

        audio.preload = 'none';

        if (slot === 'gameLoop') {
            audio.loop = true;
            audio.volume = 0.35;
        } else {
            audio.volume = 0.6;
        }

        elements.set(slot, audio);
    }

    return elements.get(slot) ?? null;
}

function play(slot: Slot): void {
    if (muted.value) return;

    const audio = element(slot);

    if (!audio) return;

    try {
        audio.currentTime = 0;

        // A rejected promise is the normal outcome of a missing file or a
        // blocked autoplay. Neither is worth an unhandled rejection.
        void audio.play().catch(() => undefined);
    } catch {
        // Some browsers throw synchronously on currentTime before load.
    }
}

function stop(slot: Slot): void {
    const audio = elements.get(slot);

    if (!audio) return;

    try {
        audio.pause();
        audio.currentTime = 0;
    } catch {
        // Ignore.
    }
}

export function useSound() {
    const setMuted = (value: boolean): void => {
        muted.value = value;
        writeMuted(value);

        // Muting mid-run must silence the loop immediately, not at the end of
        // the question.
        if (value) {
            stop('gameLoop');
        }
    };

    return {
        muted: readonly(muted),
        toggleMuted: () => setMuted(!muted.value),
        setMuted,

        startLoop: () => play('gameLoop'),
        stopLoop: () => stop('gameLoop'),
        playFail: () => {
            stop('gameLoop');
            play('fail');
        },
        playWin: () => {
            stop('gameLoop');
            play('win');
        },
    };
}
