import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // The shared sleep-stage color map lives here (Tailwind bg-* classes referenced from PHP).
        './app/Support/SleepStages.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope', ...defaultTheme.fontFamily.sans],
                display: ['Archivo', ...defaultTheme.fontFamily.sans],
            },
            // Titan palette — mirrors the native iOS app's Theme.Palette exactly, so web and
            // iOS share one visual language. One accent owns each pillar (see below).
            colors: {
                titan: {
                    bg: '#07070A',      // app canvas (near-black)
                    bg2: '#0E0E13',     // raised surface
                    indigo: '#6D6BF6',  // brand / tint / Sleep
                    cyan: '#22D3EE',    // Strain / HRV / calories
                    mint: '#34E5C0',    // Recovery / good / connected
                    pink: '#FF4D8D',    // Heart / RHR / Fat / low-recovery
                    amber: '#FFB020',   // Fuel / Carbs / warning
                    violet: '#A78BFA',  // Trends / Respiration / REM
                },
            },
            borderRadius: {
                card: '22px',   // GlassCard corner (iOS Radius.card)
                chip: '14px',   // iOS Radius.chip
            },
            boxShadow: {
                card: '0 10px 18px rgba(0,0,0,0.45)',   // iOS GlassCard drop shadow
            },
        },
    },

    plugins: [forms],
};
