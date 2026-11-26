<script setup lang="ts">
/**
 * TEMPORARY placeholder. Phase 7 replaces this with Explore (R7.1) and the
 * real top bar and sidebar (R7.7).
 *
 * It exists so Phase 1's Google sign-in can actually be clicked through in a
 * browser, and so the shared `auth.user` prop is visible.
 */
import { Head } from '@inertiajs/vue3';

defineProps<{
    // Shared by HandleInertiaRequests. Note there is no email here, by design.
    auth?: { user: { id: number; name: string; avatar_url: string | null } | null };
}>();
</script>

<template>
    <Head title="Quiz" />

    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-6">
        <h1 class="text-2xl font-semibold tracking-tight">Quiz</h1>

        <template v-if="auth?.user">
            <div class="flex items-center gap-3">
                <img
                    v-if="auth.user.avatar_url"
                    :src="auth.user.avatar_url"
                    :alt="auth.user.name"
                    class="size-10 rounded-full"
                />
                <span class="font-medium">{{ auth.user.name }}</span>
            </div>

            <form method="POST" action="/logout">
                <button type="submit" class="text-sm underline">Sign out</button>
            </form>

            <p class="text-sm text-neutral-500">
                Phase 1 only. Explore, the Creator Studio and gameplay come later.
            </p>
        </template>

        <template v-else>
            <a
                href="/auth/google/redirect"
                class="rounded-md border px-4 py-2 text-center text-sm font-medium"
            >
                Sign in with Google
            </a>

            <p class="text-sm text-neutral-500">
                Signing in is only needed to create quizzes and to vote. Playing never requires it.
            </p>
        </template>
    </main>
</template>
