module.exports = {
  content: ['./resources/views/report/**/*.blade.php'],
  theme: {
    extend: {
      colors: {
        navy: '#301C47',
        'light-blue': '#E3F0FF',
        // Brand accent (historically named "teal"); now the brand indigo #4654A4.
        teal: '#4654A4',
        'light-grey': '#FAF8FF',
      },
    },
  },
  plugins: [],
};
