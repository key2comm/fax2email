<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SelectInput from '@/components/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface AccountRef {
    id: number;
    name: string;
}

interface RoleOption {
    value: string;
    label: string;
    is_staff: boolean;
}

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: string;
    account: AccountRef | null;
    assignable_roles: string[];
    can_delete: boolean;
}

interface InvitationRow {
    id: number;
    email: string;
    role: string;
    account: AccountRef | null;
    expires_at: string;
    can_revoke: boolean;
}

const props = defineProps<{
    users: UserRow[];
    invitations: InvitationRow[];
    account: AccountRef | null;
    accounts: AccountRef[];
    roles: RoleOption[];
    invitableRoles: string[];
}>();

const page = usePage<SharedData>();
const isStaff = computed(() => ['admin', 'provisioner'].includes(page.props.auth.user.role));

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Users', href: '/users' },
    ...(props.account ? [{ title: props.account.name, href: `/users?account=${props.account.id}` }] : []),
]);

const roleLabel = (value: string) => props.roles.find((role) => role.value === value)?.label ?? value;
const isStaffRole = (value: string) => props.roles.find((role) => role.value === value)?.is_staff ?? false;

const accountFilter = ref<number | string>(props.account?.id ?? '');
watch(accountFilter, (value) => {
    router.get(route('users.index'), value ? { account: value } : {}, { preserveState: true });
});

const invite = useForm({
    email: '',
    role: props.invitableRoles.includes('account_user') ? 'account_user' : (props.invitableRoles[0] ?? ''),
    account_id: (props.account?.id ?? null) as number | null,
});

const sendInvite = () => {
    invite
        .transform((data) => ({ ...data, account_id: isStaffRole(data.role) ? null : data.account_id }))
        .post(route('invitations.store'), { preserveScroll: true, onSuccess: () => invite.reset('email') });
};

const changeRole = (user: UserRow, role: string) => {
    router.patch(route('users.role', user.id), { role }, { preserveScroll: true });
};

const removeUser = (user: UserRow) => {
    if (confirm(`Remove ${user.name} (${user.email})? They will no longer be able to log in.`)) {
        router.delete(route('users.destroy', user.id), { preserveScroll: true });
    }
};

const revoke = (invitation: InvitationRow) => {
    router.delete(route('invitations.destroy', invitation.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-8 p-4">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <Heading
                    :title="account ? `${account.name} users` : 'All users'"
                    description="Invite people, change their role, or remove their access."
                />
                <div v-if="isStaff" class="grid w-full gap-2 sm:w-64">
                    <Label for="account-filter">Account</Label>
                    <SelectInput id="account-filter" v-model="accountFilter">
                        <option value="">All users</option>
                        <option v-for="option in accounts" :key="option.id" :value="option.id">{{ option.name }}</option>
                    </SelectInput>
                </div>
            </div>

            <section class="max-w-3xl space-y-4">
                <HeadingSmall title="Invite someone" description="They'll get an email with a link to set their password." />
                <form @submit.prevent="sendInvite" class="grid gap-4 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email</Label>
                        <Input id="invite-email" type="email" v-model="invite.email" required placeholder="name@business.com" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="invite-role">Role</Label>
                        <SelectInput id="invite-role" v-model="invite.role">
                            <option v-for="value in invitableRoles" :key="value" :value="value">{{ roleLabel(value) }}</option>
                        </SelectInput>
                    </div>
                    <div v-if="isStaff && !isStaffRole(invite.role)" class="grid gap-2">
                        <Label for="invite-account">Account</Label>
                        <SelectInput id="invite-account" v-model="invite.account_id" required>
                            <option :value="null" disabled>Choose…</option>
                            <option v-for="option in accounts" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </SelectInput>
                    </div>
                    <Button :disabled="invite.processing">Send invite</Button>
                </form>
                <InputError :message="invite.errors.email || invite.errors.role || invite.errors.account_id" />
            </section>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Email</th>
                            <th v-if="isStaff && !account" class="px-4 py-2 font-medium">Account</th>
                            <th class="px-4 py-2 font-medium">Role</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id" class="border-b last:border-0">
                            <td class="px-4 py-2 font-medium">{{ user.name }}</td>
                            <td class="px-4 py-2">{{ user.email }}</td>
                            <td v-if="isStaff && !account" class="px-4 py-2 text-muted-foreground">{{ user.account?.name ?? 'Staff' }}</td>
                            <td class="px-4 py-2">
                                <SelectInput
                                    v-if="user.assignable_roles.length > 1"
                                    :model-value="user.role"
                                    class="w-40"
                                    @update:model-value="changeRole(user, $event as string)"
                                >
                                    <option v-for="value in user.assignable_roles" :key="value" :value="value">{{ roleLabel(value) }}</option>
                                </SelectInput>
                                <span v-else>{{ roleLabel(user.role) }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Button v-if="user.can_delete" variant="destructive" size="sm" @click="removeUser(user)">Remove</Button>
                            </td>
                        </tr>
                        <tr v-if="users.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">No users yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <section v-if="invitations.length" class="space-y-4">
                <HeadingSmall title="Pending invitations" />
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <tbody>
                            <tr v-for="invitation in invitations" :key="invitation.id" class="border-b last:border-0">
                                <td class="px-4 py-2">{{ invitation.email }}</td>
                                <td class="px-4 py-2">{{ roleLabel(invitation.role) }}</td>
                                <td v-if="isStaff" class="px-4 py-2 text-muted-foreground">{{ invitation.account?.name ?? 'Staff' }}</td>
                                <td class="px-4 py-2 text-muted-foreground">Expires {{ new Date(invitation.expires_at).toLocaleDateString() }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Button v-if="invitation.can_revoke" variant="outline" size="sm" @click="revoke(invitation)">Revoke</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
