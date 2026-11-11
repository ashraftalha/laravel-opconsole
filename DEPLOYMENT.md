# Documentation Site Deployment (Cloudflare Workers)

This covers deploying the VitePress documentation site in `docs/` to `opconsole.ashraftalha.com`. Cloudflare Workers Builds deploys it as an assets-only Worker named `laravel-opconsole`; the "Workers Builds: laravel-opconsole" check on each `main` commit is that deployment. It does not affect the Composer package, which is installed and versioned independently of this site.

## Worker configuration

`wrangler.jsonc` in the repository root is the deployment configuration:

| Key | Value | Why |
|---|---|---|
| `name` | `laravel-opconsole` | Must match the Worker's name in the Cloudflare dashboard, or Workers Builds rejects the deploy. |
| `assets.directory` | `./docs/.vitepress/dist` | The VitePress build output. |
| `assets.not_found_handling` | `404-page` | Unknown paths get VitePress's `404.html` with a `404` status. Without it, Cloudflare returns an empty `404` body. |

It is export-ignored, so it never ships in the Composer package.

## One-time Workers Builds setup (manual, via dashboard)

1. **Cloudflare dashboard → Workers & Pages → `laravel-opconsole` → Settings → Build**, connected to the `ashraftalha/laravel-opconsole` GitHub repository.
2. Configure the build:

   | Setting | Value |
   |---|---|
   | Production branch | `main` |
   | Root directory | `/` |
   | Build command | `npm run docs:build` |
   | Deploy command | `npx wrangler deploy` (the default; reads `wrangler.jsonc`) |

3. Node version: the repository pins Node via `.nvmrc` (`20`). If the build uses a different version, set the build variable `NODE_VERSION=20`.
4. Cloudflare builds and deploys on every push to `main`.

## Custom domain and DNS

1. In the Worker, go to **Settings → Domains & Routes → Add → Custom domain** and enter `opconsole.ashraftalha.com`.
2. With `ashraftalha.com` on Cloudflare DNS, Cloudflare creates the DNS record and TLS certificate for the custom domain automatically.

**No DNS changes have been made as part of this work** — this is a manual, authorized step for whoever controls the `ashraftalha.com` zone.

## Local development and verification

```bash
npm install        # installs VitePress (devDependency only, does not touch composer.json)
npm run docs:dev      # local dev server with hot reload
npm run docs:build    # production build -> docs/.vitepress/dist
npm run docs:preview  # serve the production build locally
```

To check Cloudflare's routing (404 page, redirects) before deploying, build and run `npx wrangler dev` from the repository root. It serves `docs/.vitepress/dist` with the settings in `wrangler.jsonc`; its local state in `.wrangler/` is gitignored.

## What ships to the site vs. what doesn't

- `docs/**/*.md`, `docs/.vitepress/config.mts`, and `docs/public/**` are the site source — all committed.
- `docs/.vitepress/dist` (build output) and `docs/.vitepress/cache` are gitignored; Cloudflare generates them fresh on every build.
- `node_modules` is gitignored; Cloudflare runs `npm install` itself before the build command.
- The Composer package (`src/`, `config/`, `routes/`, `tests/`) is entirely unaffected by this site and continues to install via `composer require ashraftalha/laravel-opconsole` as before.

## Redirects / clean URLs

`cleanUrls: true` is set in `docs/.vitepress/config.mts`, so pages are served without `.html` (for example `/guide/installation`). Cloudflare's default HTML handling (`auto-trailing-slash`) serves `guide/installation.html` for that path and redirects `/guide/installation.html` and `/guide/installation/` to it. Cloudflare uses `307` for these redirects and the status is not configurable; every page's canonical URL is the clean URL, and the site never links to the other forms.
