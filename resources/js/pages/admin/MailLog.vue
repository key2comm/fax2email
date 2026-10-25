<script setup lang="ts">
import MailLayout from '@/layouts/admin/MailLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight, IconSearch } from '@tabler/icons-vue';
import { ref, watch } from 'vue';

interface MailLogRow {
    id: number;
    sender: string | null;
    recipients: string;
    cc: string | null;
    bcc: string | null;
    subject: string | null;
    attachments: number;
    status: 'sending' | 'sent' | 'failed';
    error: string | null;
    message_id: string | null;
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
    logs: Paginated<MailLogRow>;
    filters: { search: string; status: string };
    keepDays: number;
}>();

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const expanded = ref<number | null>(null);

const applyFilters = () => {
    const query = Object.fromEntries(Object.entries({ search: search.value, status: status.value }).filter(([, value]) => value));
    router.get(route('admin.mail.log'), query, { preserveState: true, preserveScroll: true });
};

watch(status, applyFilters);

let searchTimer: ReturnType<typeof setTimeout>;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});

const statuses = {
    sending: { label: 'Unconfirmed', badge: 'bg-yellow-lt' },
    sent: { label: 'Sent', badge: 'bg-green-lt' },
    failed: { label: 'Failed', badge: 'bg-red-lt' },
};

const formatDate = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="Mail log" />

    <MailLayout>
        <div class="card-body border-bottom py-3">
            <div class="row g-2">
                <div class="col-sm">
                    <div class="input-icon">
                        <span class="input-icon-addon"><IconSearch class="icon" /></span>
                        <input v-model="search" type="search" class="form-control" placeholder="Search recipient or subject" aria-label="Search" />
                    </div>
                </div>
                <div class="col-sm-auto">
                    <select v-model="status" class="form-select" aria-label="Status">
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="failed">Failed</option>
                        <option value="sending">Unconfirmed</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-vcenter card-table table-hover table-clickable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="log in logs.data" :key="log.id">
                        <tr :aria-expanded="expanded === log.id" @click="expanded = expanded === log.id ? null : log.id">
                            <td class="text-secondary text-nowrap">{{ formatDate(log.created_at) }}</td>
                            <td class="text-truncate" style="max-width: 16rem">{{ log.recipients }}</td>
                            <td class="text-truncate" style="max-width: 24rem">
                                {{ log.subject || '(no subject)' }}
                                <span v-if="log.attachments" class="text-secondary">· {{ log.attachments }} attachment(s)</span>
                            </td>
                            <td>
                                <span class="badge" :class="statuses[log.status].badge">{{ statuses[log.status].label }}</span>
                            </td>
                        </tr>
                        <tr v-if="expanded === log.id">
                            <td colspan="4" class="bg-body-tertiary">
                                <dl class="row mb-0 mail-log-detail">
                                    <dt class="col-sm-2">From</dt>
                                    <dd class="col-sm-10 text-break">{{ log.sender || '—' }}</dd>
                                    <dt class="col-sm-2">To</dt>
                                    <dd class="col-sm-10 text-break">{{ log.recipients }}</dd>
                                    <template v-if="log.cc">
                                        <dt class="col-sm-2">Cc</dt>
                                        <dd class="col-sm-10 text-break">{{ log.cc }}</dd>
                                    </template>
                                    <template v-if="log.bcc">
                                        <dt class="col-sm-2">Bcc</dt>
                                        <dd class="col-sm-10 text-break">{{ log.bcc }}</dd>
                                    </template>
                                    <template v-if="log.message_id">
                                        <dt class="col-sm-2">Message ID</dt>
                                        <dd class="col-sm-10 text-break font-monospace small">{{ log.message_id }}</dd>
                                    </template>
                                    <template v-if="log.error">
                                        <dt class="col-sm-2">Error</dt>
                                        <dd class="col-sm-10 text-break text-danger" style="white-space: pre-wrap">{{ log.error }}</dd>
                                    </template>
                                    <template v-if="log.status === 'sending'">
                                        <dt class="col-sm-2">Note</dt>
                                        <dd class="col-sm-10">
                                            The send started but no result was recorded, usually because the process stopped mid-send.
                                        </dd>
                                    </template>
                                </dl>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="logs.data.length === 0">
                        <td colspan="4" class="text-center text-secondary py-4">No emails found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex align-items-center">
            <p class="m-0 text-secondary">
                <template v-if="logs.total">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}. </template>Entries are kept for
                {{ keepDays }} days.
            </p>
            <ul class="pagination m-0 ms-auto">
                <li class="page-item" :class="{ disabled: !logs.prev_page_url }">
                    <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" class="page-link" preserve-scroll>
                        <IconChevronLeft class="icon" />
                        Previous
                    </Link>
                    <span v-else class="page-link"><IconChevronLeft class="icon" /> Previous</span>
                </li>
                <li class="page-item" :class="{ disabled: !logs.next_page_url }">
                    <Link v-if="logs.next_page_url" :href="logs.next_page_url" class="page-link" preserve-scroll>
                        Next
                        <IconChevronRight class="icon" />
                    </Link>
                    <span v-else class="page-link">Next <IconChevronRight class="icon" /></span>
                </li>
            </ul>
        </div>
    </MailLayout>
</template>
