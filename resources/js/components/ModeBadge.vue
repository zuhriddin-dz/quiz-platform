<script setup lang="ts">
/**
 * R6.6 — the Strict / Casual badge, on quiz cards and on the play screen.
 *
 * It exists as a component rather than a span of text because the badge is
 * load-bearing: it is the only thing telling a player whether the run they
 * are about to start is refereed by the server or by their own browser, and
 * whether its result will be labelled self-reported.
 *
 * Phase 7 reuses it on the Explore cards.
 */
const props = defineProps<{
    mode: 'strict' | 'casual';
    label?: string;
    /** Adds the one-line explanation under the badge. */
    withHint?: boolean;
}>();

const text = props.label ?? (props.mode === 'strict' ? 'Strict' : 'Casual');

const hint =
    props.mode === 'strict'
        ? 'Timed by the server, one question at a time.'
        : 'Timed in your browser. Results are self-reported.';
</script>

<template>
    <span class="inline-flex flex-col gap-1">
        <span
            :class="
                mode === 'strict'
                    ? 'bg-indigo-100 text-indigo-800'
                    : 'bg-neutral-100 text-neutral-700'
            "
            class="inline-flex w-fit items-center rounded px-1.5 py-0.5 text-xs font-medium"
            :title="hint"
        >
            {{ text }}
        </span>

        <span v-if="withHint" class="text-xs text-neutral-500">{{ hint }}</span>
    </span>
</template>
