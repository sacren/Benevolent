import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            // Served on a campaign's hostname to one of its supporters, who has
            // no account and no session. It brings its own markup for the same
            // reason it must not fall into the default arm below: AppLayout is
            // the signed-in application shell and would render a sidebar, a
            // campaign navigation and an account menu to a stranger, while the
            // route still answers 200 with the right component name. Not
            // AuthLayout either -- that one carries the platform's logo and a
            // link to the platform's home page, and this is a campaign's public
            // surface rather than the platform's.
            case name === 'Unsubscribe':
                return null;
            // Served centrally rather than in campaign context, but it is a page
            // about signing in and wears the same card as the real thing.
            case name === 'CampaignSignIn':
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
