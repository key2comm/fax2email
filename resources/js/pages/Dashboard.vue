<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { IconInbox, IconSend } from '@tabler/icons-vue';

const page = usePage<SharedData>();
const user = page.props.auth.user;
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :title="`Welcome, ${user.name}`" :pretitle="user.account ? user.account.name : 'Staff portal'">
        <div class="row row-deck row-cards">
            <div class="col-sm-6">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-primary text-white avatar"><IconInbox class="icon" /></span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">Received faxes</div>
                                <div class="text-secondary">Coming soon</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-green text-white avatar"><IconSend class="icon" /></span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">Sent faxes</div>
                                <div class="text-secondary">Coming soon</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="empty">
                            <div class="empty-icon"><IconInbox class="icon" /></div>
                            <p class="empty-title">No faxes yet</p>
                            <p class="empty-subtitle text-secondary">Received and sent faxes will appear here once fax numbers are set up.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
