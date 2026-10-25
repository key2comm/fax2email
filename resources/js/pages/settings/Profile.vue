<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail: boolean;
    status?: string;
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Profile" />

    <SettingsLayout>
        <form @submit.prevent="submit" novalidate>
            <div class="card-body">
                <h2 class="mb-1">Profile</h2>
                <p class="card-subtitle mb-4">Your name and the email address you log in with.</p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.name }"
                            autocomplete="name"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email address</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.email }"
                            autocomplete="username"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                </div>

                <div v-if="mustVerifyEmail && !user.email_verified_at" class="alert alert-warning mt-3 mb-0">
                    Your email address is unverified.
                    <Link :href="route('verification.send')" method="post" as="button" class="btn btn-link p-0 align-baseline">
                        Re-send the verification email.
                    </Link>
                    <div v-if="status === 'verification-link-sent'" class="mt-2 text-success">A new verification link has been sent.</div>
                </div>
            </div>
            <div class="card-footer bg-transparent mt-auto">
                <div class="btn-list justify-content-end">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Save</button>
                </div>
            </div>
        </form>
    </SettingsLayout>
</template>
