<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { IconPrinter, IconTrash } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    values: { name: string | null; color: string | null };
    defaultName: string;
}>();

const page = usePage<SharedData>();
const current = computed(() => page.props.branding);

type Asset = 'logo' | 'logo_dark' | 'favicon';
const removeKeys: Record<Asset, string> = { logo: 'logo', logo_dark: 'logo-dark', favicon: 'favicon' };

const form = useForm({
    name: props.values.name ?? '',
    color: props.values.color ?? '',
    logo: null as File | null,
    logo_dark: null as File | null,
    favicon: null as File | null,
    remove: [] as string[],
});

// Previews: a newly chosen file, or what is saved now (unless marked for removal).
const previews = ref<Record<Asset, string | null>>({ logo: null, logo_dark: null, favicon: null });
const preview = (asset: Asset) => previews.value[asset] ?? (form.remove.includes(removeKeys[asset]) ? null : current.value[asset]);

const choose = (asset: Asset, event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form[asset] = file;
    previews.value[asset] = file ? URL.createObjectURL(file) : null;
    form.remove = form.remove.filter((key) => key !== removeKeys[asset]);
};

const remove = (asset: Asset) => {
    form[asset] = null;
    previews.value[asset] = null;
    if (!form.remove.includes(removeKeys[asset])) {
        form.remove.push(removeKeys[asset]);
    }
};

const previewName = computed(() => form.name.trim() || props.defaultName);
const previewColor = computed(() => form.color || '#066fd1');

const save = () => {
    form.post(route('admin.branding.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('logo', 'logo_dark', 'favicon', 'remove');
            previews.value = { logo: null, logo_dark: null, favicon: null };
        },
    });
};
</script>

<template>
    <Head title="Branding" />

    <AppLayout title="Branding" pretitle="Settings">
        <form class="row row-cards" @submit.prevent="save" novalidate>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="name">App name</label>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.name }"
                                :placeholder="defaultName"
                                maxlength="40"
                            />
                            <InputError :message="form.errors.name" />
                            <small class="form-hint">Shown in the menu bar, login page and browser tabs. Leave blank for "{{ defaultName }}".</small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="color">Brand colour</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input id="color" v-model="form.color" type="color" class="form-control form-control-color" :value="previewColor" />
                                <input
                                    v-model="form.color"
                                    type="text"
                                    class="form-control font-monospace"
                                    style="max-width: 9rem"
                                    placeholder="#066fd1"
                                />
                                <button v-if="form.color" type="button" class="btn btn-link" @click="form.color = ''">Use the default</button>
                            </div>
                            <InputError :message="form.errors.color" />
                            <small class="form-hint">Used for buttons, links and highlights.</small>
                        </div>

                        <div v-for="asset in ['logo', 'logo_dark', 'favicon'] as Asset[]" :key="asset" class="mb-3">
                            <label class="form-label">
                                {{
                                    asset === 'logo' ? 'Logo' : asset === 'logo_dark' ? 'Logo for dark mode (optional)' : 'Browser tab icon (favicon)'
                                }}
                            </label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="file" accept="image/png,image/jpeg,image/webp" class="form-control" @change="choose(asset, $event)" />
                                <button
                                    v-if="preview(asset)"
                                    type="button"
                                    class="btn btn-outline-danger btn-icon"
                                    :aria-label="`Remove ${asset}`"
                                    @click="remove(asset)"
                                >
                                    <IconTrash class="icon" />
                                </button>
                            </div>
                            <InputError :message="form.errors[asset]" />
                            <small class="form-hint">
                                <template v-if="asset === 'favicon'">A square PNG works best, e.g. 256 × 256.</template>
                                <template v-else-if="asset === 'logo_dark'"
                                    >A light version of your logo, used when someone switches to dark mode.</template
                                >
                                <template v-else>PNG with a transparent background works best. Shown about 32 pixels tall.</template>
                                PNG, JPG or WebP.
                            </small>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Save branding</button>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Preview</h3>
                    </div>
                    <div class="card-body">
                        <div v-for="mode in ['light', 'dark']" :key="mode" :data-bs-theme="mode" class="border rounded p-3 mb-3 bg-body">
                            <div class="small text-secondary mb-2">{{ mode === 'light' ? 'Light mode' : 'Dark mode' }}</div>
                            <div class="d-flex align-items-center justify-content-between">
                                <img
                                    v-if="(mode === 'dark' && preview('logo_dark')) || preview('logo')"
                                    :src="((mode === 'dark' && preview('logo_dark')) || preview('logo')) as string"
                                    :alt="previewName"
                                    style="height: 2rem"
                                />
                                <span v-else class="d-inline-flex align-items-center gap-2 text-body">
                                    <span class="avatar avatar-sm text-white" :style="{ backgroundColor: previewColor }"
                                        ><IconPrinter class="icon"
                                    /></span>
                                    <span class="fw-bold">{{ previewName }}</span>
                                </span>
                                <span class="btn btn-sm" :style="{ backgroundColor: previewColor, borderColor: previewColor, color: '#fff' }"
                                    >Button</span
                                >
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-secondary">
                            <img v-if="preview('favicon')" :src="preview('favicon') as string" alt="" style="width: 16px; height: 16px" />
                            <span
                                >Browser tab: <strong>Dashboard - {{ previewName }}</strong></span
                            >
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
