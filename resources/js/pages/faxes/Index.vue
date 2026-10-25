<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight, IconDownload, IconInbox, IconMailForward, IconSend } from '@tabler/icons-vue';
import { computed, reactive, watch } from 'vue';

type Status = 'received' | 'delivered' | 'queued' | 'sending' | 'sent' | 'failed';

interface FaxRow {
    id: number;
    status: Status;
    caller_number: string | null;
    caller_name: string | null;
    remote_station_id: string | null;
    to: string | null;
    to_label: string | null;
    from: string | null;
    sent_by: string | null;
    source: string | null;
    attempts: number;
    next_attempt_at: string | null;
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
    direction: 'inbound' | 'outbound';
    filters: { status: string; number: string | number; account: string | number };
    numbers: { id: number; label: string }[];
    accounts: { id: number; name: string }[];
    keepDays: number;
}>();

const page = usePage<SharedData>();
const outbound = computed(() => props.direction === 'outbound');
const filters = reactive({ ...props.filters });

watch(filters, () => {
    const query = Object.fromEntries(
        Object.entries({ ...filters, direction: props.direction }).filter(([, value]) => value !== '' && value !== null),
    );
    router.get(route('faxes.index'), query, { preserveState: true, preserveScroll: true });
});

const statuses: Record<Status, { label: string; badge: string }> = {
    received: { label: 'Processing', badge: 'bg-yellow-lt' },
    delivered: { label: 'Delivered', badge: 'bg-green-lt' },
    queued: { label: 'Queued', badge: 'bg-azure-lt' },
    sending: { label: 'Sending…', badge: 'bg-yellow-lt' },
    sent: { label: 'Sent', badge: 'bg-green-lt' },
    failed: { label: 'Failed', badge: 'bg-red-lt' },
};

const statusOptions = computed<Status[]>(() => (outbound.value ? ['queued', 'sending', 'sent', 'failed'] : ['delivered', 'received', 'failed']));

const resend = (fax: FaxRow) => {
    router.post(route('faxes.resend', fax.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="outbound ? 'Sent faxes' : 'Received faxes'" />

    <AppLayout title="Faxes" :pretitle="page.props.auth.user.account?.name ?? 'All accounts'">
        <template v-if="page.props.auth.can.sendFaxes" #actions>
            <Link :href="route('faxes.send')" class="btn btn-primary">
                <IconSend class="icon" />
                Send a fax
            </Link>
        </template>

        <div v-if="page.props.errors.fax" class="alert alert-danger" role="alert">{{ page.props.errors.fax }}</div>

        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item">
                        <Link :href="route('faxes.index')" class="nav-link" :class="{ active: !outbound }">Received</Link>
                    </li>
                    <li class="nav-item">
                        <Link :href="route('faxes.index', { direction: 'outbound' })" class="nav-link" :class="{ active: outbound }">Sent</Link>
                    </li>
                </ul>
            </div>

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
                            <option v-for="status in statusOptions" :key="status" :value="status">{{ statuses[status].label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div v-if="faxes.data.length === 0" class="card-body">
                <div class="empty">
                    <div class="empty-icon">
                        <IconSend v-if="outbound" class="icon" />
                        <IconInbox v-else class="icon" />
                    </div>
                    <p class="empty-title">{{ outbound ? 'No faxes sent yet' : 'No faxes yet' }}</p>
                    <p class="empty-subtitle text-secondary">
                        {{
                            outbound
                                ? 'Faxes you send from the portal or by email appear here.'
                                : 'Faxes sent to your numbers will appear here and be emailed as PDFs.'
                        }}
                    </p>
                </div>
            </div>

            <div v-else class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>{{ outbound ? 'Sent' : 'Received' }}</th>
                            <th>{{ outbound ? 'To' : 'From' }}</th>
                            <th>{{ outbound ? 'From' : 'To' }}</th>
                            <th v-if="accounts.length">Account</th>
                            <th>Pages</th>
                            <th>Status</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fax in faxes.data" :key="fax.id">
                            <td class="text-secondary text-nowrap">
                                {{ new Date(fax.created_at).toLocaleString() }}
                                <div v-if="outbound && fax.sent_by" class="small">
                                    by {{ fax.sent_by }}{{ fax.source === 'email' ? ' (email)' : '' }}
                                </div>
                            </td>
                            <template v-if="outbound">
                                <td>
                                    <div class="font-monospace">{{ fax.to }}</div>
                                    <div v-if="fax.to_label" class="small text-secondary">{{ fax.to_label }}</div>
                                    <div v-if="fax.remote_station_id" class="small text-secondary">{{ fax.remote_station_id }}</div>
                                </td>
                                <td class="font-monospace text-secondary">{{ fax.from }}</td>
                            </template>
                            <template v-else>
                                <td>
                                    <div class="font-monospace">{{ fax.caller_number || 'Unknown' }}</div>
                                    <div v-if="fax.remote_station_id" class="small text-secondary">{{ fax.remote_station_id }}</div>
                                </td>
                                <td>
                                    <div class="font-monospace">{{ fax.to }}</div>
                                    <div v-if="fax.to_label" class="small text-secondary">{{ fax.to_label }}</div>
                                </td>
                            </template>
                            <td v-if="accounts.length" class="text-secondary">{{ fax.account }}</td>
                            <td>{{ fax.pages || '—' }}</td>
                            <td>
                                <span class="badge" :class="statuses[fax.status].badge">{{ statuses[fax.status].label }}</span>
                                <div v-if="outbound && fax.attempts > 0 && fax.status !== 'sent'" class="small text-secondary">
                                    Attempt {{ fax.attempts
                                    }}<template v-if="fax.next_attempt_at"
                                        >, retrying {{ new Date(fax.next_attempt_at).toLocaleTimeString() }}</template
                                    >
                                </div>
                                <div v-if="!outbound && fax.emailed_at" class="small text-secondary">
                                    Emailed {{ new Date(fax.emailed_at).toLocaleString() }}
                                </div>
                                <div v-if="fax.email_error" class="small text-danger">{{ fax.email_error }}</div>
                                <div
                                    v-if="(fax.status === 'failed' || (outbound && fax.status === 'queued')) && fax.result_text"
                                    class="small text-secondary"
                                >
                                    {{ fax.result_text }}
                                </div>
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
