<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { IconPencil, IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

interface AccountRow {
    id: number;
    name: string;
    timezone: string;
    is_active: boolean;
    users_count: number;
    owners_count: number;
}

defineProps<{
    accounts: AccountRow[];
    canDelete: boolean;
    timezones: string[];
}>();

// New accounts start in the zone of whoever creates them.
const page = usePage<SharedData>();
const editing = ref<AccountRow | null>(null);
const form = useForm({ name: '', timezone: page.props.auth.user.timezone || 'UTC', is_active: true as boolean });

const edit = (account: AccountRow) => {
    editing.value = account;
    form.clearErrors();
    form.name = account.name;
    form.timezone = account.timezone;
    form.is_active = account.is_active;
};

const reset = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
};

const save = () => {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value) {
        form.patch(route('accounts.update', editing.value.id), options);
    } else {
        form.post(route('accounts.store'), options);
    }
};

const toggleActive = (account: AccountRow) => {
    router.patch(route('accounts.update', account.id), { name: account.name, is_active: !account.is_active }, { preserveScroll: true });
};

const destroy = (account: AccountRow) => {
    if (confirm(`Delete "${account.name}"? This permanently removes the account, its ${account.users_count} user(s) and all of its faxes.`)) {
        router.delete(route('accounts.destroy', account.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Accounts" />

    <AppLayout title="Accounts" pretitle="Each account is a separate business">
        <div class="row row-cards">
            <div class="col-12">
                <form class="card" @submit.prevent="save" novalidate>
                    <div class="card-header">
                        <h3 class="card-title">{{ editing ? `Edit ${editing.name}` : 'New account' }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <label class="form-label required" for="name">Name</label>
                                <input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.name }"
                                    placeholder="Business name, e.g. Acme Dental"
                                    required
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="col-lg-8">
                                <label class="form-label" for="timezone">Time zone</label>
                                <TimezoneSelect
                                    id="timezone"
                                    v-model="form.timezone"
                                    :timezones="timezones"
                                    :error="form.errors.timezone"
                                    hint="The date and time in the header of faxes this account sends are shown in this zone."
                                />
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="btn-list justify-content-end">
                            <button v-if="editing" type="button" class="btn" @click="reset">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                <IconPlus v-if="!editing" class="icon" />
                                {{ editing ? 'Save' : 'Create account' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Time zone</th>
                                    <th>Owners</th>
                                    <th>Users</th>
                                    <th>Status</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="account in accounts" :key="account.id" :class="{ 'bg-primary-lt': editing?.id === account.id }">
                                    <td>
                                        <Link :href="route('users.index', { account: account.id })" class="fw-medium">{{ account.name }}</Link>
                                    </td>
                                    <td class="text-secondary">{{ account.timezone.replace(/_/g, ' ') }}</td>
                                    <td>
                                        <span v-if="account.owners_count === 0" class="badge bg-red-lt">None</span>
                                        <template v-else>{{ account.owners_count }}</template>
                                    </td>
                                    <td class="text-secondary">{{ account.users_count }}</td>
                                    <td>
                                        <span v-if="account.is_active" class="badge bg-green-lt">Active</span>
                                        <span v-else class="badge bg-secondary-lt">Disabled</span>
                                    </td>
                                    <td>
                                        <div class="btn-list flex-nowrap">
                                            <Link :href="route('users.index', { account: account.id })" class="btn btn-sm">Users</Link>
                                            <button type="button" class="btn btn-sm" :aria-label="`Edit ${account.name}`" @click="edit(account)">
                                                <IconPencil class="icon" />
                                            </button>
                                            <button type="button" class="btn btn-sm" @click="toggleActive(account)">
                                                {{ account.is_active ? 'Disable' : 'Enable' }}
                                            </button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-outline-danger" @click="destroy(account)">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="accounts.length === 0">
                                    <td colspan="6" class="text-center text-secondary py-4">No accounts yet. Create the first one above.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
