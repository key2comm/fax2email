<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: (errors) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }

            if (errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <Head title="Password" />

    <SettingsLayout>
        <form @submit.prevent="updatePassword" novalidate>
            <div class="card-body">
                <h2 class="mb-1">Password</h2>
                <p class="card-subtitle mb-4">Use a long, random password that you don't use anywhere else.</p>

                <div class="row g-3" style="max-width: 32rem">
                    <div class="col-12">
                        <label class="form-label" for="current_password">Current password</label>
                        <input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.current_password }"
                            autocomplete="current-password"
                        />
                        <InputError :message="form.errors.current_password" />
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="password">New password</label>
                        <input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.password }"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="password_confirmation">Confirm new password</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.password_confirmation }"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent mt-auto">
                <div class="btn-list justify-content-end">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Update password</button>
                </div>
            </div>
        </form>
    </SettingsLayout>
</template>
