<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    token: string;
    email: string;
    role: string;
    accountName: string | null;
}

const props = defineProps<Props>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invitations.accept', props.token), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout title="Accept your invitation" :description="accountName ? `Join ${accountName} as ${role}.` : `Join as ${role}.`">
        <Head title="Accept invitation" />

        <form @submit.prevent="submit" novalidate>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input id="email" type="email" class="form-control" :value="email" readonly />
            </div>

            <div class="mb-3">
                <label class="form-label" for="name">Your name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.name }"
                    placeholder="Full name"
                    autocomplete="name"
                    required
                    autofocus
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.password }"
                    autocomplete="new-password"
                    required
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="mb-3">
                <label class="form-label" for="password_confirmation">Confirm password</label>
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
                    Create my login
                </button>
            </div>
        </form>
    </AuthLayout>
</template>
