import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel MatrixOne',
  description: 'A MatrixOne database driver for Laravel with Eloquent, Query Builder, Schema Builder and migrations.',
  base: '/laravel-matrixone/',

  themeConfig: {
    nav: [
      { text: 'Docs', link: '/docs/installation' },
      {
        text: 'Resources',
        items: [
          { text: 'GitHub', link: 'https://github.com/vuthaihoc/laravel-matrixone' },
          { text: 'Packagist', link: 'https://packagist.org/packages/vuthaihoc/laravel-matrixone' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/docs/installation' },
          { text: 'Running MatrixOne (Docker)', link: '/docs/docker' },
        ],
      },
      {
        text: 'Usage',
        items: [
          { text: 'Query Builder', link: '/docs/query-builder' },
          { text: 'Eloquent', link: '/docs/eloquent' },
          { text: 'Schema', link: '/docs/schema' },
          { text: 'Full-text Search', link: '/docs/full-text' },
          { text: 'Testing', link: '/docs/testing' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'MatrixOne Compatibility', link: '/docs/compatibility' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/vuthaihoc/laravel-matrixone' },
    ],

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/vuthaihoc/laravel-matrixone/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
