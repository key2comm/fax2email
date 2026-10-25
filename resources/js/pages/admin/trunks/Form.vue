<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

interface Trunk {
    id: number;
    name: string;
    enabled: boolean;
    auth_type: 'ip' | 'credentials';
    transport: 'udp' | 'tcp' | 'tls';
    host: string;
    port: number;
    dial_format: 'e164' | 'e164_plus' | 'national';
    username: string | null;
    extra_addresses: string;
    has_password: boolean;
}

const props = defineProps<{
    trunk: Trunk | null;
}>();

const form = useForm({
    name: props.trunk?.name ?? '',
    enabled: props.trunk?.enabled ?? true,
    auth_type: props.trunk?.auth_type ?? 'ip',
    transport: props.trunk?.transport ?? 'udp',
    host: props.trunk?.host ?? '',
    port: props.trunk?.port ?? 5060,
    dial_format: props.trunk?.dial_format ?? 'e164',
    username: props.trunk?.username ?? '',
    password: '',
    extra_addresses: props.trunk?.extra_addresses ?? '',
});

const title = computed(() => (props.trunk ? `Edit ${props.trunk.name}` : 'Add SIP trunk'));

// Switch to the usual port when the protocol changes, if the port was still the usual one.
watch(
    () => form.transport,
    (transport, previous) => {
        const standard = (t: string) => (t === 'tls' ? 5061 : 5060);
        if (Number(form.port) === standard(previous)) {
            form.port = standard(transport);
        }
    },
);

const submit = () => {
    if (props.trunk) {
        form.put(route('admin.trunks.update', props.trunk.id), { preserveScroll: true });
    } else {
        form.post(route('admin.trunks.store'), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="title" />

    <AppLayout :title="title" pretitle="SIP trunks">
        <form class="card" style="max-width: 48rem" @submit.prevent="submit" novalidate>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label required" for="name">Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.name }"
                            placeholder="e.g. Provider A"
                            maxlength="60"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <label class="form-check form-switch mb-2">
                            <input v-model="form.enabled" class="form-check-input" type="checkbox" />
                            <span class="form-check-label">Enabled</span>
                        </label>
                    </div>

                    <div class="col-12">
                        <label class="form-label">How does the provider identify this server?</label>
                        <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column flex-md-row gap-2">
                            <label class="form-selectgroup-item flex-fill">
                                <input v-model="form.auth_type" type="radio" value="ip" class="form-selectgroup-input" />
                                <span class="form-selectgroup-label d-flex align-items-center p-3">
                                    <span class="me-3"><span class="form-selectgroup-check"></span></span>
                                    <span>
                                        <strong>IP address</strong>
                                        <span class="d-block text-secondary small">
                                            The provider sends calls from known addresses. No username or password.
                                        </span>
                                    </span>
                                </span>
                            </label>
                            <label class="form-selectgroup-item flex-fill">
                                <input v-model="form.auth_type" type="radio" value="credentials" class="form-selectgroup-input" />
                                <span class="form-selectgroup-label d-flex align-items-center p-3">
                                    <span class="me-3"><span class="form-selectgroup-check"></span></span>
                                    <span>
                                        <strong>Username and password</strong>
                                        <span class="d-block text-secondary small">This server registers to the provider.</span>
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label required" for="transport">Protocol</label>
                        <select id="transport" v-model="form.transport" class="form-select">
                            <option value="udp">UDP</option>
                            <option value="tcp">TCP</option>
                            <option value="tls">TLS (encrypted)</option>
                        </select>
                        <InputError :message="form.errors.transport" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required" for="host">Provider host</label>
                        <input
                            id="host"
                            v-model="form.host"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.host }"
                            placeholder="sip.provider.com or 203.0.113.10"
                            required
                        />
                        <InputError :message="form.errors.host" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required" for="port">Port</label>
                        <input
                            id="port"
                            v-model.number="form.port"
                            type="number"
                            min="1"
                            max="65535"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.port }"
                            required
                        />
                        <InputError :message="form.errors.port" />
                    </div>
                    <div class="col-12">
                        <small class="form-hint"
                            >Standard ports are 5060 for UDP and TCP, and 5061 for TLS. Use your provider's port if different.</small
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="dial_format">Dial numbers as</label>
                        <select id="dial_format" v-model="form.dial_format" class="form-select" :class="{ 'is-invalid': form.errors.dial_format }">
                            <option value="e164">15551234567 (country code, no +)</option>
                            <option value="e164_plus">+15551234567 (with +)</option>
                            <option value="national">5551234567 (10 digits in North America)</option>
                        </select>
                        <InputError :message="form.errors.dial_format" />
                        <small class="form-hint"
                            >How this provider wants fax numbers sent when you send a fax. Check your provider's documentation.</small
                        >
                    </div>

                    <template v-if="form.auth_type === 'credentials'">
                        <div class="col-md-6">
                            <label class="form-label required" for="username">Username</label>
                            <input
                                id="username"
                                v-model="form.username"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.username }"
                                autocomplete="off"
                                required
                            />
                            <InputError :message="form.errors.username" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" :class="{ required: !trunk?.has_password }" for="password">Password</label>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.password }"
                                autocomplete="new-password"
                                :placeholder="trunk?.has_password ? 'Saved. Leave blank to keep it' : ''"
                            />
                            <InputError :message="form.errors.password" />
                        </div>
                    </template>

                    <div class="col-12">
                        <label class="form-label" for="extra_addresses">Additional provider addresses</label>
                        <textarea
                            id="extra_addresses"
                            v-model="form.extra_addresses"
                            class="form-control font-monospace"
                            :class="{ 'is-invalid': form.errors.extra_addresses }"
                            rows="3"
                            placeholder="One per line, e.g. 203.0.113.0/24 or sip2.provider.com"
                        ></textarea>
                        <InputError :message="form.errors.extra_addresses" />
                        <small class="form-hint">
                            Providers often send calls from several servers. List any addresses besides the host above. Everything here and the host's
                            addresses are whitelisted as <strong>Provider trunk</strong>, and only these addresses can reach the SIP ports. Hostnames
                            are looked up again every 5 minutes.
                        </small>
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <div class="btn-list justify-content-end">
                    <Link :href="route('admin.trunks.index')" class="btn">Cancel</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ trunk ? 'Save' : 'Add trunk' }}</button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
