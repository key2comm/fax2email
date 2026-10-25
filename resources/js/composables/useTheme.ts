import { ref } from 'vue';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'theme';

function readSaved(): Theme | null {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        return saved === 'light' || saved === 'dark' ? saved : null;
    } catch {
        return null;
    }
}

function systemTheme(): Theme {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

const theme = ref<Theme>(readSaved() ?? systemTheme());

function apply(value: Theme) {
    document.documentElement.setAttribute('data-bs-theme', value);
}

/**
 * Applies the saved theme, or the operating system's until the user picks one.
 * resources/views/app.blade.php does the same before the page paints, so there
 * is no flash of the wrong theme.
 */
export function initializeTheme() {
    apply(theme.value);

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (readSaved() === null) {
            theme.value = systemTheme();
            apply(theme.value);
        }
    });
}

export function useTheme() {
    function setTheme(value: Theme) {
        theme.value = value;
        apply(value);
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // Storage blocked (e.g. private mode): the choice lasts until the page reloads.
        }
    }

    return {
        theme,
        setTheme,
        toggleTheme: () => setTheme(theme.value === 'dark' ? 'light' : 'dark'),
    };
}
