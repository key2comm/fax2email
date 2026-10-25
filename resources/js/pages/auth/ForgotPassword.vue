<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout title="Forgot password" description="Enter your email address and we'll send you a link to reset your password.">
        <Head title="Forgot password" />

        <div v-if="status" class="alert alert-success" role="alert">{{ status }}</div>

        <form @submit.prevent="submit" novalidate>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.email }"
                    placeholder="you@business.com"
                    autocomplete="off"
                    required
                    autofocus
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    Email password reset link
                </button>
            </div>
        </form>

        <template #footer>
            <Link :href="route('login')">Back to log in</Link>
        </template>
    </AuthLayout>
</template>
