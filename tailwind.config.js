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
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', 'sans-serif'],
      },
      colors: {
        brand: {
          50: '#EEF2FF',
          100: '#E0E7FF',
          500: '#6366F1',
          600: '#4F46E5',
          700: '#4338CA',
        },
      },
    },
  },
  plugins: [],
};
