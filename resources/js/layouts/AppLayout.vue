<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { useInitials } from '@/composables/useInitials';
import { useTheme } from '@/composables/useTheme';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    IconAddressBook,
    IconBuilding,
    IconFileText,
    IconHash,
    IconLayoutDashboard,
    IconLock,
    IconLogout,
    IconMail,
    IconMoon,
    IconPlugConnected,
    IconSend,
    IconShield,
    IconSun,
    IconUser,
    IconUsers,
} from '@tabler/icons-vue';
import { computed } from 'vue';

defineProps<{
    title: string;
    pretitle?: string;
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const { theme, toggleTheme } = useTheme();
const { getInitials } = useInitials();

const roleLabels: Record<string, string> = {
    admin: 'Admin',
    provisioner: 'Provisioner',
    account_owner: 'Account Owner',
    account_user: 'Account User',
};

const menu = computed<NavItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard', icon: IconLayoutDashboard },
    ...(page.props.auth.can.sendFaxes ? [{ title: 'Send fax', href: '/send', icon: IconSend }] : []),
    ...(page.props.auth.can.viewFaxes ? [{ title: 'Faxes', href: '/faxes', icon: IconFileText }] : []),
    ...(page.props.auth.can.useContacts ? [{ title: 'Address book', href: '/contacts', icon: IconAddressBook }] : []),
    ...(page.props.auth.can.manageNumbers ? [{ title: 'Fax numbers', href: '/numbers', icon: IconHash }] : []),
    ...(page.props.auth.can.manageAccounts ? [{ title: 'Accounts', href: '/accounts', icon: IconBuilding }] : []),
    ...(page.props.auth.can.manageUsers ? [{ title: 'Users', href: '/users', icon: IconUsers }] : []),
    ...(page.props.auth.can.manageSettings
        ? [
              { title: 'Mail server', href: '/admin/mail', icon: IconMail },
              { title: 'SIP trunks', href: '/admin/trunks', icon: IconPlugConnected },
              { title: 'Firewall', href: '/admin/firewall', icon: IconShield },
          ]
        : []),
]);

const currentPath = computed(() => page.url.split('?')[0]);
const isActive = (href: string) => currentPath.value === href || currentPath.value.startsWith(`${href}/`);
</script>

<template>
    <div class="page">
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="navbar-brand navbar-brand-autodark pe-0 pe-md-3">
                    <Link :href="route('dashboard')" class="text-reset text-decoration-none">
                        <AppLogo />
                    </Link>
                </div>

                <div class="navbar-nav flex-row order-md-last">
                    <div class="nav-item me-2">
                        <button
                            type="button"
                            class="nav-link px-2 border-0 bg-transparent"
                            :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                            :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                            @click="toggleTheme"
                        >
                            <IconSun v-if="theme === 'dark'" class="icon" />
                            <IconMoon v-else class="icon" />
                        </button>
                    </div>

                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 p-0 px-2" data-bs-toggle="dropdown" aria-label="Open user menu">
                            <span class="avatar avatar-sm">{{ getInitials(user.name) }}</span>
                            <div class="d-none d-xl-block ps-2">
                                <div>{{ user.name }}</div>
                                <div class="mt-1 small text-secondary">
                                    {{ roleLabels[user.role] }}<template v-if="user.account"> · {{ user.account.name }}</template>
                                </div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <Link :href="route('profile.edit')" class="dropdown-item">
                                <IconUser class="icon dropdown-item-icon" />
                                Profile
                            </Link>
                            <Link :href="route('password.edit')" class="dropdown-item">
                                <IconLock class="icon dropdown-item-icon" />
                                Password
                            </Link>
                            <div class="dropdown-divider"></div>
                            <Link :href="route('logout')" method="post" as="button" class="dropdown-item">
                                <IconLogout class="icon dropdown-item-icon" />
                                Log out
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <header class="navbar-expand-md">
            <div class="collapse navbar-collapse" id="navbar-menu">
                <div class="navbar">
                    <div class="container-xl">
                        <ul class="navbar-nav">
                            <li v-for="item in menu" :key="item.href" class="nav-item" :class="{ active: isActive(item.href) }">
                                <Link class="nav-link" :href="item.href">
                                    <span class="nav-link-icon d-md-none d-lg-inline-block">
                                        <component :is="item.icon" class="icon" />
                                    </span>
                                    <span class="nav-link-title">{{ item.title }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-wrapper">
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div v-if="pretitle" class="page-pretitle">{{ pretitle }}</div>
                            <h2 class="page-title">{{ title }}</h2>
                        </div>
                        <div v-if="$slots.actions" class="col-auto ms-auto d-print-none">
                            <slot name="actions" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-body">
                <div class="container-xl">
                    <div v-if="page.props.flash.status" class="alert alert-success" role="alert">
                        {{ page.props.flash.status }}
                    </div>
                    <slot />
                </div>
            </div>

            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl text-secondary small">{{ page.props.name }} · v{{ page.props.version }}</div>
            </footer>
        </div>
    </div>
</template>
