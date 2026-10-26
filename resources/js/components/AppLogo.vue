<script setup lang="ts">
import { useTheme } from '@/composables/useTheme';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { IconPrinter } from '@tabler/icons-vue';
import { computed } from 'vue';

const page = usePage<SharedData>();
const { theme } = useTheme();
const branding = computed(() => page.props.branding);

// The dark-mode logo if there is one, otherwise the regular logo in both themes.
const logo = computed(() => (theme.value === 'dark' && branding.value.logo_dark) || branding.value.logo);
</script>

<template>
    <img v-if="logo" :src="logo" :alt="branding.name" class="navbar-brand-image" />
    <span v-else class="d-inline-flex align-items-center gap-2">
        <span class="avatar avatar-sm bg-primary text-white">
            <IconPrinter class="icon" />
        </span>
        <span class="fw-bold">{{ branding.name }}</span>
    </span>
</template>
