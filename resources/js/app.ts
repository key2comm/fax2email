import '../css/app.css';
// Tabler's JS bundles Bootstrap; it wires up dropdowns, the mobile menu and tooltips.
import '@tabler/core';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useTheme';

// The app's name from Settings > Branding, kept current as the user navigates.
let appName = 'Fax2Email';
router.on('navigate', (event) => {
    appName = (event.detail.page.props.branding as { name: string } | undefined)?.name ?? appName;
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        appName = (props.initialPage.props.branding as { name: string } | undefined)?.name ?? appName;
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#066fd1',
    },
});

initializeTheme();
