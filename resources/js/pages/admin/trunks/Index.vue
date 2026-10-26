<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconPencil, IconPlugConnected, IconPlus, IconTrash } from '@tabler/icons-vue';

interface TrunkRow {
    id: number;
    name: string;
    enabled: boolean;
    auth_type: 'ip' | 'credentials';
    transport: 'udp' | 'tcp' | 'tls';
    host: string;
    port: number;
    username: string | null;
    addresses: string[];
    resolve_error: string | null;
    status: string | null;
    status_detail: string | null;
    status_checked_at: string | null;
}

defineProps<{
    trunks: TrunkRow[];
}>();

const statuses: Record<string, { label: string; badge: string }> = {
    registered: { label: 'Registered', badge: 'bg-green-lt' },
    reachable: { label: 'Reachable', badge: 'bg-green-lt' },
    trying: { label: 'Registering…', badge: 'bg-yellow-lt' },
    failed: { label: 'Registration failed', badge: 'bg-red-lt' },
    unreachable: { label: 'Not responding', badge: 'bg-red-lt' },
    disabled: { label: 'Disabled', badge: 'bg-secondary-lt' },
    unknown: { label: 'Unknown', badge: 'bg-secondary-lt' },
};

const destroy = (trunk: TrunkRow) => {
    if (confirm(`Delete the trunk "${trunk.name}"? Its addresses are removed from the whitelist, so faxes from this provider will be refused.`)) {
        router.delete(route('admin.trunks.destroy', trunk.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="SIP trunks" />

    <AppLayout title="SIP trunks" pretitle="Administration">
        <template #actions>
            <Link :href="route('admin.trunks.create')" class="btn btn-primary">
                <IconPlus class="icon" />
                Add trunk
            </Link>
        </template>

        <div class="card">
            <div v-if="trunks.length === 0" class="card-body">
                <div class="empty">
                    <div class="empty-icon"><IconPlugConnected class="icon" /></div>
                    <p class="empty-title">No SIP trunks yet</p>
                    <p class="empty-subtitle text-secondary">
                        Add the SIP provider that delivers your fax numbers. Its addresses are whitelisted automatically, and only trunk addresses can
                        reach the SIP ports.
                    </p>
                    <div class="empty-action">
                        <Link :href="route('admin.trunks.create')" class="btn btn-primary">
                            <IconPlus class="icon" />
                            Add your first trunk
                        </Link>
                    </div>
                </div>
            </div>

            <div v-else class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Authentication</th>
                            <th>Provider</th>
                            <th>Whitelisted addresses</th>
                            <th>Status</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="trunk in trunks" :key="trunk.id">
                            <td class="fw-medium">{{ trunk.name }}</td>
                            <td>
                                {{ trunk.auth_type === 'ip' ? 'IP address' : 'Username/password' }}
                                <div v-if="trunk.username" class="small text-secondary">{{ trunk.username }}</div>
                            </td>
                            <td class="font-monospace small">{{ trunk.transport.toUpperCase() }} {{ trunk.host }}:{{ trunk.port }}</td>
                            <td class="font-monospace small">
                                <div v-for="address in trunk.addresses" :key="address">{{ address }}</div>
                                <div v-if="trunk.resolve_error" class="text-danger font-sans">{{ trunk.resolve_error }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="statuses[trunk.status ?? 'unknown']?.badge ?? 'bg-secondary-lt'">
                                    {{ statuses[trunk.status ?? 'unknown']?.label ?? trunk.status }}
                                </span>
                                <div v-if="trunk.status_detail" class="small text-secondary mt-1">{{ trunk.status_detail }}</div>
                            </td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <Link :href="route('admin.trunks.edit', trunk.id)" class="btn btn-sm" :aria-label="`Edit ${trunk.name}`">
                                        <IconPencil class="icon" />
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        :aria-label="`Delete ${trunk.name}`"
                                        @click="destroy(trunk)"
                                    >
                                        <IconTrash class="icon" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="trunks.length > 0" class="card-footer small text-secondary">
                Trunk status is checked every 60 seconds, so a new or changed trunk can show as Unknown for a minute or two. Reload this page to see
                the latest status.
            </div>
        </div>
    </AppLayout>
</template>
