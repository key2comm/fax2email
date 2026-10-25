<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    token: string;
    email: string;
}

const props = defineProps<Props>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout title="Reset password" description="Choose a new password for your account.">
        <Head title="Reset password" />

        <form @submit.prevent="submit" novalidate>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input id="email" v-model="form.email" type="email" class="form-control" readonly />
                <InputError :message="form.errors.email" />
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">New password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    autocomplete="new-password"
                    required
                    autofocus
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="mb-3">
                <label class="form-label" for="password_confirmation">Confirm new password</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password_confirmation }"
                    autocomplete="new-password"
                    required
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    Reset password
                </button>
            </div>
        </form>
    </AuthLayout>
</template>
