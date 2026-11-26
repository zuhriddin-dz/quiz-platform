<script setup lang="ts">
/**
 * R7.7 — logo and search on the left; Creator Studio and the avatar menu on
 * the right, or "Sign in with Google" for guests.
 *
 * R7.8 — Creator Studio is a sign-in prompt for guests, not a hidden link:
 * they should be able to see that creating quizzes exists.
 */
import { ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SoundToggle from '@/components/SoundToggle.vue';

const props = defineProps<{ search?: string | null }>();

const page = usePage();
const user = () => page.props.auth?.user ?? null;

const term = ref(props.search ?? '');
const menuOpen = ref(false);

// Keep the box in step when the server sends different filters back.
watch(
    () => props.search,
    (value) => {
        term.value = value ?? '';
    },
);

const submit = (): void => {
    // Search always lands on Explore, wherever it was typed. `q` is dropped
    // when empty so the URL stays clean and shareable.
    router.get('/', term.value.trim() === '' ? {} : { q: term.value.trim() }, {
        preserveState: false,
    });
};
</script>

<template>
    <header class="border-b">
        <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
            <Link href="/" class="shrink-0 text-base font-semibold tracking-tight">Quiz</Link>

            <form class="min-w-0 flex-1" role="search" @submit.prevent="submit">
                <label for="search" class="sr-only">Search quizzes</label>
                <input
                    id="search"
                    v-model="term"
                    type="search"
                    placeholder="Search quizzes"
                    maxlength="80"
                    class="w-full max-w-md rounded-md border px-3 py-1.5 text-sm"
                />
            </form>

            <nav class="flex shrink-0 items-center gap-4 text-sm">
                <!-- R10.3 — set it before a run; reaching for it mid-question
                     would cost you the run. -->
                <SoundToggle />

                <template v-if="user()">
                    <Link href="/studio" class="font-medium">Creator Studio</Link>

                    <div class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2"
                            :aria-expanded="menuOpen"
                            aria-haspopup="menu"
                            @click="menuOpen = !menuOpen"
                        >
                            <img
                                v-if="user()?.avatar_url"
                                :src="user()!.avatar_url!"
                                :alt="user()!.name"
                                class="size-8 rounded-full"
                            />
                            <span v-else class="size-8 rounded-full bg-neutral-200" />
                        </button>

                        <div
                            v-if="menuOpen"
                            role="menu"
                            class="absolute right-0 z-10 mt-2 w-44 rounded-md border bg-white py-1 shadow-lg"
                        >
                            <p class="truncate px-3 py-1.5 text-xs text-neutral-500">
                                {{ user()!.name }}
                            </p>

                            <Link href="/plays" role="menuitem" class="block px-3 py-1.5 hover:bg-neutral-50">
                                My plays
                            </Link>

                            <!-- POST, so a link cannot sign someone out. -->
                            <form method="POST" action="/logout">
                                <button
                                    type="submit"
                                    role="menuitem"
                                    class="block w-full px-3 py-1.5 text-left hover:bg-neutral-50"
                                >
                                    Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                </template>

                <template v-else>
                    <!-- R7.8 — visible to guests, and a sign-in prompt. -->
                    <a href="/auth/google/redirect" class="font-medium text-neutral-500">
                        Creator Studio
                    </a>

                    <a
                        href="/auth/google/redirect"
                        class="rounded-md border px-3 py-1.5 font-medium"
                    >
                        Sign in with Google
                    </a>
                </template>
            </nav>
        </div>
    </header>
</template>
