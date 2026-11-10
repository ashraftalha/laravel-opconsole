// Fails when the docs mention a "current" version other than the newest release in CHANGELOG.md.
// The nav label is derived from CHANGELOG.md in config.mts; these pages state it in prose.
import { readFileSync } from 'node:fs'

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
const version = read('CHANGELOG.md').match(/^## \[(\d+\.\d+\.\d+)\]/m)?.[1]
if (!version) {
  console.error('CHANGELOG.md has no released version heading (## [x.y.z]).')
  process.exit(1)
}

const checks = [
  ['docs/reference/supported-versions.md', /\| Current release \| `v([\d.]+)` \|/, 'the "Current release" row'],
  ['docs/reference/upgrade.md', /upgrades to the current `v([\d.]+)` release/, 'the intro sentence'],
  ['docs/reference/upgrade.md', /^## Upgrade from v[\d.]+ to v([\d.]+)/m, 'the first upgrade section'],
  ['docs/reference/changelog.md', /^\*\*v([\d.]+)\*\*/m, 'the first "Latest release" entry'],
  ['RELEASE-CHECKLIST.md', /^## Release Scope: `v([\d.]+)`/m, 'the release scope heading'],
]

let failed = false
for (const [file, pattern, where] of checks) {
  const found = read(file).match(pattern)?.[1]
  if (found !== version) {
    console.error(`${file}: ${where} says ${found ? `v${found}` : 'nothing'}, expected v${version} (CHANGELOG.md).`)
    failed = true
  }
}

if (failed) process.exit(1)
console.log(`Docs version references match CHANGELOG.md (v${version}).`)
