<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useDateTime } from '@/lib/datetime';
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCertificate, IconCircleCheck, IconLockOpen } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Status {
    state: 'off' | 'pending' | 'active' | 'error';
    message: string | null;
    domain: string | null;
    issuer: string | null;
    issued_at: string | null;
    expires_at: string | null;
    checked_at: string | null;
}

const props = defineProps<{
    settings: {
        mode: 'none' | 'letsencrypt' | 'custom';
        domain: string | null;
        email: string | null;
        redirect: boolean;
        auto_renew: boolean;
        renew_days: number;
        force_renew: boolean;
    };
    status: Status;
    resolvesTo: string[];
    serverAddress: string | null;
    nextRenewal: string | null;
    renewDays: number[];
}>();

const { formatDateTime } = useDateTime();
const source = ref<'letsencrypt' | 'custom'>(props.settings.mode === 'custom' ? 'custom' : 'letsencrypt');

const letsEncrypt = useForm({
    domain: props.settings.domain ?? '',
    email: props.settings.email ?? '',
    agree: false,
    redirect: props.settings.redirect,
});
const custom = useForm({ domain: props.settings.domain ?? '', certificate: '', private_key: '', redirect: props.settings.redirect });

const dnsMismatch = computed(() => props.serverAddress && props.resolvesTo.length > 0 && !props.resolvesTo.includes(props.serverAddress));

// Read a .pem/.crt/.key file into the matching box.
const loadFile = (event: Event, field: 'certificate' | 'private_key') => {
    const file = (event.target as HTMLInputElement).files?.[0];
    file?.text().then((text) => (custom[field] = text));
};

const submitLetsEncrypt = () => letsEncrypt.post(route('admin.https.letsencrypt'), { preserveScroll: true });
const submitCustom = () =>
    custom.post(route('admin.https.custom'), { preserveScroll: true, onSuccess: () => custom.reset('certificate', 'private_key') });
const setRedirect = (redirect: boolean) => router.put(route('admin.https.update'), { redirect }, { preserveScroll: true });
const setRenewal = (values: { auto_renew?: boolean; renew_days?: number }) =>
    router.put(route('admin.https.update'), values, { preserveScroll: true });
const renewNow = () => {
    if (confirm('Get a new certificate now? The current one keeps working until the new one is in place.')) {
        router.post(route('admin.https.renew'), {}, { preserveScroll: true });
    }
};
const turnOff = () => {
    if (confirm('Turn off HTTPS? The portal will be served over plain HTTP, so passwords will no longer be encrypted in transit.')) {
        router.delete(route('admin.https.disable'), { preserveScroll: true });
    }
};

