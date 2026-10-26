import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Dates come from the server in UTC (ISO 8601). Show them in the time zone the
 * user chose under Profile (UTC unless they changed it), with the zone's
 * abbreviation so it is always clear which zone a time is in.
 */
export function useDateTime() {
    const page = usePage<SharedData>();
    const timeZone = () => page.props.auth.user?.timezone || 'UTC';

    const format = (iso: string | null | undefined, options: Intl.DateTimeFormatOptions) =>
        iso ? new Intl.DateTimeFormat(undefined, { timeZone: timeZone(), ...options }).format(new Date(iso)) : '';

    return {
        timeZone,
        /** e.g. "Oct 25, 2026, 9:52 PM EDT" */
        formatDateTime: (iso: string | null | undefined) => format(iso, { dateStyle: 'medium', timeStyle: 'short' }) + zoneSuffix(iso),
        /** e.g. "Oct 25, 2026" */
        formatDate: (iso: string | null | undefined) => format(iso, { dateStyle: 'medium' }),
        /** e.g. "9:52 PM EDT" */
        formatTime: (iso: string | null | undefined) => format(iso, { timeStyle: 'short' }) + zoneSuffix(iso),
    };

    function zoneSuffix(iso: string | null | undefined) {
        if (!iso) {
            return '';
        }
        const zone = new Intl.DateTimeFormat('en-US', { timeZone: timeZone(), timeZoneName: 'short' })
            .formatToParts(new Date(iso))
            .find((part) => part.type === 'timeZoneName')?.value;

        return zone ? ` ${zone}` : '';
    }
}
