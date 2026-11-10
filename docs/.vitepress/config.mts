import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { defineConfig } from 'vitepress'

const hostname = 'https://opconsole.ashraftalha.com'
const ogImage = `${hostname}/screenshots/overview-light.png`
const siteDescription = 'Monitor. Diagnose. Maintain. An operations console for Laravel: health checks, safe Artisan operations, a security audit and a log viewer, all behind Gates.'

// Single source of truth for the version label: the newest released heading in CHANGELOG.md.
const changelog = readFileSync(new URL('../../CHANGELOG.md', import.meta.url), 'utf8')
const version = changelog.match(/^## \[(\d+\.\d+\.\d+)\]/m)?.[1]
if (!version) throw new Error('No released version heading (## [x.y.z]) found in CHANGELOG.md')

/** First prose paragraph of a page as plain text, for its meta description. */
function firstParagraph(markdown: string): string | undefined {
  const body = markdown.replace(/^---\n[\s\S]*?\n---\n/, '').replace(/```[\s\S]*?```/g, '')
  const block = body.split(/\n\s*\n/).map(b => b.trim())
    .find(b => b && !/^(#|\||<|:::|[-*>]\s|\d+\.\s|!\[)/.test(b))
  if (!block) return undefined
  const text = block
    .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\*\*|__|[*`]/g, '') // single "_" stays: it appears in config keys
    .replace(/<[^>]+>/g, '')
    .replace(/\s+/g, ' ')
    .trim()
  return text.length <= 160 ? text : text.slice(0, 157).replace(/\s+\S*$/, '') + '…'
}

export default defineConfig({
  title: 'Laravel OpConsole',
  description: siteDescription,
  lang: 'en-US',
  cleanUrls: true,
  lastUpdated: true,
  sitemap: {
    hostname,
  },

  head: [
    // favicon.svg adapts to dark browser UI; favicon.ico (16/32/48) covers Safari and Google's favicon crawler.
    ['link', { rel: 'icon', href: '/favicon.ico', sizes: '48x48' }],
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    ['link', { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' }],
    ['meta', { name: 'theme-color', content: '#2952e3' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Laravel OpConsole' }],
    ['meta', { property: 'og:image', content: ogImage }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:image', content: ogImage }],
  ],

  // Per-page canonical URL, Open Graph/Twitter title and description. A site-wide canonical
  // pointing at the home page would tell search engines every page is a duplicate of it.
  transformPageData(pageData, { siteConfig }) {
    const path = pageData.relativePath.replace(/(^|\/)index\.md$/, '$1').replace(/\.md$/, '')
    const url = `${hostname}/${path}`
    if (!pageData.frontmatter.description && pageData.relativePath !== 'index.md') {
      pageData.description = firstParagraph(readFileSync(join(siteConfig.srcDir, pageData.relativePath), 'utf8')) ?? siteDescription
    }
    const title = pageData.relativePath === 'index.md' ? 'Laravel OpConsole — Monitor. Diagnose. Maintain.' : `${pageData.title} | Laravel OpConsole`
    const description = pageData.description || siteDescription

    pageData.frontmatter.head ??= []
    pageData.frontmatter.head.push(
      ['link', { rel: 'canonical', href: url }],
      ['meta', { property: 'og:url', content: url }],
      ['meta', { property: 'og:title', content: title }],
      ['meta', { property: 'og:description', content: description }],
      ['meta', { name: 'twitter:title', content: title }],
      ['meta', { name: 'twitter:description', content: description }],
    )
  },

  themeConfig: {
    // Square mark as the image; the name is rendered as text by the theme (theme/index.ts).
    logo: { light: '/logo-mark.svg', dark: '/logo-mark-dark.svg', alt: '' },
    siteTitle: false,

    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'Features', link: '/features/health-dashboard' },
      { text: 'Advanced', link: '/advanced/custom-health-checks' },
      { text: 'Reference', link: '/reference/configuration' },
      {
        text: `v${version}`,
        items: [
          { text: 'Changelog', link: '/reference/changelog' },
          { text: 'Upgrade guide', link: '/reference/upgrade' },
          { text: 'GitHub Releases', link: 'https://github.com/ashraftalha/laravel-opconsole/releases' },
        ],
      },
    ],

    // One sidebar for the whole site: every page in every section, each group collapsible.
    sidebar: [
      {
        text: 'Getting Started',
        collapsed: false,
        items: [
          { text: 'Introduction', link: '/guide/introduction' },
          { text: 'Requirements & Compatibility', link: '/guide/requirements' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Initial Configuration', link: '/guide/initial-configuration' },
          { text: 'Authentication & Authorization', link: '/guide/authentication' },
          { text: 'Quick Start', link: '/guide/quick-start' },
        ],
      },
      {
        text: 'Core Features',
        collapsed: false,
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
      {
        text: 'Advanced Topics',
        collapsed: false,
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
      {
        text: 'Reference',
        collapsed: false,
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
