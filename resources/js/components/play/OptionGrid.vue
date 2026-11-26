<script setup lang="ts">
/**
 * R5.9 — the two option layouts.
 *
 * Desktop (hover: hover AND pointer: fine):
 *     W is one option centred on top, A / S / D in a row beneath, key hints
 *     drawn on the options.
 *
 * Touch:
 *     a plain 2x2 grid, no hotkeys and no key hints.
 *
 * There are no other hotkeys at all — no 1-4, no A-D labels. The keys map to
 * DISPLAY positions, so they mean the same thing visually every run even
 * though the options behind them are shuffled.
 *
 * `disabled` is true during "checking…" so a second click cannot land, though
 * the state machine also refuses one.
 */
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{
    options: string[];
    hasKeyboardLayout: boolean;
    disabled: boolean;
    /** Highlighted red on the fail screen. */
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
</script>

<template>
    <!-- Desktop: W on top, A/S/D beneath -->
    <div v-if="hasKeyboardLayout" class="space-y-3">
        <div class="flex justify-center">
            <button
                type="button"
                :disabled="disabled"
                :class="wrongIndex === 0 ? 'border-red-500 bg-red-50' : 'hover:bg-neutral-50'"
                class="relative w-full max-w-sm rounded-lg border px-4 py-5 text-left disabled:opacity-60"
                @click="choose(0)"
            >
                <kbd class="absolute left-3 top-3 rounded bg-neutral-100 px-1.5 text-xs font-medium">W</kbd>
                <span class="block pl-8">{{ options[0] }}</span>
            </button>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <button
                v-for="index in [1, 2, 3]"
                :key="index"
                type="button"
                :disabled="disabled"
                :class="wrongIndex === index ? 'border-red-500 bg-red-50' : 'hover:bg-neutral-50'"
                class="relative rounded-lg border px-4 py-5 text-left disabled:opacity-60"
                @click="choose(index)"
            >
                <kbd class="absolute left-3 top-3 rounded bg-neutral-100 px-1.5 text-xs font-medium">
                    {{ KEYS[index].toUpperCase() }}
                </kbd>
                <span class="block pl-8">{{ options[index] }}</span>
            </button>
        </div>
    </div>

    <!-- Touch: 2x2, no hints -->
    <div v-else class="grid grid-cols-2 gap-3">
        <button
            v-for="(option, index) in options"
            :key="index"
            type="button"
            :disabled="disabled"
            :class="wrongIndex === index ? 'border-red-500 bg-red-50' : ''"
            class="rounded-lg border px-4 py-6 text-left disabled:opacity-60"
            @click="choose(index)"
        >
            {{ option }}
        </button>
    </div>
</template>
