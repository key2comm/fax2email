<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight, IconDownload, IconInbox, IconMailForward } from '@tabler/icons-vue';
import { reactive, watch } from 'vue';

interface FaxRow {
    id: number;
    status: 'received' | 'delivered' | 'failed';
    caller_number: string | null;
    caller_name: string | null;
    remote_station_id: string | null;
    to: string | null;
    to_label: string | null;
    account: string | null;
    pages: number;
    result_text: string | null;
    emailed_at: string | null;
    email_error: string | null;
    has_pdf: boolean;
    can_resend: boolean;
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    faxes: Paginated<FaxRow>;
    filters: { status: string; number: string | number; account: string | number };
    numbers: { id: number; label: string }[];
    accounts: { id: number; name: string }[];
    keepDays: number;
}>();

const page = usePage<SharedData>();
const filters = reactive({ ...props.filters });

watch(filters, () => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));
    router.get(route('faxes.index'), query, { preserveState: true, preserveScroll: true });
});

const statuses = {
    received: { label: 'Processing', badge: 'bg-yellow-lt' },
    delivered: { label: 'Delivered', badge: 'bg-green-lt' },
    failed: { label: 'Failed', badge: 'bg-red-lt' },
};

const resend = (fax: FaxRow) => {
    router.post(route('faxes.resend', fax.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Faxes" />

    <AppLayout title="Received faxes" :pretitle="page.props.auth.user.account?.name ?? 'All accounts'">
        <div v-if="page.props.errors.fax" class="alert alert-danger" role="alert">{{ page.props.errors.fax }}</div>

        <div class="card">
            <div class="card-body border-bottom py-3">
                <div class="row g-2">
                    <div v-if="accounts.length" class="col-sm-auto">
                        <select v-model="filters.account" class="form-select" aria-label="Account">
                            <option value="">All accounts</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                        </select>
                    </div>
                    <div class="col-sm-auto">
                        <select v-model="filters.number" class="form-select" aria-label="Fax number">
                            <option value="">All numbers</option>
                            <option v-for="number in numbers" :key="number.id" :value="number.id">{{ number.label }}</option>
                        </select>
                    </div>
                    <div class="col-sm-auto">
                        <select v-model="filters.status" class="form-select" aria-label="Status">
                            <option value="">All statuses</option>
                            <option value="delivered">Delivered</option>
                            <option value="received">Processing</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                </div>
            </div>

            <div v-if="faxes.data.length === 0" class="card-body">
                <div class="empty">
                    <div class="empty-icon"><IconInbox class="icon" /></div>
                    <p class="empty-title">No faxes yet</p>
                    <p class="empty-subtitle text-secondary">Faxes sent to your numbers will appear here and be emailed as PDFs.</p>
                </div>
            </div>

            <div v-else class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Received</th>
                            <th>From</th>
                            <th>To</th>
                            <th v-if="accounts.length">Account</th>
                            <th>Pages</th>
                            <th>Status</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fax in faxes.data" :key="fax.id">
                            <td class="text-secondary text-nowrap">{{ new Date(fax.created_at).toLocaleString() }}</td>
                            <td>
                                <div class="font-monospace">{{ fax.caller_number || 'Unknown' }}</div>
                                <div v-if="fax.remote_station_id" class="small text-secondary">{{ fax.remote_station_id }}</div>
                            </td>
                            <td>
                                <div class="font-monospace">{{ fax.to }}</div>
                                <div v-if="fax.to_label" class="small text-secondary">{{ fax.to_label }}</div>
                            </td>
                            <td v-if="accounts.length" class="text-secondary">{{ fax.account }}</td>
                            <td>{{ fax.pages }}</td>
                            <td>
                                <span class="badge" :class="statuses[fax.status].badge">{{ statuses[fax.status].label }}</span>
                                <div v-if="fax.emailed_at" class="small text-secondary">Emailed {{ new Date(fax.emailed_at).toLocaleString() }}</div>
                                <div v-if="fax.email_error" class="small text-danger">{{ fax.email_error }}</div>
                                <div v-if="fax.status === 'failed' && fax.result_text" class="small text-secondary">{{ fax.result_text }}</div>
                            </td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <a v-if="fax.has_pdf" :href="route('faxes.download', fax.id)" class="btn btn-sm">
                                        <IconDownload class="icon" />
                                        PDF
                                    </a>
                                    <button
                                        v-if="fax.can_resend && fax.status !== 'failed'"
                                        type="button"
                                        class="btn btn-sm"
                                        title="Email this fax again"
                                        @click="resend(fax)"
                                    >
                                        <IconMailForward class="icon" />
                                        Resend
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card-footer d-flex align-items-center">
                <p class="m-0 text-secondary">
                    <template v-if="faxes.total">Showing {{ faxes.from }}–{{ faxes.to }} of {{ faxes.total }}. </template>Faxes are kept for
                    {{ keepDays }} days.
                </p>
                <ul class="pagination m-0 ms-auto">
                    <li class="page-item" :class="{ disabled: !faxes.prev_page_url }">
                        <Link v-if="faxes.prev_page_url" :href="faxes.prev_page_url" class="page-link" preserve-scroll>
                            <IconChevronLeft class="icon" />
                            Previous
                        </Link>
                        <span v-else class="page-link"><IconChevronLeft class="icon" /> Previous</span>
                    </li>
                    <li class="page-item" :class="{ disabled: !faxes.next_page_url }">
                        <Link v-if="faxes.next_page_url" :href="faxes.next_page_url" class="page-link" preserve-scroll>
                            Next
                            <IconChevronRight class="icon" />
                        </Link>
                        <span v-else class="page-link">Next <IconChevronRight class="icon" /></span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
