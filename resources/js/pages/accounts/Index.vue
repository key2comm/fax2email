<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accounts', href: '/accounts' }];

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

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <Heading title="Accounts" description="Each account is a separate business with its own users and fax numbers." />

            <form @submit.prevent="create" class="flex max-w-xl flex-col gap-2 sm:flex-row sm:items-end">
                <div class="grid flex-1 gap-2">
                    <Label for="name">New account name</Label>
                    <Input id="name" v-model="form.name" required placeholder="e.g. Acme Dental" />
                    <InputError :message="form.errors.name" />
                </div>
                <Button :disabled="form.processing">Create account</Button>
            </form>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Owners</th>
                            <th class="px-4 py-2 font-medium">Users</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="account in accounts" :key="account.id" class="border-b last:border-0">
                            <td class="px-4 py-2 font-medium">
                                <Link :href="route('users.index', { account: account.id })" class="hover:underline">{{ account.name }}</Link>
                            </td>
                            <td class="px-4 py-2" :class="{ 'text-red-600': account.owners_count === 0 }">{{ account.owners_count }}</td>
                            <td class="px-4 py-2">{{ account.users_count }}</td>
                            <td class="px-4 py-2">{{ account.is_active ? 'Active' : 'Disabled' }}</td>
                            <td class="space-x-2 whitespace-nowrap px-4 py-2 text-right">
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="route('users.index', { account: account.id })">Users</Link>
                                </Button>
                                <Button variant="outline" size="sm" @click="toggleActive(account)">
                                    {{ account.is_active ? 'Disable' : 'Enable' }}
                                </Button>
                                <Button v-if="canDelete" variant="destructive" size="sm" @click="destroy(account)">Delete</Button>
                            </td>
                        </tr>
                        <tr v-if="accounts.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">No accounts yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
