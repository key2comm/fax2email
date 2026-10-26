<script setup lang="ts">
import MailLayout from '@/layouts/admin/MailLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCircleCheck, IconCircleX, IconCopy } from '@tabler/icons-vue';
import { ref } from 'vue';

interface DnsRecord {
    type: string;
    name: string;
    value: string;
    priority: number | null;
}

interface CheckItem {
    key: string;
    label: string;
    status: 'ok' | 'warn' | 'fail';
    detail: string;
}

defineProps<{
    domain: string | null;
    setup: { server_ips: string[]; mail_host: string; subdomain: boolean; records: DnsRecord[] } | null;
    results: { checked_at: string; items: CheckItem[] } | null;
}>();

const { formatDateTime } = useDateTime();
const checking = ref(false);
const copied = ref('');

const check = () => {
    checking.value = true;
    router.post(route('admin.mail.inbound-mx.check'), {}, { preserveScroll: true, onFinish: () => (checking.value = false) });
};

const copy = (text: string) => {
    navigator.clipboard?.writeText(text).then(() => {
        copied.value = text;
        setTimeout(() => (copied.value = ''), 1500);
    });
};
</script>

<template>
    <Head title="Inbound MX" />

    <MailLayout>
        <div v-if="!domain" class="card-body">
            <div class="alert alert-warning mb-0">
                Email to fax is off, so there are no MX records to set up. Run the installer again and enter a fax domain (for example
                <span class="font-monospace">fax.yourdomain.com</span>) when it asks. You can use the same one-command install you used to set up the
                server.
            </div>
        </div>

        <template v-else-if="setup">
            <div class="card-body border-bottom">
                <h3 class="card-title">DNS records for {{ domain }}</h3>
                <p class="text-secondary">
                    People fax by emailing PDFs to <span class="font-monospace">&lt;number&gt;@{{ domain }}</span
                    >. For that email to reach this server, create these records at the company that hosts your domain's DNS:
                </p>

                <div class="table-responsive">
                    <table class="table table-vcenter card-table border">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Name (host)</th>
                                <th>Value (points to)</th>
                                <th>Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in setup.records" :key="record.type">
                                <td>
                                    <span class="badge bg-blue-lt">{{ record.type }}</span>
                                </td>
                                <td class="font-monospace">
                                    {{ record.name }}
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-ghost-secondary btn-icon"
                                        aria-label="Copy"
                                        @click="copy(record.name)"
                                    >
                                        <IconCopy class="icon" />
                                    </button>
                                </td>
                                <td class="font-monospace">
                                    {{ record.value }}
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-ghost-secondary btn-icon"
                                        aria-label="Copy"
                                        @click="copy(record.value)"
                                    >
                                        <IconCopy class="icon" />
                                    </button>
                                </td>
                                <td>{{ record.priority ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <small v-if="copied" class="text-green">Copied {{ copied }}</small>

                <ul class="text-secondary mt-3 mb-0">
                    <li>
                        Many DNS providers want only the part before your main domain in the <strong>Name</strong> field. For
                        <span class="font-monospace">fax.yourdomain.com</span> enter <span class="font-monospace">fax</span>; for the main domain
                        itself enter <span class="font-monospace">@</span>.
                    </li>
                    <li>An MX record must point to a hostname, never to an IP address. The hostname needs its own A record.</li>
                    <li v-if="setup.mail_host !== domain">
                        The MX points to <span class="font-monospace">{{ setup.mail_host }}</span
                        >, the portal's address, which already points at this server, so no extra A record is needed.
                    </li>
                    <li v-if="setup.server_ips.length === 0">
                        This server has no public IP address of its own (it is probably behind NAT). Use the public IP that forwards to it.
                    </li>
                    <li>Changes can take from a few minutes to an hour to show up.</li>
                </ul>
            </div>

            <div class="card-body border-bottom">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h4>Using a subdomain (recommended)</h4>
                        <p class="text-secondary mb-0">
                            For example <span class="font-monospace">fax.yourdomain.com</span>. The records go in the DNS for
                            <span class="font-monospace">yourdomain.com</span>, and the company's normal email at
                            <span class="font-monospace">@yourdomain.com</span> keeps working exactly as before.
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h4>Using a whole domain</h4>
                        <p class="text-secondary mb-0">
                            For example <span class="font-monospace">yourcompanyfax.com</span>, used only for faxing. The MX record is for the domain
                            itself (Name <span class="font-monospace">@</span>). <strong>Don't use a domain that already receives email</strong>:
                            changing its MX records would send all of its email to this server instead.
                        </p>
                    </div>
                </div>
                <div v-if="!setup.subdomain" class="alert alert-warning mt-3 mb-0">
                    {{ domain }} looks like a whole domain. Make sure no one receives email at it, or switch to a subdomain such as
                    <span class="font-monospace">fax.{{ domain }}</span
                    >.
                </div>
            </div>

            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <button type="button" class="btn btn-primary" :disabled="checking" @click="check">
                        <span v-if="checking" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        Check DNS
                    </button>
                    <span class="text-secondary">
                        Checks the MX records, where they point, and whether this server's mail service answers for {{ domain }}.
                    </span>
                </div>

                <div v-if="results" class="list-group">
                    <div v-for="item in results.items" :key="item.key" class="list-group-item d-flex gap-3">
                        <IconCircleCheck v-if="item.status === 'ok'" class="icon text-green flex-shrink-0" />
                        <IconAlertTriangle v-else-if="item.status === 'warn'" class="icon text-yellow flex-shrink-0" />
                        <IconCircleX v-else class="icon text-red flex-shrink-0" />
                        <div>
                            <div class="fw-medium">{{ item.label }}</div>
                            <div class="text-secondary">{{ item.detail }}</div>
                        </div>
                    </div>
                    <div class="list-group-item small text-secondary">Checked {{ formatDateTime(results.checked_at) }}</div>
                </div>
            </div>
        </template>
    </MailLayout>
</template>
