import { defineConfig } from 'vitepress'

const hostname = 'https://opconsole.ashraftalha.com'
const ogImage = `${hostname}/screenshots/overview-light.png`

export default defineConfig({
  title: 'Laravel OpConsole',
  description: 'Monitor. Diagnose. Maintain. An operations console for Laravel: health checks, safe Artisan operations, a security audit and a log viewer, all behind Gates.',
  lang: 'en-US',
  cleanUrls: true,
  lastUpdated: true,
  sitemap: {
    hostname,
  },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
    ['link', { rel: 'canonical', href: hostname }],
    ['meta', { name: 'theme-color', content: '#2952e3' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Laravel OpConsole' }],
    ['meta', { property: 'og:title', content: 'Laravel OpConsole — Monitor. Diagnose. Maintain.' }],
    ['meta', { property: 'og:description', content: 'An operations console for Laravel: health checks, safe Artisan operations, a security audit and a log viewer, all behind Gates.' }],
    ['meta', { property: 'og:image', content: ogImage }],
    ['meta', { property: 'og:url', content: hostname }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:title', content: 'Laravel OpConsole' }],
    ['meta', { name: 'twitter:description', content: 'An operations console for Laravel: health checks, safe Artisan operations, a security audit and a log viewer, all behind Gates.' }],
    ['meta', { name: 'twitter:image', content: ogImage }],
  ],

  themeConfig: {
    logo: '/logo.svg',
    siteTitle: 'OpConsole',

    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'Features', link: '/features/health-dashboard' },
      { text: 'Advanced', link: '/advanced/custom-health-checks' },
      { text: 'Reference', link: '/reference/configuration' },
      {
        text: 'v1.11.0',
        items: [
          { text: 'Changelog', link: '/reference/changelog' },
          { text: 'Upgrade guide', link: '/reference/upgrade' },
          { text: 'GitHub Releases', link: 'https://github.com/ashraftalha/laravel-opconsole/releases' },
        ],
      },
    ],

    sidebar: {
      '/guide/': [
        {
          text: 'Getting Started',
          items: [
            { text: 'Introduction', link: '/guide/introduction' },
            { text: 'Requirements & Compatibility', link: '/guide/requirements' },
            { text: 'Installation', link: '/guide/installation' },
            { text: 'Initial Configuration', link: '/guide/initial-configuration' },
            { text: 'Authentication & Authorization', link: '/guide/authentication' },
            { text: 'Quick Start', link: '/guide/quick-start' },
          ],
        },
      ],
      '/features/': [
        {
          text: 'Core Features',
          items: [
            { text: 'Health Dashboard', link: '/features/health-dashboard' },
            { text: 'Health Checks & Health Score', link: '/features/health-checks' },
            { text: 'Cache, Database & Storage', link: '/features/cache-database-storage' },
            { text: 'Queue Worker & Scheduler', link: '/features/queue-scheduler' },
            { text: 'Failed Jobs & Retry', link: '/features/failed-jobs' },
            { text: 'Log Viewer', link: '/features/log-viewer' },
            { text: 'Artisan Actions', link: '/features/artisan-actions' },
            { text: 'Maintenance Operations', link: '/features/maintenance-operations' },
            { text: 'Octane & Swoole', link: '/features/octane-swoole' },
            { text: 'Security Audit', link: '/features/security-checks' },
            { text: 'Operational Audit Trail', link: '/features/audit-trail' },
          ],
        },
      ],
      '/advanced/': [
        {
          text: 'Advanced Topics',
          items: [
            { text: 'Custom Health Checks', link: '/advanced/custom-health-checks' },
            { text: 'Extension Points & Contracts', link: '/advanced/extension-points' },
            { text: 'Production Deployment', link: '/advanced/production-deployment' },
            { text: 'Octane & Swoole Environments', link: '/advanced/octane-swoole-environments' },
            { text: 'Permissions & Security Policies', link: '/advanced/permissions-security-policies' },
            { text: 'Operational Safety', link: '/advanced/operational-safety' },
            { text: 'Performance Considerations', link: '/advanced/performance' },
          ],
        },
      ],
      '/reference/': [
        {
          text: 'Reference',
          items: [
            { text: 'Configuration Reference', link: '/reference/configuration' },
            { text: 'Artisan Commands', link: '/reference/artisan-commands' },
            { text: 'Environment Variables', link: '/reference/environment-variables' },
            { text: 'Routes & Endpoints', link: '/reference/routes' },
            { text: 'Architecture', link: '/reference/architecture' },
            { text: 'Security Model', link: '/reference/security' },
            { text: 'Supported Versions', link: '/reference/supported-versions' },
            { text: 'Troubleshooting', link: '/reference/troubleshooting' },
            { text: 'FAQ', link: '/reference/faq' },
            { text: 'Upgrade Guide', link: '/reference/upgrade' },
            { text: 'Changelog', link: '/reference/changelog' },
          ],
        },
      ],
    },

    search: {
      provider: 'local',
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/ashraftalha/laravel-opconsole' },
    ],

    editLink: {
      pattern: 'https://github.com/ashraftalha/laravel-opconsole/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © Ashraf Talha',
    },

    outline: {
      level: [2, 3],
    },
  },
})