// While a request is being processed, check back every few seconds.
let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    poll = setInterval(() => {
        if (props.status.state === 'pending') {
            router.reload({ only: ['status', 'settings'] });
        }
    }, 4000);
});
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <Head title="HTTPS" />

    <AppLayout title="HTTPS" pretitle="Settings">
        <div class="row row-cards">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <span
                                class="avatar"
                                :class="{
                                    'bg-green-lt': status.state === 'active',
                                    'bg-yellow-lt': status.state === 'pending',
                                    'bg-red-lt': status.state === 'error',
                                    'bg-secondary-lt': status.state === 'off',
                                }"
                            >
                                <IconCircleCheck v-if="status.state === 'active'" class="icon" />
                                <span v-else-if="status.state === 'pending'" class="spinner-border spinner-border-sm" role="status"></span>
                                <IconAlertTriangle v-else-if="status.state === 'error'" class="icon" />
                                <IconLockOpen v-else class="icon" />
                            </span>
                            <div class="flex-fill">
                                <h3 class="mb-1">
                                    <template v-if="status.state === 'active'">HTTPS is on for {{ status.domain }}</template>
                                    <template v-else-if="status.state === 'pending'">Working on it…</template>
                                    <template v-else-if="status.state === 'error'">HTTPS could not be set up</template>
                                    <template v-else>HTTPS is off</template>
                                </h3>
                                <div v-if="status.state === 'active'" class="text-secondary">
                                    Certificate from {{ status.issuer }}, valid until {{ formatDateTime(status.expires_at) }}.
                                    <template v-if="settings.mode === 'letsencrypt' && nextRenewal">
                                        Next automatic renewal: {{ formatDateTime(nextRenewal) }}.
                                    </template>
                                    <template v-else-if="settings.mode === 'letsencrypt'">Automatic renewal is off.</template>
                                    <template v-else>Upload a new certificate before then.</template>
                                </div>
                                <div v-else-if="status.state === 'pending'" class="text-secondary">This usually takes under a minute.</div>
                                <div v-else-if="status.state === 'error'" class="text-danger">{{ status.message }}</div>
                                <div v-else class="text-secondary">
                                    The portal is served over plain HTTP, so passwords are not encrypted on the way. Set up a certificate below.
                                </div>
                            </div>
                        </div>

                        <div v-if="status.state === 'active'" class="d-flex flex-wrap align-items-center gap-3 mt-3 pt-3 border-top">
                            <label class="form-check form-switch m-0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    :checked="settings.redirect"
                                    @change="setRedirect(($event.target as HTMLInputElement).checked)"
                                />
                                <span class="form-check-label">Send HTTP visitors to HTTPS</span>
                            </label>
                            <template v-if="settings.mode === 'letsencrypt'">
                                <label class="form-check form-switch m-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        :checked="settings.auto_renew"
                                        @change="setRenewal({ auto_renew: ($event.target as HTMLInputElement).checked })"
                                    />
                                    <span class="form-check-label">Renew automatically</span>
                                </label>
                                <div v-if="settings.auto_renew" class="d-flex align-items-center gap-2">
                                    <span class="text-secondary">every</span>
                                    <select
                                        class="form-select form-select-sm w-auto"
                                        aria-label="Renew every"
                                        :value="settings.renew_days"
                                        @change="setRenewal({ renew_days: Number(($event.target as HTMLSelectElement).value) })"
                                    >
                                        <option v-for="days in renewDays" :key="days" :value="days">{{ days }} days</option>
                                    </select>
                                </div>
                                <button type="button" class="btn btn-sm" :disabled="settings.force_renew" @click="renewNow">
                                    {{ settings.force_renew ? 'Renewing…' : 'Renew now' }}
                                </button>
                            </template>
                            <a :href="`https://${status.domain}`" class="ms-auto">Open https://{{ status.domain }}</a>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="turnOff">Turn off HTTPS</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-tabs card-header-tabs">
                            <li class="nav-item">
                                <a href="#" class="nav-link" :class="{ active: source === 'letsencrypt' }" @click.prevent="source = 'letsencrypt'">
                                    Let's Encrypt (free)
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="#" class="nav-link" :class="{ active: source === 'custom' }" @click.prevent="source = 'custom'"
                                    >Your own certificate</a
                                >
                            </li>
                        </ul>
                    </div>

                    <form v-if="source === 'letsencrypt'" @submit.prevent="submitLetsEncrypt" novalidate>
                        <div class="card-body">
                            <p class="text-secondary">
                                Let's Encrypt issues free certificates that this server renews automatically. Before you start, the domain must point
                                at this server (a DNS A record) and port 80 must be reachable from the internet.
                            </p>
                            <div v-if="dnsMismatch" class="alert alert-warning">
                                {{ letsEncrypt.domain }} currently points to {{ resolvesTo.join(', ') }}, but this server's address is
                                {{ serverAddress }}. That's fine behind a NAT or load balancer; otherwise fix the DNS record first.
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required" for="le-domain">Portal domain</label>
                                    <input
                                        id="le-domain"
                                        v-model="letsEncrypt.domain"
                                        type="text"
                                        class="form-control"
                                        :class="{ 'is-invalid': letsEncrypt.errors.domain }"
                                        placeholder="portal.yourdomain.com"
                                    />
                                    <InputError :message="letsEncrypt.errors.domain" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required" for="le-email">Email for expiry notices</label>
                                    <input
                                        id="le-email"
                                        v-model="letsEncrypt.email"
                                        type="email"
                                        class="form-control"
                                        :class="{ 'is-invalid': letsEncrypt.errors.email }"
                                    />
                                    <InputError :message="letsEncrypt.errors.email" />
                                </div>
                                <div class="col-12">
                                    <label class="form-check">
                                        <input v-model="letsEncrypt.agree" type="checkbox" class="form-check-input" />
                                        <span class="form-check-label">
                                            I agree to the
                                            <a href="https://letsencrypt.org/repository/" target="_blank" rel="noopener"
                                                >Let's Encrypt terms of service</a
                                            >.
                                        </span>
                                    </label>
                                    <InputError :message="letsEncrypt.errors.agree" />
                                    <label class="form-check form-switch mt-2">
                                        <input v-model="letsEncrypt.redirect" type="checkbox" class="form-check-input" />
                                        <span class="form-check-label">Send HTTP visitors to HTTPS</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary" :disabled="letsEncrypt.processing || status.state === 'pending'">
                                <IconCertificate class="icon" />
                                Get certificate
                            </button>
                        </div>
                    </form>

                    <form v-else @submit.prevent="submitCustom" novalidate>
                        <div class="card-body">
                            <p class="text-secondary">
                                Use a certificate from your own provider. Paste the certificate (with any intermediate certificates after it) and its
                                private key in PEM format, or choose the files.
                            </p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required" for="custom-domain">Portal domain</label>
                                    <input
                                        id="custom-domain"
                                        v-model="custom.domain"
                                        type="text"
                                        class="form-control"
                                        :class="{ 'is-invalid': custom.errors.domain }"
                                        placeholder="portal.yourdomain.com"
                                    />
                                    <InputError :message="custom.errors.domain" />
                                </div>
                                <div class="col-12">
                                    <label class="form-label required" for="certificate">Certificate</label>
                                    <textarea
                                        id="certificate"
                                        v-model="custom.certificate"
                                        class="form-control font-monospace small"
                                        :class="{ 'is-invalid': custom.errors.certificate }"
                                        rows="5"
                                        placeholder="-----BEGIN CERTIFICATE-----"
                                    ></textarea>
                                    <input
                                        type="file"
                                        accept=".pem,.crt,.cer"
                                        class="form-control form-control-sm mt-1"
                                        @change="loadFile($event, 'certificate')"
                                    />
                                    <InputError :message="custom.errors.certificate" />
                                </div>
                                <div class="col-12">
                                    <label class="form-label required" for="private_key">Private key</label>
                                    <textarea
                                        id="private_key"
                                        v-model="custom.private_key"
                                        class="form-control font-monospace small"
                                        :class="{ 'is-invalid': custom.errors.private_key }"
                                        rows="5"
                                        placeholder="-----BEGIN PRIVATE KEY-----"
                                        autocomplete="off"
                                    ></textarea>
                                    <input
                                        type="file"
                                        accept=".pem,.key"
                                        class="form-control form-control-sm mt-1"
                                        @change="loadFile($event, 'private_key')"
                                    />
                                    <InputError :message="custom.errors.private_key" />
                                    <small class="form-hint">The key is moved to a root-only folder on the server and is never shown again.</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-check form-switch">
                                        <input v-model="custom.redirect" type="checkbox" class="form-check-input" />
                                        <span class="form-check-label">Send HTTP visitors to HTTPS</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary" :disabled="custom.processing || status.state === 'pending'">
                                <IconCertificate class="icon" />
                                Install certificate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
