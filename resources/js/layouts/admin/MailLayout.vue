<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const tabs = [
    { title: 'Settings', href: '/admin/mail' },
    { title: 'Mail log', href: '/admin/mail/log' },
];

const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <AppLayout title="Mail server" pretitle="Administration">
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs">
                    <li v-for="tab in tabs" :key="tab.href" class="nav-item">
                        <Link :href="tab.href" class="nav-link" :class="{ active: currentPath === tab.href }">{{ tab.title }}</Link>
                    </li>
                </ul>
            </div>
            <slot />
        </div>
    </AppLayout>
</template>
