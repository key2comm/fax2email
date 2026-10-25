<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { IconLock, IconUser } from '@tabler/icons-vue';
import { computed } from 'vue';

const items = [
    { title: 'Profile', href: '/settings/profile', icon: IconUser },
    { title: 'Password', href: '/settings/password', icon: IconLock },
];

const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <AppLayout title="Your account" pretitle="Settings">
        <div class="card">
            <div class="row g-0">
                <div class="col-12 col-md-3 border-end">
                    <div class="card-body">
                        <div class="list-group list-group-transparent">
                            <Link
                                v-for="item in items"
                                :key="item.href"
                                :href="item.href"
                                class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                                :class="{ active: currentPath === item.href }"
                            >
                                <component :is="item.icon" class="icon" />
                                {{ item.title }}
                            </Link>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-9">
                    <slot />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
