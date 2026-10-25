<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectInput from '@/components/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

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
    status?: string;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mail server', href: '/admin/mail' }];

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

const save = () => {
    form.put(route('admin.mail.update'), { preserveScroll: true, onSuccess: () => form.reset('password') });
};

const sendTest = () => {
    form.post(route('admin.mail.test'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Mail server" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-6 p-4">
            <Heading title="Outgoing mail server" description="The SMTP server used to send invitations, password resets and received faxes." />

            <p
                v-if="!isConfigured"
                class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
            >
                No mail server is set up yet, so emails are not being delivered.
            </p>

            <p
                v-if="status"
                class="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-700 dark:bg-green-950 dark:text-green-200"
            >
                {{ status }}
            </p>
            <InputError :message="form.errors.test" />

            <form @submit.prevent="save" class="grid gap-6">
                <div class="grid gap-4 sm:grid-cols-[1fr_8rem_10rem]">
                    <div class="grid gap-2">
                        <Label for="host">SMTP host</Label>
                        <Input id="host" v-model="form.host" required placeholder="smtp.example.com" />
                        <InputError :message="form.errors.host" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="port">Port</Label>
                        <Input id="port" type="number" v-model="form.port" required min="1" max="65535" list="smtp-ports" />
                        <datalist id="smtp-ports">
                            <option value="587">STARTTLS</option>
                            <option value="465">SSL/TLS</option>
                            <option value="2525">Alternate (when 587 is blocked)</option>
                            <option value="25">Plain SMTP</option>
                        </datalist>
                        <InputError :message="form.errors.port" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="encryption">Encryption</Label>
                        <SelectInput id="encryption" v-model="form.encryption">
                            <option value="starttls">STARTTLS</option>
                            <option value="ssl">SSL/TLS</option>
                            <option value="none">None</option>
                        </SelectInput>
                        <InputError :message="form.errors.encryption" />
                    </div>
                </div>
                <p class="-mt-4 text-sm text-muted-foreground">
                    Common ports: 587 (STARTTLS), 465 (SSL/TLS), 2525 (alternate STARTTLS port many providers offer when 587 is blocked), 25 (plain).
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="username">Username</Label>
                        <Input id="username" v-model="form.username" autocomplete="off" placeholder="Leave blank if not required" />
                        <InputError :message="form.errors.username" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            v-model="form.password"
                            autocomplete="new-password"
                            :placeholder="hasPassword ? 'Saved. Leave blank to keep it' : 'Leave blank if not required'"
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="from_address">From address</Label>
                        <Input id="from_address" type="email" v-model="form.from_address" required placeholder="fax@example.com" />
                        <InputError :message="form.errors.from_address" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="from_name">From name</Label>
                        <Input id="from_name" v-model="form.from_name" required />
                        <InputError :message="form.errors.from_name" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button :disabled="form.processing">Save</Button>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="sendTest">
                        Send test email to {{ testRecipient }}
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">The test uses the values on this form, so you can check them before saving.</p>
            </form>
        </div>
    </AppLayout>
</template>
