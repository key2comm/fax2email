<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FirewallLayout, { type FirewallStatus } from '@/layouts/admin/FirewallLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconShieldCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

interface RuleRow {
    id: number;
    cidr: string;
    note: string | null;
    created_by: string | null;
    created_at: string;
}

const props = defineProps<{
    rules: RuleRow[];
    status: FirewallStatus;
    yourIp: string;
}>();

const form = useForm({ type: 'allow', cidr: '', note: '' });
const yourIpListed = computed(() => props.rules.some((rule) => rule.cidr === props.yourIp));

const add = () => {
    form.post(route('admin.firewall.rules.store'), { preserveScroll: true, onSuccess: () => form.reset('cidr', 'note') });
};

const addMine = () => {
    form.cidr = props.yourIp;
    form.note = form.note || 'My address';
    add();
};

const remove = (rule: RuleRow) => {
    if (confirm(`Remove ${rule.cidr} from the whitelist? It can then be banned by fail2ban like any other address.`)) {
        router.delete(route('admin.firewall.rules.destroy', rule.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Firewall: whitelist" />

    <FirewallLayout :status="status">
        <form class="card-body border-bottom" @submit.prevent="add" novalidate>
            <label class="form-label" for="cidr">Whitelist an IP address or range</label>
            <div class="row g-2">
                <div class="col-md-4">
                    <input
                        id="cidr"
                        v-model="form.cidr"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.cidr }"
                        placeholder="198.51.100.10 or 198.51.100.0/24"
                        required
                    />
                </div>
                <div class="col-md">
                    <input
                        v-model="form.note"
                        type="text"
                        class="form-control"
                        placeholder="Note, e.g. Office or SIP carrier"
                        maxlength="255"
                        aria-label="Note"
                    />
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary w-100" :disabled="form.processing">
                        <IconShieldCheck class="icon" />
                        Whitelist
                    </button>
                </div>
            </div>
            <InputError :message="form.errors.cidr || form.errors.note" />
            <small class="form-hint mt-2">
                Whitelisted addresses are never blocked or banned. Add your office and your SIP carriers' addresses so they can't be locked out.
                <template v-if="!yourIpListed">
                    Your address is {{ yourIp }}.
                    <button type="button" class="btn btn-link p-0 align-baseline" :disabled="form.processing" @click="addMine">Whitelist it</button>
                </template>
            </small>
        </form>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Note</th>
                        <th>Added</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rule in rules" :key="rule.id">
                        <td class="font-monospace">{{ rule.cidr }}</td>
                        <td class="text-secondary">{{ rule.note || '—' }}</td>
                        <td class="text-secondary text-nowrap">
                            {{ new Date(rule.created_at).toLocaleDateString() }}
                            <span v-if="rule.created_by"> by {{ rule.created_by }}</span>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm" @click="remove(rule)">Remove</button>
                        </td>
                    </tr>
                    <tr v-if="rules.length === 0">
                        <td colspan="4" class="text-center text-secondary py-4">No whitelisted addresses yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </FirewallLayout>
</template>
