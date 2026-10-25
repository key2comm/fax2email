<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { IconUserPlus } from '@tabler/icons-vue';
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

    <AppLayout :title="account ? account.name : 'All users'" pretitle="Users">
        <template v-if="isStaff" #actions>
            <select v-model="accountFilter" class="form-select" aria-label="Filter by account">
                <option value="">All users</option>
                <option v-for="option in accounts" :key="option.id" :value="option.id">{{ option.name }}</option>
            </select>
        </template>

        <div class="row row-cards">
            <div class="col-12">
                <form class="card" @submit.prevent="sendInvite" novalidate>
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Invite someone</h3>
                            <p class="card-subtitle">They'll get an email with a link to set their password.</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md">
                                <label class="form-label" for="invite-email">Email address</label>
                                <input
                                    id="invite-email"
                                    v-model="invite.email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': invite.errors.email }"
                                    placeholder="name@business.com"
                                    required
                                />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="invite-role">Role</label>
                                <select id="invite-role" v-model="invite.role" class="form-select">
                                    <option v-for="value in invitableRoles" :key="value" :value="value">{{ roleLabel(value) }}</option>
                                </select>
                            </div>
                            <div v-if="isStaff && !isStaffRole(invite.role)" class="col-md-3">
                                <label class="form-label" for="invite-account">Account</label>
                                <select
                                    id="invite-account"
                                    v-model="invite.account_id"
                                    class="form-select"
                                    :class="{ 'is-invalid': invite.errors.account_id }"
                                    required
                                >
                                    <option :value="null" disabled>Choose…</option>
                                    <option v-for="option in accounts" :key="option.id" :value="option.id">{{ option.name }}</option>
                                </select>
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-primary w-100" :disabled="invite.processing">
                                    <IconUserPlus class="icon" />
                                    Send invite
                                </button>
                            </div>
                        </div>
                        <InputError :message="invite.errors.email || invite.errors.role || invite.errors.account_id" />
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
                                    <th>Email</th>
                                    <th v-if="isStaff && !account">Account</th>
                                    <th>Role</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in users" :key="user.id">
                                    <td class="fw-medium">{{ user.name }}</td>
                                    <td class="text-secondary">{{ user.email }}</td>
                                    <td v-if="isStaff && !account" class="text-secondary">{{ user.account?.name ?? 'Staff' }}</td>
                                    <td>
                                        <select
                                            v-if="user.assignable_roles.length > 1"
                                            :value="user.role"
                                            class="form-select form-select-sm w-auto"
                                            :aria-label="`Role for ${user.name}`"
                                            @change="changeRole(user, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option v-for="value in user.assignable_roles" :key="value" :value="value">{{ roleLabel(value) }}</option>
                                        </select>
                                        <span v-else>{{ roleLabel(user.role) }}</span>
                                    </td>
                                    <td>
                                        <button v-if="user.can_delete" type="button" class="btn btn-sm btn-outline-danger" @click="removeUser(user)">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="users.length === 0">
                                    <td colspan="5" class="text-center text-secondary py-4">No users yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="invitations.length" class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Pending invitations</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <tbody>
                                <tr v-for="invitation in invitations" :key="invitation.id">
                                    <td>{{ invitation.email }}</td>
                                    <td>{{ roleLabel(invitation.role) }}</td>
                                    <td v-if="isStaff" class="text-secondary">{{ invitation.account?.name ?? 'Staff' }}</td>
                                    <td class="text-secondary">Expires {{ new Date(invitation.expires_at).toLocaleDateString() }}</td>
                                    <td class="w-1">
                                        <button v-if="invitation.can_revoke" type="button" class="btn btn-sm" @click="revoke(invitation)">
                                            Revoke
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
