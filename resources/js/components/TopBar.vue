<script setup lang="ts">
/**
 * R7.7 — logo and search on the left; Creator Studio and the avatar menu on
 * the right, or "Sign in with Google" for guests.
 *
 * R7.8 — Creator Studio is a sign-in prompt for guests, not a hidden link:
 * they should be able to see that creating quizzes exists.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import SoundToggle from '@/components/SoundToggle.vue';

const props = defineProps<{ search?: string | null }>();

const page = usePage();
const user = () => page.props.auth?.user ?? null;

const term = ref(props.search ?? '');
const menuOpen = ref(false);
const menuRoot = ref<HTMLElement | null>(null);

watch(
    () => props.search,
    (value) => {
        term.value = value ?? '';
    },
);

const submit = (): void => {
    router.get('/', term.value.trim() === '' ? {} : { q: term.value.trim() }, {
        preserveState: false,
    });
};

/* A menu that cannot be dismissed by clicking away or pressing Escape is a
   trap on touch, where there is no other obvious way out. */
const onDocumentClick = (event: MouseEvent): void => {
    if (menuOpen.value && !menuRoot.value?.contains(event.target as Node)) {
        menuOpen.value = false;
    }
};

const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') menuOpen.value = false;
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <header class="border-b border-border-subtle bg-bg/85 backdrop-blur-sm">
        <div class="mx-auto flex max-w-6xl items-center gap-5 px-6 py-3.5">
            <!-- The wordmark is serif, like the quiz titles and prompts: the
                 one place the identity is stated outright. -->
            <Link
                href="/"
                class="shrink-0 font-display text-xl leading-none tracking-tight"
                aria-label="Quiz — home"
            >
                Quiz<span class="text-accent-text">.</span>
            </Link>

            <form class="min-w-0 flex-1" role="search" @submit.prevent="submit">
                <label for="search" class="sr-only">Search quizzes</label>
                <input
                    id="search"
                    v-model="term"
                    type="search"
                    placeholder="Search quizzes"
                    maxlength="80"
                    class="w-full max-w-sm rounded-lg border border-border-subtle bg-surface px-3.5 py-2 text-sm placeholder:text-text-faint focus:border-border-strong"
                />
            </form>

            <nav class="flex shrink-0 items-center gap-4 text-sm">
                <!-- R10.3 — set it before a run; reaching for it mid-question
                     would cost you the run. -->
                <SoundToggle />

                <template v-if="user()">
                    <Link href="/studio" class="hidden font-medium hover:text-accent-text sm:block">
                        Creator Studio
                    </Link>

                    <div ref="menuRoot" class="relative">
                        <button
                            type="button"
                            class="flex items-center rounded-full"
                            :aria-expanded="menuOpen"
                            aria-haspopup="menu"
                            :aria-label="`Account menu for ${user()!.name}`"
                            @click="menuOpen = !menuOpen"
                        >
                            <img
                                v-if="user()?.avatar_url"
                                :src="user()!.avatar_url!"
                                alt=""
                                class="size-8 rounded-full ring-1 ring-border-subtle"
                            />
                            <span
                                v-else
                                class="size-8 rounded-full bg-surface-sunken ring-1 ring-border-subtle"
                            />
                        </button>

                        <div
                            v-if="menuOpen"
                            role="menu"
                            class="absolute right-0 z-20 mt-2 w-48 overflow-hidden rounded-xl border border-border-subtle bg-surface py-1 shadow-[var(--shadow-card)]"
                        >
                            <p class="truncate px-3.5 py-2 text-xs text-text-faint">
                                {{ user()!.name }}
                            </p>

                            <Link
                                href="/plays"
                                role="menuitem"
                                class="block px-3.5 py-2 hover:bg-surface-soft"
                            >
                                My plays
                            </Link>

                            <Link
                                href="/studio"
                                role="menuitem"
                                class="block px-3.5 py-2 hover:bg-surface-soft sm:hidden"
                            >
                                Creator Studio
                            </Link>

                            <!-- POST, so a link cannot sign someone out. -->
                            <form method="POST" action="/logout">
                                <button
                                    type="submit"
                                    role="menuitem"
                                    class="block w-full px-3.5 py-2 text-left hover:bg-surface-soft"
                                >
                                    Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                </template>

                <template v-else>
                    <!-- R7.8 — visible to guests, and a sign-in prompt. -->
                    <a
                        href="/auth/google/redirect"
                        class="hidden font-medium text-text-muted hover:text-accent-text sm:block"
                    >
                        Creator Studio
                    </a>

                    <a
                        href="/auth/google/redirect"
                        class="rounded-lg bg-accent px-3.5 py-2 font-medium text-on-accent transition-colors hover:bg-accent-hover"
                    >
                        Sign in
                    </a>
                </template>
            </nav>
        </div>
    </header>
</template>
