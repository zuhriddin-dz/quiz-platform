<script setup lang="ts">
/**
 * R5.9 — the two option layouts.
 *
 * Desktop (hover: hover AND pointer: fine): W centred on top, A / S / D in a
 * row beneath, key hints drawn on the options.
 * Touch: a plain 2x2 grid, no hotkeys and no key hints.
 *
 * There are no other hotkeys at all — no 1-4, no A-D labels. The keys map to
 * DISPLAY positions, so they mean the same thing visually every run even
 * though the options behind them are shuffled.
 *
 * Colours are semantic tokens, so this component renders correctly on the dark
 * play surface without knowing the surface is dark.
 */
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    options: string[];
    hasKeyboardLayout: boolean;
    disabled: boolean;
    /** Highlighted on the fail screen. */
    wrongIndex?: number | null;
}>();

const emit = defineEmits<{ (event: 'choose', displayedIndex: number): void }>();

/** Display position -> key. Index 0 is W, the one on top. */
const KEYS = ['w', 'a', 's', 'd'] as const;

const choose = (index: number): void => {
    if (props.disabled) return;

    emit('choose', index);
};

const onKeydown = (event: KeyboardEvent): void => {
    if (!props.hasKeyboardLayout || props.disabled) return;

    // Ignore modified presses: cmd+W is "close tab", not "answer".
    if (event.metaKey || event.ctrlKey || event.altKey) return;

    const index = KEYS.indexOf(event.key.toLowerCase() as (typeof KEYS)[number]);

    if (index === -1 || index >= props.options.length) return;

    event.preventDefault();
    choose(index);
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

/*
 * The picked-wrong option is marked by border, tint AND an explicit label, so
 * the state is never carried by colour alone.
 */
const optionClass = (index: number): string =>
    props.wrongIndex === index
        ? 'border-danger-border text-danger-text'
        : 'border-border-subtle hover:border-border-strong';
</script>

<template>
    <!-- Desktop: W on top, A/S/D beneath -->
    <div v-if="hasKeyboardLayout" class="space-y-3">
        <div class="flex justify-center">
            <button
                type="button"
                :disabled="disabled"
                :class="optionClass(0)"
                class="relative w-full max-w-md rounded-xl border border-border-subtle bg-surface px-5 py-5 text-start text-lg transition-colors disabled:opacity-70"
                @click="choose(0)"
            >
                <kbd
                    class="absolute start-3 top-3 rounded border border-border-subtle px-1.5 text-2xs font-semibold text-text-faint"
                >
                    W
                </kbd>
                <span class="block ps-9">{{ options[0] }}</span>
                <span v-if="wrongIndex === 0" class="sr-only">— your answer, incorrect</span>
            </button>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <button
                v-for="index in [1, 2, 3]"
                :key="index"
                type="button"
                :disabled="disabled"
                :class="optionClass(index)"
                class="relative rounded-xl border border-border-subtle bg-surface px-5 py-5 text-start text-lg transition-colors disabled:opacity-70"
                @click="choose(index)"
            >
                <kbd
                    class="absolute start-3 top-3 rounded border border-border-subtle px-1.5 text-2xs font-semibold text-text-faint"
                >
                    {{ KEYS[index].toUpperCase() }}
                </kbd>
                <span class="block ps-9">{{ options[index] }}</span>
                <span v-if="wrongIndex === index" class="sr-only">— your answer, incorrect</span>
            </button>
        </div>
    </div>

    <!-- Touch: 2x2, no hints, targets sized for a thumb -->
    <div v-else class="grid grid-cols-2 gap-3">
        <button
            v-for="(option, index) in options"
            :key="index"
            type="button"
            :disabled="disabled"
            :class="optionClass(index)"
            class="min-h-24 rounded-xl border border-border-subtle bg-surface px-4 py-5 text-start text-lg transition-colors disabled:opacity-70"
            @click="choose(index)"
        >
            {{ option }}
            <span v-if="wrongIndex === index" class="sr-only">— your answer, incorrect</span>
        </button>
    </div>
</template>
