<script setup lang="ts">
import MailLayout from '@/layouts/admin/MailLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-vue';

interface EmailRow {
    id: number;
    from_address: string | null;
    recipient: string | null;
    subject: string | null;
    client_ip: string | null;
    spf: string | null;
    dkim_domain: string | null;
    status: 'accepted' | 'rejected';
    reason: string | null;
    fax_id: number | null;
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

defineProps<{
    emails: Paginated<EmailRow>;
    domain: string | null;
    keepDays: number;
}>();

const { formatDateTime } = useDateTime();
</script>

<template>
    <Head title="Email to fax" />

    <MailLayout>
        <div class="card-body border-bottom">
            <p v-if="domain" class="mb-0">
                Users fax by emailing PDFs to <span class="font-monospace">&lt;number&gt;@{{ domain }}</span> from their login email address. Only
                senders that pass SPF or DKIM for their From domain are accepted; anything else is dropped without a reply.
            </p>
            <div v-else class="alert alert-warning mb-0">
                Email to fax is off. Run the installer again with <span class="font-monospace">--fax-email-domain fax.yourdomain.com</span> to turn it
                on, then point that domain's MX record at this server.
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Received</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Checks</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="email in emails.data" :key="email.id">
                        <td class="text-secondary text-nowrap">{{ formatDateTime(email.created_at) }}</td>
                        <td>
                            <div class="text-break">{{ email.from_address || '—' }}</div>
                            <div v-if="email.subject" class="small text-secondary text-truncate" style="max-width: 18rem">{{ email.subject }}</div>
                        </td>
                        <td class="font-monospace small text-break">{{ email.recipient }}</td>
                        <td class="small">
                            <div>
                                SPF: <span :class="email.spf === 'pass' ? 'text-green' : 'text-secondary'">{{ email.spf || '—' }}</span>
                            </div>
                            <div>
                                DKIM:
                                <span :class="email.dkim_domain ? 'text-green' : 'text-secondary'">{{
                                    email.dkim_domain ? `pass (${email.dkim_domain})` : 'none'
                                }}</span>
                            </div>
                            <div v-if="email.client_ip" class="text-secondary font-monospace">{{ email.client_ip }}</div>
                        </td>
                        <td>
                            <span class="badge" :class="email.status === 'accepted' ? 'bg-green-lt' : 'bg-red-lt'">
                                {{ email.status === 'accepted' ? 'Faxed' : 'Rejected' }}
                            </span>
                            <div v-if="email.reason" class="small text-secondary">{{ email.reason }}</div>
                        </td>
                    </tr>
                    <tr v-if="emails.data.length === 0">
                        <td colspan="5" class="text-center text-secondary py-4">No fax emails received yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex align-items-center">
            <p class="m-0 text-secondary">
                <template v-if="emails.total">Showing {{ emails.from }}–{{ emails.to }} of {{ emails.total }}. </template>Kept for {{ keepDays }}
                days.
            </p>
            <ul class="pagination m-0 ms-auto">
                <li class="page-item" :class="{ disabled: !emails.prev_page_url }">
                    <Link v-if="emails.prev_page_url" :href="emails.prev_page_url" class="page-link" preserve-scroll>
                        <IconChevronLeft class="icon" />
                        Previous
                    </Link>
                    <span v-else class="page-link"><IconChevronLeft class="icon" /> Previous</span>
                </li>
                <li class="page-item" :class="{ disabled: !emails.next_page_url }">
                    <Link v-if="emails.next_page_url" :href="emails.next_page_url" class="page-link" preserve-scroll>
                        Next
                        <IconChevronRight class="icon" />
                    </Link>
                    <span v-else class="page-link">Next <IconChevronRight class="icon" /></span>
                </li>
            </ul>
        </div>
    </MailLayout>
</template>
