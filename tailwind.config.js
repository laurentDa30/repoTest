import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                gold: {
                    DEFAULT: '#B8956A',
                    dark: '#A07D58',
                    light: '#D4B896',
                },
                cream: '#F5F0EB',
                warm: '#E8DDD3',
            },
        },
    },

    plugins: [forms],
};
