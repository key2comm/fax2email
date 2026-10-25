<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FirewallLayout, { type FirewallStatus } from '@/layouts/admin/FirewallLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface Settings {
    max_retry: number;
    find_time_minutes: number;
    ban_time_hours: number;
    recidive_max_retry: number;
    recidive_find_time_days: number;
    recidive_ban_time_days: number;
    // One "jail_<name>" switch per watched service.
    [key: string]: number | boolean;
}

const props = defineProps<{
    settings: Settings;
    jails: Record<string, string>;
    status: FirewallStatus;
}>();

const form = useForm<Settings>({ ...props.settings });

const jailHints: Record<string, string> = {
    f2e_sip: 'Failed SIP registrations and unknown users. Starts once FreeSWITCH is installed.',
    f2e_portal: 'Failed logins on this web portal.',
    f2e_ssh: 'Failed SSH logins to the server.',
};

const save = () => {
    form.put(route('admin.firewall.settings.update'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Firewall: settings" />

    <FirewallLayout :status="status">
        <form @submit.prevent="save" novalidate>
            <div class="card-body">
                <h3 class="card-title">Automatic bans (fail2ban)</h3>
                <p class="text-secondary">
                    Ban an address for
                    <strong>{{ form.ban_time_hours }} hour(s)</strong> after <strong>{{ form.max_retry }}</strong> failed attempts within
                    <strong>{{ form.find_time_minutes }} minute(s)</strong>.
                </p>

                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <label class="form-label" for="max_retry">Failed attempts</label>
                        <input id="max_retry" v-model.number="form.max_retry" type="number" min="1" max="100" class="form-control" />
                        <InputError :message="form.errors.max_retry" />
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="find_time_minutes">Within (minutes)</label>
                        <input id="find_time_minutes" v-model.number="form.find_time_minutes" type="number" min="1" max="1440" class="form-control" />
                        <InputError :message="form.errors.find_time_minutes" />
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="ban_time_hours">Ban for (hours)</label>
                        <input id="ban_time_hours" v-model.number="form.ban_time_hours" type="number" min="1" max="8760" class="form-control" />
                        <InputError :message="form.errors.ban_time_hours" />
                    </div>
                </div>

                <h3 class="card-title">Repeat offenders</h3>
                <p class="text-secondary">
                    An address banned <strong>{{ form.recidive_max_retry }}</strong> times within
                    <strong>{{ form.recidive_find_time_days }} day(s)</strong> is banned for
                    <strong>{{ form.recidive_ban_time_days }} day(s)</strong>.
                </p>

                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <label class="form-label" for="recidive_max_retry">Bans</label>
                        <input id="recidive_max_retry" v-model.number="form.recidive_max_retry" type="number" min="2" max="20" class="form-control" />
                        <InputError :message="form.errors.recidive_max_retry" />
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="recidive_find_time_days">Within (days)</label>
                        <input
                            id="recidive_find_time_days"
                            v-model.number="form.recidive_find_time_days"
                            type="number"
                            min="1"
                            max="30"
                            class="form-control"
                        />
                        <InputError :message="form.errors.recidive_find_time_days" />
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="recidive_ban_time_days">Ban for (days)</label>
                        <input
                            id="recidive_ban_time_days"
                            v-model.number="form.recidive_ban_time_days"
                            type="number"
                            min="1"
                            max="365"
                            class="form-control"
                        />
                        <InputError :message="form.errors.recidive_ban_time_days" />
                    </div>
                </div>

                <h3 class="card-title">Watch these services</h3>
                <div class="divide-y">
                    <label v-for="(label, jail) in jails" :key="jail" class="row py-2">
                        <span class="col">
                            {{ label }}
                            <span class="d-block small text-secondary">{{ jailHints[jail] }}</span>
                        </span>
                        <span class="col-auto">
                            <span class="form-check form-switch m-0">
                                <input v-model="form[`jail_${jail}`]" class="form-check-input" type="checkbox" />
                            </span>
                        </span>
                    </label>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Save</button>
            </div>
        </form>
    </FirewallLayout>
</template>
