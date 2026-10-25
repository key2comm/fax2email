<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconHash, IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';

interface NumberRow {
    id: number;
    number: string;
    formatted: string;
    label: string | null;
    enabled: boolean;
    account: { id: number; name: string };
    recipients: string[];
    faxes_30d: number;
    can_edit: boolean;
    can_delete: boolean;
}

defineProps<{
    numbers: NumberRow[];
    canCreate: boolean;
}>();

const destroy = (number: NumberRow) => {
    if (confirm(`Delete ${number.formatted}? Faxes sent to it will be refused. Faxes already received are kept.`)) {
        router.delete(route('numbers.destroy', number.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Fax numbers" />

    <AppLayout title="Fax numbers" pretitle="Where received faxes are delivered">
        <template v-if="canCreate" #actions>
            <Link :href="route('numbers.create')" class="btn btn-primary">
                <IconPlus class="icon" />
                Add number
            </Link>
        </template>

        <div class="card">
            <div v-if="numbers.length === 0" class="card-body">
                <div class="empty">
                    <div class="empty-icon"><IconHash class="icon" /></div>
                    <p class="empty-title">No fax numbers yet</p>
                    <p class="empty-subtitle text-secondary">
                        {{
                            canCreate
                                ? 'Add the numbers your SIP providers deliver, and assign each one to an account.'
                                : 'Ask your provider to add a fax number to your account.'
                        }}
                    </p>
                </div>
            </div>

            <div v-else class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Account</th>
                            <th>Emailed to</th>
                            <th>Faxes (30 days)</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="number in numbers" :key="number.id">
                            <td>
                                <div class="font-monospace fw-medium">{{ number.formatted }}</div>
                                <div v-if="number.label" class="small text-secondary">{{ number.label }}</div>
                                <span v-if="!number.enabled" class="badge bg-secondary-lt">Disabled</span>
                            </td>
                            <td class="text-secondary">{{ number.account.name }}</td>
                            <td class="small">
                                <div v-for="email in number.recipients" :key="email">{{ email }}</div>
                                <span v-if="number.recipients.length === 0" class="text-secondary">The account's owners</span>
                            </td>
                            <td>{{ number.faxes_30d }}</td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <Link v-if="number.can_edit" :href="route('numbers.edit', number.id)" class="btn btn-sm">
                                        <IconPencil class="icon" />
                                        Edit
                                    </Link>
                                    <button
                                        v-if="number.can_delete"
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        :aria-label="`Delete ${number.formatted}`"
                                        @click="destroy(number)"
                                    >
                                        <IconTrash class="icon" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
