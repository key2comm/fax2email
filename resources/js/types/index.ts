import type { Component } from 'vue';

export interface Auth {
    user: User;
    can: {
        manageAccounts: boolean;
        manageUsers: boolean;
        manageSettings: boolean;
        viewFaxes: boolean;
        manageNumbers: boolean;
        sendFaxes: boolean;
        useContacts: boolean;
    };
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: Component;
    isActive?: boolean;
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    version: string;
    flash: { status: string | null };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: 'admin' | 'provisioner' | 'account_owner' | 'account_user';
    account_id: number | null;
    account: { id: number; name: string } | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
