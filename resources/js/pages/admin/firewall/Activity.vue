<script setup lang="ts">
import FirewallLayout, { type FirewallStatus } from '@/layouts/admin/FirewallLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight, IconSearch } from '@tabler/icons-vue';
import { ref, watch } from 'vue';

interface EventRow {
    id: number;
    action: string;
    cidr: string;
    source: string;
    user: string | null;
    note: string | null;
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
    events: Paginated<EventRow>;
    search: string;
    status: FirewallStatus;
}>();

const search = ref(props.search);
let timer: ReturnType<typeof setTimeout>;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('admin.firewall.activity'), value ? { search: value } : {}, { preserveState: true, preserveScroll: true });
    }, 300);
});

const badges: Record<string, string> = {
    banned: 'bg-orange-lt',
    blocked: 'bg-red-lt',
    unbanned: 'bg-green-lt',
    unblocked: 'bg-green-lt',
    whitelisted: 'bg-blue-lt',
};

const { formatDateTime } = useDateTime();
</script>

<template>
    <Head title="Firewall: activity" />

    <FirewallLayout :status="status">
        <div class="card-body border-bottom py-3">
            <div class="input-icon" style="max-width: 20rem">
                <span class="input-icon-addon"><IconSearch class="icon" /></span>
                <input v-model="search" type="search" class="form-control" placeholder="Search by IP address" aria-label="Search by IP address" />
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Address</th>
                        <th>Action</th>
                        <th>Source</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="event in events.data" :key="event.id">
                        <td class="text-secondary text-nowrap">{{ formatDateTime(event.created_at) }}</td>
                        <td class="font-monospace">
                            <Link :href="route('admin.firewall.activity', { search: event.cidr })" class="text-reset">{{ event.cidr }}</Link>
                        </td>
                        <td>
                            <span class="badge" :class="badges[event.action] ?? 'bg-secondary-lt'">{{ event.action }}</span>
                        </td>
                        <td class="text-secondary">{{ event.source }}</td>
                        <td class="text-secondary">
                            {{ event.note || '' }}
                            <span v-if="event.user">by {{ event.user }}</span>
                        </td>
                    </tr>
                    <tr v-if="events.data.length === 0">
                        <td colspan="5" class="text-center text-secondary py-4">No firewall activity yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex align-items-center">
            <p class="m-0 text-secondary">
                <template v-if="events.total">Showing {{ events.from }}–{{ events.to }} of {{ events.total }}. </template>Kept for 180 days.
            </p>
            <ul class="pagination m-0 ms-auto">
                <li class="page-item" :class="{ disabled: !events.prev_page_url }">
                    <Link v-if="events.prev_page_url" :href="events.prev_page_url" class="page-link" preserve-scroll>
                        <IconChevronLeft class="icon" />
                        Previous
                    </Link>
                    <span v-else class="page-link"><IconChevronLeft class="icon" /> Previous</span>
                </li>
                <li class="page-item" :class="{ disabled: !events.next_page_url }">
                    <Link v-if="events.next_page_url" :href="events.next_page_url" class="page-link" preserve-scroll>
                        Next
                        <IconChevronRight class="icon" />
                    </Link>
                    <span v-else class="page-link">Next <IconChevronRight class="icon" /></span>
                </li>
            </ul>
        </div>
    </FirewallLayout>
</template>
