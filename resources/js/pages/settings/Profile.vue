<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    mustVerifyEmail: boolean;
    status?: string;
    timezones: string[];
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    timezone: user.timezone || 'UTC',
});

// The zone this browser is in, offered as a one-click choice.
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const zoneFilter = ref('');
const zones = computed(() => {
    const term = zoneFilter.value.trim().toLowerCase().replace(/\s+/g, '_');
    const list = term ? props.timezones.filter((zone) => zone.toLowerCase().includes(term)) : props.timezones;

    return list.includes(form.timezone) ? list : [form.timezone, ...list];
});
const now = (zone: string) => new Intl.DateTimeFormat(undefined, { timeZone: zone, timeStyle: 'short' }).format(new Date());

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
                    <div class="col-12">
                        <label class="form-label" for="timezone">Time zone</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input
                                    v-model="zoneFilter"
                                    type="search"
                                    class="form-control"
                                    placeholder="Search, e.g. Toronto"
                                    aria-label="Search time zones"
                                />
                            </div>
                            <div class="col-md-8">
                                <select id="timezone" v-model="form.timezone" class="form-select" :class="{ 'is-invalid': form.errors.timezone }">
                                    <option v-for="zone in zones" :key="zone" :value="zone">{{ zone.replace(/_/g, ' ') }}</option>
                                </select>
                            </div>
                        </div>
                        <InputError :message="form.errors.timezone" />
                        <small class="form-hint mt-1">
                            Dates and times in the portal are shown in this zone. It is {{ now(form.timezone) }} there now.
                            <button
                                v-if="browserZone && browserZone !== form.timezone"
                                type="button"
                                class="btn btn-link p-0 align-baseline"
                                @click="form.timezone = browserZone"
                            >
                                Use this computer's time zone ({{ browserZone.replace(/_/g, ' ') }})
                            </button>
                        </small>
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
