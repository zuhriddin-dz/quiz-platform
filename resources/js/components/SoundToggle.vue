<script setup lang="ts">
/**
 * R10.3 — the mute toggle, remembered per device.
 *
 * It lives in the top bar rather than on the play screen, so it can be set
 * before a run starts — reaching for it mid-question would cost you the run.
 */
import { useSound } from '@/composables/useSound';

const { muted, toggleMuted } = useSound();
</script>

<template>
    <button
        type="button"
        class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100"
        :aria-pressed="muted"
        :title="muted ? 'Unmute sound' : 'Mute sound'"
        @click="toggleMuted"
    >
        <span class="sr-only">{{ muted ? 'Unmute sound' : 'Mute sound' }}</span>

        <!-- Speaker, crossed out when muted. -->
        <svg class="size-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path
                d="M4 8h2l3-3v10l-3-3H4z"
                fill="currentColor"
                stroke="currentColor"
                stroke-width="1.2"
                stroke-linejoin="round"
            />
            <template v-if="muted">
                <path d="M12 8l4 4m0-4l-4 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            </template>
            <template v-else>
                <path d="M12.5 7.5a3.5 3.5 0 010 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                <path d="M14.5 5.5a6.5 6.5 0 010 9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            </template>
        </svg>
    </button>
</template>
