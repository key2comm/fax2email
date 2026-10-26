<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { Link, usePage } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCircleCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

export interface FirewallStatus {
    applied_at: string | null;
    error: string | null;
    fail2ban: boolean | null;
}

const props = defineProps<{
    status: FirewallStatus;
}>();

const tabs = [
    { title: 'Blocked', href: '/admin/firewall' },
    { title: 'Whitelist', href: '/admin/firewall/whitelist' },
    { title: 'Settings', href: '/admin/firewall/settings' },
    { title: 'Activity', href: '/admin/firewall/activity' },
];

const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
const healthy = computed(() => props.status.applied_at !== null && !props.status.error && props.status.fail2ban !== false);

const { formatDateTime } = useDateTime();
</script>

<template>
    <AppLayout title="Firewall" pretitle="Administration">
        <div class="alert mb-3" :class="healthy ? 'alert-success' : 'alert-warning'" role="status">
            <div class="d-flex gap-2">
                <IconCircleCheck v-if="healthy" class="icon alert-icon" />
                <IconAlertTriangle v-else class="icon alert-icon" />
                <div>
                    <template v-if="status.applied_at === null">
                        The firewall service has not applied any rules yet. It is installed by the installer and runs as root.
                    </template>
                    <template v-else>
                        Rules last applied {{ formatDateTime(status.applied_at) }}.
                        <span v-if="status.fail2ban === false">fail2ban is not running, so automatic bans are paused.</span>
                    </template>
                    <div v-if="status.error" class="mt-1 text-danger">Last error: {{ status.error }}</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs">
                    <li v-for="tab in tabs" :key="tab.href" class="nav-item">
                        <Link :href="tab.href" class="nav-link" :class="{ active: currentPath === tab.href }">{{ tab.title }}</Link>
                    </li>
                </ul>
            </div>
            <slot />
        </div>
    </AppLayout>
</template>
