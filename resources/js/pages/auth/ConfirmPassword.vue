<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <AuthLayout title="Confirm your password" description="This is a secure area. Please confirm your password before continuing.">
        <Head title="Confirm password" />

        <form @submit.prevent="submit" novalidate>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    autocomplete="current-password"
                    required
                    autofocus
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    Confirm password
                </button>
            </div>
        </form>
    </AuthLayout>
</template>
