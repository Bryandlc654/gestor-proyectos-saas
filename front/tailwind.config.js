/** Configuración Tailwind del front React, alineada con la identidad Vértice. */
const brand = {
  50: '#EEF4FF', 100: '#DCE9FF', 200: '#BCD6FF', 300: '#8FBCFF', 400: '#4F95FF',
  500: '#1573FF', 600: '#0E66FF', 700: '#0A52D1', 800: '#0B44A8', 900: '#0D3B85', 950: '#072A5B',
};
const navy = {
  50: '#F0F5FB', 100: '#DDE7F4', 200: '#BFD2E8', 300: '#93B3D6', 400: '#5F8DBE',
  500: '#3B6DA5', 600: '#2A5588', 700: '#1D456F', 800: '#123A63', 900: '#072A5B', 950: '#041C3D',
};
const cyan = {
  50: '#E9FBFF', 100: '#CFF5FF', 200: '#A5ECFE', 300: '#67E0FB', 400: '#2ECDF6',
  500: '#15BDF4', 600: '#0A9FD6', 700: '#0C7FAE', 800: '#10688F', 900: '#125676', 950: '#0B3F59',
};

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
      colors: { brand, navy, cyan },
      borderRadius: { xl: '0.625rem', '2xl': '0.75rem', '3xl': '1rem' },
      boxShadow: {
        '2xs': '0 1px 1px 0 rgb(15 23 42 / 0.03)',
        sm: '0 1px 2px 0 rgb(15 23 42 / 0.04)',
        DEFAULT: '0 1px 3px 0 rgb(15 23 42 / 0.06)',
        md: '0 2px 6px -1px rgb(15 23 42 / 0.06)',
        lg: '0 8px 24px -6px rgb(15 23 42 / 0.10)',
        xl: '0 12px 32px -8px rgb(15 23 42 / 0.12)',
        '2xl': '0 24px 48px -12px rgb(15 23 42 / 0.18)',
      },
    },
  },
  plugins: [],
};
