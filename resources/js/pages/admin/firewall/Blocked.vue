<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FirewallLayout, { type FirewallStatus } from '@/layouts/admin/FirewallLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconBan } from '@tabler/icons-vue';

interface RuleRow {
    id: number;
    cidr: string;
    source: string;
    is_fail2ban: boolean;
    note: string | null;
    created_by: string | null;
    created_at: string;
    expires_at: string | null;
    unban_requested: boolean;
    bans_30d: number;
}

defineProps<{
    rules: RuleRow[];
    durations: string[];
    status: FirewallStatus;
    yourIp: string;
}>();

const durationLabels: Record<string, string> = {
    permanent: 'Permanently',
    '1h': 'For 1 hour',
    '1d': 'For 1 day',
    '7d': 'For 7 days',
    '30d': 'For 30 days',
};

const form = useForm({ type: 'block', cidr: '', duration: 'permanent', note: '' });

const block = () => {
    form.post(route('admin.firewall.rules.store'), { preserveScroll: true, onSuccess: () => form.reset('cidr', 'note') });
};

const unblock = (rule: RuleRow) => {
    const message = rule.is_fail2ban
        ? `Lift the fail2ban ban on ${rule.cidr}? It will be banned again if it keeps failing.`
        : `Unblock ${rule.cidr}?`;
    if (confirm(message)) {
        router.delete(route('admin.firewall.rules.destroy', rule.id), { preserveScroll: true });
    }
};

const { formatDateTime } = useDateTime();
</script>

<template>
    <Head title="Firewall: blocked" />

    <FirewallLayout :status="status">
        <form class="card-body border-bottom" @submit.prevent="block" novalidate>
            <label class="form-label" for="cidr">Block an IP address or range</label>
            <div class="row g-2">
                <div class="col-md-4">
                    <input
                        id="cidr"
                        v-model="form.cidr"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.cidr }"
                        placeholder="203.0.113.7 or 203.0.113.0/24"
                        required
                    />
                </div>
                <div class="col-md-2">
                    <select v-model="form.duration" class="form-select" aria-label="Duration">
                        <option v-for="duration in durations" :key="duration" :value="duration">{{ durationLabels[duration] }}</option>
                    </select>
                </div>
                <div class="col-md">
                    <input v-model="form.note" type="text" class="form-control" placeholder="Reason (optional)" maxlength="255" aria-label="Reason" />
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-danger w-100" :disabled="form.processing">
                        <IconBan class="icon" />
                        Block
                    </button>
                </div>
            </div>
            <InputError :message="form.errors.cidr || form.errors.duration || form.errors.note" />
            <small class="form-hint mt-2">
                A block covers all traffic from that address: SIP, web portal and SSH. Your address is {{ yourIp }}, which can't be blocked from here.
            </small>
        </form>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Source</th>
                        <th>Reason</th>
                        <th>Blocked</th>
                        <th>Until</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rule in rules" :key="rule.id">
                        <td class="font-monospace">
                            {{ rule.cidr }}
                            <span v-if="rule.bans_30d > 1" class="badge bg-red-lt ms-1" :title="`Banned ${rule.bans_30d} times in the last 30 days`">
                                {{ rule.bans_30d }}× in 30 days
                            </span>
                        </td>
                        <td>
                            <span class="badge" :class="rule.is_fail2ban ? 'bg-orange-lt' : 'bg-blue-lt'">{{ rule.source }}</span>
                        </td>
                        <td class="text-secondary">
                            {{ rule.note || (rule.is_fail2ban ? 'Too many failed attempts' : '—') }}
                            <div v-if="rule.created_by" class="small">by {{ rule.created_by }}</div>
                        </td>
                        <td class="text-secondary text-nowrap">{{ formatDateTime(rule.created_at) }}</td>
                        <td class="text-secondary text-nowrap">{{ rule.expires_at ? formatDateTime(rule.expires_at) : 'Permanent' }}</td>
                        <td>
                            <span v-if="rule.unban_requested" class="text-secondary small text-nowrap">Lifting…</span>
                            <button v-else type="button" class="btn btn-sm" @click="unblock(rule)">
                                {{ rule.is_fail2ban ? 'Lift ban' : 'Unblock' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="rules.length === 0">
                        <td colspan="6" class="text-center text-secondary py-4">Nothing is blocked right now.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </FirewallLayout>
</template>
