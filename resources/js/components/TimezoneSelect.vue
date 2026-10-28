<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    id: string;
    timezones: string[];
    error?: string;
    /** What the zone is used for, shown above the current time there. */
    hint: string;
}>();

const zone = defineModel<string>({ required: true });

// The zone this browser is in, offered as a one-click choice.
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const zoneFilter = ref('');
const zones = computed(() => {
    const term = zoneFilter.value.trim().toLowerCase().replace(/\s+/g, '_');
    const list = term ? props.timezones.filter((z) => z.toLowerCase().includes(term)) : props.timezones;

    return list.includes(zone.value) ? list : [zone.value, ...list];
});
const now = (z: string) => new Intl.DateTimeFormat(undefined, { timeZone: z, timeStyle: 'short' }).format(new Date());
</script>

<template>
    <div class="row g-2">
        <div class="col-md-4">
            <input v-model="zoneFilter" type="search" class="form-control" placeholder="Search, e.g. Toronto" aria-label="Search time zones" />
        </div>
        <div class="col-md-8">
            <select :id="id" v-model="zone" class="form-select" :class="{ 'is-invalid': error }">
                <option v-for="z in zones" :key="z" :value="z">{{ z.replace(/_/g, ' ') }}</option>
            </select>
        </div>
    </div>
    <InputError :message="error" />
    <small class="form-hint mt-1">
        {{ hint }} It is {{ now(zone) }} there now.
        <button v-if="browserZone && browserZone !== zone" type="button" class="btn btn-link p-0 align-baseline" @click="zone = browserZone">
            Use this computer's time zone ({{ browserZone.replace(/_/g, ' ') }})
        </button>
    </small>
</template>
