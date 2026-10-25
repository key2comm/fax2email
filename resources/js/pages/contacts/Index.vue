<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { IconAddressBook, IconPencil, IconPlus, IconSearch, IconSend, IconTrash } from '@tabler/icons-vue';
import { ref, watch } from 'vue';

interface Contact {
    id: number;
    name: string;
    company: string | null;
    fax_number: string;
    formatted: string;
    notes: string | null;
}

const props = defineProps<{
    contacts: Contact[];
    search: string;
}>();

const page = usePage<SharedData>();
const search = ref(props.search);
let timer: ReturnType<typeof setTimeout>;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('contacts.index'), value ? { search: value } : {}, { preserveState: true, preserveScroll: true }), 300);
});

const editing = ref<Contact | null>(null);
const form = useForm({ name: '', company: '', fax_number: '', notes: '' });

const edit = (contact: Contact) => {
    editing.value = contact;
    form.clearErrors();
    form.name = contact.name;
    form.company = contact.company ?? '';
    form.fax_number = contact.formatted;
    form.notes = contact.notes ?? '';
};

const reset = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
};

const save = () => {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value) {
        form.put(route('contacts.update', editing.value.id), options);
    } else {
        form.post(route('contacts.store'), options);
    }
};

const remove = (contact: Contact) => {
    if (confirm(`Remove ${contact.name} from the address book?`)) {
        router.delete(route('contacts.destroy', contact.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Address book" />

    <AppLayout title="Address book" :pretitle="`Shared by everyone at ${page.props.auth.user.account?.name ?? 'your company'}`">
        <div class="row row-cards">
            <div class="col-lg-4">
                <form class="card" @submit.prevent="save" novalidate>
                    <div class="card-header">
                        <h3 class="card-title">{{ editing ? `Edit ${editing.name}` : 'Add a contact' }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label required" for="name">Name</label>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.name }"
                                maxlength="255"
                                required
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="company">Company</label>
                            <input id="company" v-model="form.company" type="text" class="form-control" maxlength="255" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label required" for="fax_number">Fax number</label>
                            <input
                                id="fax_number"
                                v-model="form.fax_number"
                                type="tel"
                                class="form-control font-monospace"
                                :class="{ 'is-invalid': form.errors.fax_number }"
                                placeholder="555 123 4567"
                                required
                            />
                            <InputError :message="form.errors.fax_number" />
                        </div>
                        <div>
                            <label class="form-label" for="notes">Notes</label>
                            <textarea id="notes" v-model="form.notes" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="btn-list justify-content-end">
                            <button v-if="editing" type="button" class="btn" @click="reset">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                <IconPlus v-if="!editing" class="icon" />
                                {{ editing ? 'Save' : 'Add contact' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body border-bottom py-3">
                        <div class="input-icon">
                            <span class="input-icon-addon"><IconSearch class="icon" /></span>
                            <input
                                v-model="search"
                                type="search"
                                class="form-control"
                                placeholder="Search by name, company or number"
                                aria-label="Search contacts"
                            />
                        </div>
                    </div>

                    <div v-if="contacts.length === 0" class="card-body">
                        <div class="empty">
                            <div class="empty-icon"><IconAddressBook class="icon" /></div>
                            <p class="empty-title">{{ search ? 'No matches' : 'No contacts yet' }}</p>
                            <p class="empty-subtitle text-secondary">
                                Contacts you add here can be picked by anyone at your company when sending a fax.
                            </p>
                        </div>
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Fax number</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="contact in contacts" :key="contact.id" :class="{ 'bg-primary-lt': editing?.id === contact.id }">
                                    <td>
                                        <div class="fw-medium">{{ contact.name }}</div>
                                        <div v-if="contact.company" class="small text-secondary">{{ contact.company }}</div>
                                        <div v-if="contact.notes" class="small text-secondary">{{ contact.notes }}</div>
                                    </td>
                                    <td class="font-monospace">{{ contact.formatted }}</td>
                                    <td>
                                        <div class="btn-list flex-nowrap">
                                            <Link
                                                v-if="page.props.auth.can.sendFaxes"
                                                :href="route('faxes.send', { contact: contact.id })"
                                                class="btn btn-sm btn-primary"
                                            >
                                                <IconSend class="icon" />
                                                Fax
                                            </Link>
                                            <button type="button" class="btn btn-sm" :aria-label="`Edit ${contact.name}`" @click="edit(contact)">
                                                <IconPencil class="icon" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                :aria-label="`Remove ${contact.name}`"
                                                @click="remove(contact)"
                                            >
                                                <IconTrash class="icon" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
