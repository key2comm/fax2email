<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';

defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4 fs-2">
                <AppLogo />
            </div>
            <div class="card card-md">
                <div class="card-body">
                    <h2 class="h2 text-center mb-2">{{ title }}</h2>
                    <p v-if="description" class="text-secondary text-center mb-4">{{ description }}</p>
                    <slot />
                </div>
            </div>
            <div v-if="$slots.footer" class="text-center text-secondary mt-3">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>
