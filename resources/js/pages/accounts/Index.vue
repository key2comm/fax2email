<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';

interface AccountRow {
    id: number;
    name: string;
    is_active: boolean;
    users_count: number;
    owners_count: number;
}

defineProps<{
    accounts: AccountRow[];
    canDelete: boolean;
}>();

const form = useForm({ name: '' });

const create = () => {
    form.post(route('accounts.store'), { onSuccess: () => form.reset() });
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
                <form class="card" @submit.prevent="create" novalidate>
                    <div class="card-body">
                        <label class="form-label" for="name">New account</label>
                        <div class="row g-2">
                            <div class="col">
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
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                    <IconPlus class="icon" />
                                    Create account
                                </button>
                            </div>
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
                                    <th>Owners</th>
                                    <th>Users</th>
                                    <th>Status</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="account in accounts" :key="account.id">
                                    <td>
                                        <Link :href="route('users.index', { account: account.id })" class="fw-medium">{{ account.name }}</Link>
                                    </td>
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
                                    <td colspan="5" class="text-center text-secondary py-4">No accounts yet. Create the first one above.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
