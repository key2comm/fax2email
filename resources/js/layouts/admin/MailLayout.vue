<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const tabs = [
    { title: 'Settings', href: '/admin/mail' },
    { title: 'Mail log', href: '/admin/mail/log' },
];

const page = usePage<SharedData>();
const currentPath = computed(() => page.url.split('?')[0]);
const current = computed(() => tabs.find((tab) => tab.href === currentPath.value) ?? tabs[0]);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Mail server', href: '/admin/mail' },
    { title: current.value.title, href: current.value.href },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <Heading title="Mail server" description="Outgoing email for invitations, password resets and received faxes." />

            <nav class="-mt-6 flex gap-1 border-b">
                <Link
                    v-for="tab in tabs"
                    :key="tab.href"
                    :href="tab.href"
                    :class="
                        cn(
                            '-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                            tab.href === currentPath
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )
                    "
                >
                    {{ tab.title }}
                </Link>
            </nav>

            <slot />
        </div>
    </AppLayout>
</template>
