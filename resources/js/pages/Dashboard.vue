<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { IconCircleCheck, IconHash, IconInbox } from '@tabler/icons-vue';

type Status = 'received' | 'delivered' | 'queued' | 'sending' | 'sent' | 'failed';

interface RecentFax {
    id: number;
    from: string | null;
    to: string | null;
    pages: number;
    status: Status;
    has_pdf: boolean;
    created_at: string;
}

defineProps<{
    stats: { received: number; delivered: number; numbers: number };
    recent: RecentFax[];
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;

const statuses: Record<Status, { label: string; badge: string }> = {
    received: { label: 'Processing', badge: 'bg-yellow-lt' },
    delivered: { label: 'Delivered', badge: 'bg-green-lt' },
    queued: { label: 'Queued', badge: 'bg-azure-lt' },
    sending: { label: 'Sending…', badge: 'bg-yellow-lt' },
    sent: { label: 'Sent', badge: 'bg-green-lt' },
    failed: { label: 'Failed', badge: 'bg-red-lt' },
};

const { formatDateTime } = useDateTime();
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :title="`Welcome, ${user.name}`" :pretitle="user.account ? user.account.name : 'Staff portal'">
        <div class="row row-deck row-cards">
            <div class="col-sm-4">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-primary text-white avatar"><IconInbox class="icon" /></span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">{{ stats.received }} faxes received</div>
                                <div class="text-secondary">Last 30 days</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-green text-white avatar"><IconCircleCheck class="icon" /></span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">{{ stats.delivered }} delivered by email</div>
                                <div class="text-secondary">Last 30 days</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-azure text-white avatar"><IconHash class="icon" /></span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">{{ stats.numbers }} fax {{ stats.numbers === 1 ? 'number' : 'numbers' }}</div>
                                <div class="text-secondary">Receiving faxes</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Latest faxes</h3>
                        <div v-if="page.props.auth.can.viewFaxes && recent.length" class="card-actions">
                            <Link :href="route('faxes.index')">View all</Link>
                        </div>
                    </div>
                    <div v-if="recent.length === 0" class="card-body">
                        <div class="empty">
                            <div class="empty-icon"><IconInbox class="icon" /></div>
                            <p class="empty-title">No faxes yet</p>
                            <p class="empty-subtitle text-secondary">Faxes sent to your numbers will appear here.</p>
                        </div>
                    </div>
                    <div v-else class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <tbody>
                                <tr v-for="fax in recent" :key="fax.id">
                                    <td class="text-secondary text-nowrap">{{ formatDateTime(fax.created_at) }}</td>
                                    <td class="font-monospace">{{ fax.from }}</td>
                                    <td class="font-monospace text-secondary">→ {{ fax.to }}</td>
                                    <td>{{ fax.pages }} {{ fax.pages === 1 ? 'page' : 'pages' }}</td>
                                    <td>
                                        <span class="badge" :class="statuses[fax.status].badge">{{ statuses[fax.status].label }}</span>
                                    </td>
                                    <td class="w-1">
                                        <a v-if="fax.has_pdf" :href="route('faxes.download', fax.id)" class="btn btn-sm">PDF</a>
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
