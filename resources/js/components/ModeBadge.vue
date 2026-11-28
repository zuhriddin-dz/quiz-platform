<script setup lang="ts">
/**
 * R6.6 — the Strict / Casual badge, on quiz cards and on the play screen.
 *
 * It exists as a component rather than a span of text because the badge is
 * load-bearing: it is the only thing telling a player whether the run they are
 * about to start is refereed by the server or by their own browser, and
 * whether its result will be labelled self-reported.
 *
 * The two modes differ by WORD first and treatment second — a filled dot for
 * strict, a hollow one for casual — so the distinction survives greyscale,
 * colour-blindness and the dark play surface.
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
            class="inline-flex w-fit items-center gap-1.5 rounded-lg border border-border-subtle px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-text-muted"
            :title="hint"
        >
            <span
                aria-hidden="true"
                class="size-1.5 rounded-full"
                :class="mode === 'strict' ? 'bg-accent' : 'border border-current'"
            />
            {{ text }}
        </span>

        <span v-if="withHint" class="text-xs text-text-faint">{{ hint }}</span>
    </span>
</template>
