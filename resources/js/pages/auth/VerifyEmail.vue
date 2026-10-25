<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout title="Verify your email" description="Please verify your email address by clicking the link we just emailed to you.">
        <Head title="Email verification" />

        <div v-if="status === 'verification-link-sent'" class="alert alert-success" role="alert">
            A new verification link has been sent to your email address.
        </div>

        <form @submit.prevent="submit">
            <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                Resend verification email
            </button>
        </form>

        <template #footer>
            <Link :href="route('logout')" method="post" as="button" class="btn btn-link">Log out</Link>
        </template>
    </AuthLayout>
</template>
