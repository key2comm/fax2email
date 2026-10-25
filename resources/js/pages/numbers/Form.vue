<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface FaxNumber {
    id: number;
    number: string;
    label: string | null;
    enabled: boolean;
    account_id: number;
    recipients: string;
    owner_emails: string[];
}

const props = defineProps<{
    number: FaxNumber | null;
    accounts: { id: number; name: string }[];
}>();

// Account Owners get no account list: they can only change the label and recipients.
const isStaff = computed(() => props.accounts.length > 0);

const form = useForm({
    number: props.number?.number ?? '',
    account_id: props.number?.account_id ?? null,
    label: props.number?.label ?? '',
    enabled: props.number?.enabled ?? true,
    recipients: props.number?.recipients ?? '',
});

const title = computed(() => (props.number ? 'Edit fax number' : 'Add fax number'));

const submit = () => {
    if (props.number) {
        form.put(route('numbers.update', props.number.id), { preserveScroll: true });
    } else {
        form.post(route('numbers.store'), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="title" />

    <AppLayout :title="title" pretitle="Fax numbers">
        <form class="card" style="max-width: 44rem" @submit.prevent="submit" novalidate>
            <div class="card-body">
                <div class="row g-3">
                    <template v-if="isStaff">
                        <div class="col-md-6">
                            <label class="form-label required" for="number">Fax number</label>
                            <input
                                id="number"
                                v-model="form.number"
                                type="tel"
                                class="form-control font-monospace"
                                :class="{ 'is-invalid': form.errors.number }"
                                placeholder="+1 555 123 4567"
                                required
                            />
                            <InputError :message="form.errors.number" />
                            <small class="form-hint">Include the country code. 10-digit North American numbers get +1 added.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required" for="account_id">Account</label>
                            <select
                                id="account_id"
                                v-model="form.account_id"
                                class="form-select"
                                :class="{ 'is-invalid': form.errors.account_id }"
                                required
                            >
                                <option :value="null" disabled>Choose…</option>
                                <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                            </select>
                            <InputError :message="form.errors.account_id" />
                        </div>
                    </template>
                    <div v-else class="col-12">
                        <label class="form-label">Fax number</label>
                        <div class="form-control-plaintext font-monospace">{{ number?.number }}</div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label" for="label">Label</label>
                        <input
                            id="label"
                            v-model="form.label"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.label }"
                            placeholder="e.g. Front desk"
                            maxlength="100"
                        />
                        <InputError :message="form.errors.label" />
                    </div>
                    <div v-if="isStaff" class="col-md-4 d-flex align-items-end">
                        <label class="form-check form-switch mb-2">
                            <input v-model="form.enabled" class="form-check-input" type="checkbox" />
                            <span class="form-check-label">Receiving faxes</span>
                        </label>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="recipients">Email received faxes to</label>
                        <textarea
                            id="recipients"
                            v-model="form.recipients"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.recipients }"
                            rows="4"
                            placeholder="One email address per line"
                        ></textarea>
                        <InputError :message="form.errors.recipients" />
                        <small class="form-hint">
                            Each fax is emailed as a PDF to every address here.
                            <template v-if="number?.owner_emails?.length">
                                If left empty, faxes go to the account's owners: {{ number.owner_emails.join(', ') }}.
                            </template>
                            <template v-else>If left empty, faxes go to the account's owners.</template>
                        </small>
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <div class="btn-list justify-content-end">
                    <Link :href="route('numbers.index')" class="btn">Cancel</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ number ? 'Save' : 'Add number' }}</button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
