/** Configuración de Tailwind CSS (antes estaba inline en cada página con el CDN). */
module.exports = {
  content: [
    './*.php',
    './includes/**/*.php',
    './api/**/*.php',
    './src/**/*.php',
    './config/app.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      // Una sola familia tipográfica: los títulos (font-display) también usan Inter
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        brand: {
          50: '#EEF2FF',
          100: '#E0E7FF',
          200: '#C7D2FE',
          500: '#6366F1',
          600: '#4F46E5',
          700: '#4338CA',
        },
      },
      // Esquinas más contenidas para un aspecto más limpio
      borderRadius: {
        xl: '0.625rem',
        '2xl': '0.75rem',
        '3xl': '1rem',
      },
      // Sombras muy suaves: la jerarquía se marca con bordes finos y espacio, no con sombras
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
