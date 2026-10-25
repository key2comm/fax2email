<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthLayout title="Log in to your account">
        <Head title="Log in" />

        <div v-if="status" class="alert alert-success" role="alert">{{ status }}</div>

        <form @submit.prevent="submit" autocomplete="off" novalidate>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.email }"
                    placeholder="you@business.com"
                    autocomplete="email"
                    required
                    autofocus
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="mb-2">
                <label class="form-label" for="password">
                    Password
                    <span v-if="canResetPassword" class="form-label-description">
                        <Link :href="route('password.request')">Forgot password?</Link>
                    </span>
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    placeholder="Your password"
                    autocomplete="current-password"
                    required
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="mb-2">
                <label class="form-check">
                    <input v-model="form.remember" type="checkbox" class="form-check-input" />
                    <span class="form-check-label">Remember me on this device</span>
                </label>
            </div>

            <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    Log in
                </button>
            </div>
        </form>

        <template #footer>Access is by invitation only.</template>
    </AuthLayout>
</template>
