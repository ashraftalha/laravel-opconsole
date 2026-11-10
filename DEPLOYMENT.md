# Documentation Site Deployment (Cloudflare Pages)

This covers deploying the VitePress documentation site in `docs/` to Cloudflare Pages at `opconsole.ashraftalha.com`. It does not affect the Composer package, which is installed and versioned independently of this site.

## One-time Cloudflare Pages setup (manual, via dashboard)

1. **Cloudflare dashboard → Workers & Pages → Create → Pages → Connect to Git.**
2. Select the `ashraftalha/laravel-opconsole` GitHub repository and authorize Cloudflare's GitHub integration (preferred over any custom FTP/CI-based deploy workflow).
3. Configure the build:

   | Setting | Value |
   |---|---|
   | Production branch | `main` |
   | Build command | `npm run docs:build` |
   | Build output directory | `docs/.vitepress/dist` |
   | Root directory | `/` |

4. Node version: the repository pins Node via `.nvmrc` (`20`). Cloudflare Pages reads `.nvmrc` automatically; if it doesn't, set the environment variable `NODE_VERSION=20` in the Pages project's build settings.
5. Save and deploy. Cloudflare builds automatically on every push to `main`, and generates preview deployments for pull requests.

## Custom domain and DNS

1. In the Pages project, go to **Custom domains → Set up a custom domain** and enter `opconsole.ashraftalha.com`.
2. If `ashraftalha.com` is already on Cloudflare DNS, Cloudflare offers to add the CNAME automatically. Otherwise, add manually at your DNS provider:

   | Type | Name | Target |
   |---|---|---|
   | CNAME | `opconsole` | `<your-project>.pages.dev` |

3. Cloudflare issues and manages the TLS certificate for the custom domain automatically once DNS resolves.

**No DNS changes have been made as part of this work** — this is a manual, authorized step for whoever controls the `ashraftalha.com` zone.

## Local development and verification

```bash
npm install        # installs VitePress (devDependency only, does not touch composer.json)
npm run docs:dev      # local dev server with hot reload
npm run docs:build    # production build -> docs/.vitepress/dist
npm run docs:preview  # serve the production build locally
```

## What ships to the site vs. what doesn't

- `docs/**/*.md`, `docs/.vitepress/config.mts`, and `docs/public/**` are the site source — all committed.
- `docs/.vitepress/dist` (build output) and `docs/.vitepress/cache` are gitignored; Cloudflare generates them fresh on every build.
- `node_modules` is gitignored; Cloudflare runs `npm install` itself before the build command.
- The Composer package (`src/`, `config/`, `routes/`, `tests/`) is entirely unaffected by this site and continues to install via `composer require ashraftalha/laravel-opconsole` as before.

## Redirects / clean URLs

`cleanUrls: true` is set in `docs/.vitepress/config.mts`, so pages are served without a trailing `.html` (for example `/guide/installation`, not `/guide/installation.html`). Cloudflare Pages serves static files as-is and resolves these correctly without any additional `_redirects` configuration, because VitePress's static build already emits the directory structure `cleanUrls` needs (e.g. `guide/installation.html` is requested as `guide/installation`, which most static hosts — including Cloudflare Pages — resolve to the `.html` file automatically). If you observe 404s on direct navigation to a clean URL after deploying, add a `docs/public/_redirects` file with a catch-all rule; this has not been necessary in local `vitepress preview` testing.
