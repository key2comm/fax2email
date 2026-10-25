<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import MailLayout from '@/layouts/admin/MailLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { IconSend } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

interface Props {
    settings: {
        host: string;
        port: number;
        encryption: 'starttls' | 'ssl' | 'none';
        username: string;
        from_address: string;
        from_name: string;
    };
    hasPassword: boolean;
    isConfigured: boolean;
    testRecipient: string;
}

const props = defineProps<Props>();

const form = useForm({ ...props.settings, password: '' });

// Fill in the usual port when the encryption type changes, if the port was still a default.
const defaultPorts = { starttls: 587, ssl: 465, none: 25 };
watch(
    () => form.encryption,
    (encryption, previous) => {
        if (Number(form.port) === defaultPorts[previous]) {
            form.port = defaultPorts[encryption];
        }
    },
);

// Set by the test endpoint, which is not one of the form's own fields.
const testError = computed(() => (form.errors as Record<string, string | undefined>).test);

const save = () => {
    form.put(route('admin.mail.update'), { preserveScroll: true, onSuccess: () => form.reset('password') });
};

const sendTest = () => {
    form.post(route('admin.mail.test'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Mail server" />

    <MailLayout>
        <form @submit.prevent="save" novalidate>
            <div class="card-body">
                <p class="text-secondary">The SMTP server used to send invitations, password resets and received faxes.</p>

                <div v-if="!isConfigured" class="alert alert-warning" role="alert">
                    No mail server is set up yet, so emails are not being delivered.
                </div>
                <div v-if="testError" class="alert alert-danger" role="alert">{{ testError }}</div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required" for="host">SMTP host</label>
                        <input
                            id="host"
                            v-model="form.host"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.host }"
                            placeholder="smtp.example.com"
                            required
                        />
                        <InputError :message="form.errors.host" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label required" for="port">Port</label>
                        <input
                            id="port"
                            v-model="form.port"
                            type="number"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.port }"
                            min="1"
                            max="65535"
                            list="smtp-ports"
                            required
                        />
                        <datalist id="smtp-ports">
                            <option value="587">STARTTLS</option>
                            <option value="465">SSL/TLS</option>
                            <option value="2525">Alternate (when 587 is blocked)</option>
                            <option value="25">Plain SMTP</option>
                        </datalist>
                        <InputError :message="form.errors.port" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label required" for="encryption">Encryption</label>
                        <select id="encryption" v-model="form.encryption" class="form-select">
                            <option value="starttls">STARTTLS</option>
                            <option value="ssl">SSL/TLS</option>
                            <option value="none">None</option>
                        </select>
                        <InputError :message="form.errors.encryption" />
                    </div>
                    <div class="col-12">
                        <small class="form-hint">
                            Common ports: 587 (STARTTLS), 465 (SSL/TLS), 2525 (alternate STARTTLS port many providers offer when 587 is blocked), 25
                            (plain).
                        </small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="username">Username</label>
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.username }"
                            autocomplete="off"
                            placeholder="Leave blank if not required"
                        />
                        <InputError :message="form.errors.username" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.password }"
                            autocomplete="new-password"
                            :placeholder="hasPassword ? 'Saved. Leave blank to keep it' : 'Leave blank if not required'"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required" for="from_address">From address</label>
                        <input
                            id="from_address"
                            v-model="form.from_address"
                            type="email"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.from_address }"
                            placeholder="fax@example.com"
                            required
                        />
                        <InputError :message="form.errors.from_address" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required" for="from_name">From name</label>
                        <input
                            id="from_name"
                            v-model="form.from_name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.from_name }"
                            required
                        />
                        <InputError :message="form.errors.from_name" />
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="text-secondary small me-auto">The test uses the values on this form, so you can check them before saving.</span>
                    <button type="button" class="btn" :disabled="form.processing" @click="sendTest">
                        <IconSend class="icon" />
                        Send test to {{ testRecipient }}
                    </button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Save</button>
                </div>
            </div>
        </form>
    </MailLayout>
</template>
