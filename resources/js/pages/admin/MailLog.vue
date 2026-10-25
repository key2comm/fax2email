<script setup lang="ts">
import SelectInput from '@/components/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import MailLayout from '@/layouts/admin/MailLayout.vue';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/vue3';
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

const statusLabels = { sending: 'Unconfirmed', sent: 'Sent', failed: 'Failed' };
const statusClasses = {
    sending: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    sent: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    failed: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
};

const formatDate = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="Mail log" />

    <MailLayout>
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2 sm:flex-row">
                <Input v-model="search" type="search" placeholder="Search recipient or subject" class="sm:max-w-xs" aria-label="Search" />
                <SelectInput v-model="status" class="sm:w-44" aria-label="Status">
                    <option value="">All statuses</option>
                    <option value="sent">Sent</option>
                    <option value="failed">Failed</option>
                    <option value="sending">Unconfirmed</option>
                </SelectInput>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="whitespace-nowrap px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">To</th>
                            <th class="px-4 py-2 font-medium">Subject</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="log in logs.data" :key="log.id">
                            <tr class="cursor-pointer border-b hover:bg-muted/40" @click="expanded = expanded === log.id ? null : log.id">
                                <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ formatDate(log.created_at) }}</td>
                                <td class="max-w-xs truncate px-4 py-2">{{ log.recipients }}</td>
                                <td class="max-w-sm truncate px-4 py-2">
                                    {{ log.subject || '(no subject)' }}
                                    <span v-if="log.attachments" class="text-muted-foreground">· {{ log.attachments }} attachment(s)</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span :class="cn('rounded-full px-2 py-0.5 text-xs font-medium', statusClasses[log.status])">
                                        {{ statusLabels[log.status] }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="expanded === log.id" class="border-b bg-muted/20">
                                <td colspan="4" class="px-4 py-3">
                                    <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[8rem_1fr]">
                                        <dt class="text-muted-foreground">From</dt>
                                        <dd class="break-all">{{ log.sender || '—' }}</dd>
                                        <dt class="text-muted-foreground">To</dt>
                                        <dd class="break-all">{{ log.recipients }}</dd>
                                        <template v-if="log.cc">
                                            <dt class="text-muted-foreground">Cc</dt>
                                            <dd class="break-all">{{ log.cc }}</dd>
                                        </template>
                                        <template v-if="log.bcc">
                                            <dt class="text-muted-foreground">Bcc</dt>
                                            <dd class="break-all">{{ log.bcc }}</dd>
                                        </template>
                                        <template v-if="log.message_id">
                                            <dt class="text-muted-foreground">Message ID</dt>
                                            <dd class="break-all font-mono text-xs">{{ log.message_id }}</dd>
                                        </template>
                                        <template v-if="log.error">
                                            <dt class="text-muted-foreground">Error</dt>
                                            <dd class="whitespace-pre-wrap break-all text-red-700 dark:text-red-400">{{ log.error }}</dd>
                                        </template>
                                        <template v-if="log.status === 'sending'">
                                            <dt class="text-muted-foreground">Note</dt>
                                            <dd>The send started but no result was recorded, usually because the process stopped mid-send.</dd>
                                        </template>
                                    </dl>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="logs.data.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-muted-foreground">No emails found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between text-sm text-muted-foreground">
                <span v-if="logs.total">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}. Entries are kept for {{ keepDays }} days.</span>
                <span v-else>Entries are kept for {{ keepDays }} days.</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="!logs.prev_page_url" as-child>
                        <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" preserve-scroll>Previous</Link>
                        <span v-else>Previous</span>
                    </Button>
                    <Button variant="outline" size="sm" :disabled="!logs.next_page_url" as-child>
                        <Link v-if="logs.next_page_url" :href="logs.next_page_url" preserve-scroll>Next</Link>
                        <span v-else>Next</span>
                    </Button>
                </div>
            </div>
        </div>
    </MailLayout>
</template>
