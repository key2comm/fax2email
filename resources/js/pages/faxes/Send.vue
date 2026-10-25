<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconArrowDown, IconArrowUp, IconFileTypePdf, IconSend, IconX } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

interface Contact {
    id: number;
    name: string;
    company: string | null;
    fax_number: string;
    formatted: string;
}

const props = defineProps<{
    contacts: Contact[];
    numbers: { id: number; label: string }[];
    contactId: number | null;
    emailDomain: string | null;
    maxFiles: number;
    maxFileMb: number;
}>();

const preselected = props.contacts.find((c) => c.id === props.contactId);

const form = useForm({
    to: preselected?.fax_number ?? '',
    to_name: preselected?.name ?? '',
    from_number_id: props.numbers[0]?.id ?? null,
    files: [] as File[],
});

const search = ref('');
const matches = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') {
        return props.contacts.slice(0, 8);
    }
    const digits = term.replace(/\D/g, '');

    return props.contacts
        .filter(
            (c) => c.name.toLowerCase().includes(term) || c.company?.toLowerCase().includes(term) || (digits !== '' && c.fax_number.includes(digits)),
        )
        .slice(0, 8);
});

const choose = (contact: Contact) => {
    form.to = contact.fax_number;
    form.to_name = contact.name;
    search.value = '';
};

const fileInput = ref<HTMLInputElement | null>(null);
const fileError = ref('');

const addFiles = (event: Event) => {
    fileError.value = '';
    for (const file of Array.from((event.target as HTMLInputElement).files ?? [])) {
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            fileError.value = `${file.name} is not a PDF.`;
        } else if (file.size > props.maxFileMb * 1024 * 1024) {
            fileError.value = `${file.name} is larger than ${props.maxFileMb} MB.`;
        } else if (form.files.length >= props.maxFiles) {
            fileError.value = `Up to ${props.maxFiles} files per fax.`;
        } else {
            form.files.push(file);
        }
    }
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const move = (index: number, by: number) => {
    const files = [...form.files];
    [files[index], files[index + by]] = [files[index + by], files[index]];
    form.files = files;
};

const fileErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('files'))
        .map(([, message]) => message),
);

const submit = () => {
    form.post(route('faxes.send.store'), { forceFormData: true });
};
</script>

<template>
    <Head title="Send a fax" />

    <AppLayout title="Send a fax">
        <form class="row row-cards" @submit.prevent="submit" novalidate>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">To</h3>

                        <div v-if="contacts.length" class="mb-3">
                            <label class="form-label" for="search">Pick from the address book</label>
                            <input id="search" v-model="search" type="search" class="form-control" placeholder="Search by name, company or number" />
                            <div class="list-group list-group-flush mt-1">
                                <button
                                    v-for="contact in matches"
                                    :key="contact.id"
                                    type="button"
                                    class="list-group-item list-group-item-action d-flex justify-content-between"
                                    :class="{ active: form.to === contact.fax_number }"
                                    @click="choose(contact)"
                                >
                                    <span>
                                        {{ contact.name }}
                                        <span v-if="contact.company" class="text-secondary">· {{ contact.company }}</span>
                                    </span>
                                    <span class="font-monospace">{{ contact.formatted }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required" for="to">Fax number</label>
                                <input
                                    id="to"
                                    v-model="form.to"
                                    type="tel"
                                    class="form-control font-monospace"
                                    :class="{ 'is-invalid': form.errors.to }"
                                    placeholder="555 123 4567"
                                    required
                                />
                                <InputError :message="form.errors.to" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="to_name">Recipient name</label>
                                <input id="to_name" v-model="form.to_name" type="text" class="form-control" placeholder="Optional" maxlength="255" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required" for="from">Send from</label>
                                <select
                                    id="from"
                                    v-model="form.from_number_id"
                                    class="form-select"
                                    :class="{ 'is-invalid': form.errors.from_number_id }"
                                >
                                    <option v-for="number in numbers" :key="number.id" :value="number.id">{{ number.label }}</option>
                                </select>
                                <InputError :message="form.errors.from_number_id" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Documents</h3>
                        <p class="text-secondary small">
                            PDF files only, up to {{ maxFiles }} files of {{ maxFileMb }} MB each. They are faxed as one fax, in this order.
                        </p>

                        <ul v-if="form.files.length" class="list-group mb-3">
                            <li
                                v-for="(file, index) in form.files"
                                :key="`${file.name}-${index}`"
                                class="list-group-item d-flex align-items-center gap-2"
                            >
                                <IconFileTypePdf class="icon text-red" />
                                <span class="text-truncate flex-fill" :title="file.name">{{ file.name }}</span>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-ghost-secondary btn-icon"
                                    :disabled="index === 0"
                                    aria-label="Move up"
                                    @click="move(index, -1)"
                                >
                                    <IconArrowUp class="icon" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-ghost-secondary btn-icon"
                                    :disabled="index === form.files.length - 1"
                                    aria-label="Move down"
                                    @click="move(index, 1)"
                                >
                                    <IconArrowDown class="icon" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-ghost-danger btn-icon"
                                    aria-label="Remove"
                                    @click="form.files.splice(index, 1)"
                                >
                                    <IconX class="icon" />
                                </button>
                            </li>
                        </ul>

                        <input ref="fileInput" type="file" accept="application/pdf,.pdf" multiple class="form-control" @change="addFiles" />
                        <div v-if="fileError" class="invalid-feedback d-block">{{ fileError }}</div>
                        <div v-for="message in fileErrors" :key="message" class="invalid-feedback d-block">{{ message }}</div>
                        <div v-if="emailDomain" class="alert alert-info mt-3 mb-0">
                            You can also fax by email: send the PDFs from your login email address to the fax number followed by
                            <strong>@{{ emailDomain }}</strong
                            >, e.g. <span class="font-monospace">15551234567@{{ emailDomain }}</span
                            >.
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="btn-list justify-content-end">
                            <Link :href="route('faxes.index', { direction: 'outbound' })" class="btn">Cancel</Link>
                            <button type="submit" class="btn btn-primary" :disabled="form.processing || form.files.length === 0">
                                <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                                <IconSend v-else class="icon" />
                                Send fax
                            </button>
                        </div>
                        <div v-if="form.progress" class="progress progress-sm mt-2">
                            <div class="progress-bar" :style="{ width: `${form.progress.percentage}%` }"></div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
