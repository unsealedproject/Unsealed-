# Contributing to Unsealed

Welcome. This project is a public-records accountability tool for family court, and we take privacy, data integrity, and the zero-knowledge architecture seriously. If you're contributing, please read all of this before opening a PR.

## The zero-knowledge guarantee

The platform promises users:

- No names, emails, IPs, or identifying metadata collected
- No cookies, no analytics, no fingerprinting, no referrer logging
- Submissions hashed into a token — only the token holder can edit/delete
- Uploaded court orders never persisted to disk (hash only, for dedup)
- Submissions released 1–3 weeks later in randomized batches (3–9 weeks for small jurisdictions) so timestamp correlation is impossible
- External API calls proxy through our PHP layer; the user's browser never hits Anthropic/FEC/Congress/etc directly

**Any PR that weakens any of these guarantees will be rejected.** No exceptions. If your change touches data collection, persistence, logging, analytics, third-party scripts, browser storage, or response headers — flag it clearly in the PR description so the maintainer can review the privacy impact.

## How to contribute

### Easiest: add a judge roster for a missing county

Most of the pending work is filling out jurisdictions. Check `judges_verified.json` — counties with `"status": "pending"` or `"judges": []` need data.

Look at `_merge_batch20.py` (in `.gitignore` but the maintainer will share it on request) for the template. Source every judge from an official court directory and tag `"dataQuality": "HIGH"`. PRs that add invented or speculative names will not be merged.

### Medium: state evader-list scraper

`api_evaders.php` has a scraper for Texas. 14 other states publish "deadbeat parent" lists but their pages are server-rendered HTML. We need per-state parsers to extract the count (a simple regex is usually enough). The URLs are in `state_evader_lists.json`.

### Medium: intermediate appellate court judges

`appeals_courts.json` has 160+ state appeals courts. The 49 supreme courts have justices populated; every intermediate court is still empty. Same template as judge rosters.

### Advanced: state judicial campaign-finance scrapers

Each of the 39 states that elects family-court judges publishes contribution filings. The data lives in 50 different state portals. If you know one state well, a scraper that maps "attorney X donated $Y to judge Z within 2 years of a case" is one of the most valuable contributions you could make.

### Advanced: accessibility

- Keyboard-only nav through tabs
- ARIA labels on the chart elements
- Screen-reader narration of key stats
- Color-blind-safe palette audit (the IV-D tier bands are currently red/orange/gold/green/teal — probably not colorblind-friendly)

### Reporting bugs

Open a GitHub issue with:

1. Browser + version
2. Exact URL / tab
3. Steps to reproduce
4. What happened vs what you expected
5. Screenshot if visual

**Do NOT include any submission token, personal case details, or anyone's PII in an issue.** Issues are public.

## Local setup

The frontend works standalone — just open `family_court_full_platform_v2.html` in a browser. Anything under `/api_*.php` will 404 locally unless you have PHP-FPM running.

For full-stack dev, you'll need:

- PHP 8.2+ with `sqlite3`, `curl`, `json`, `mbstring`
- nginx (or Apache with rewrites)
- An Anthropic API key if you want the AI-extraction endpoints to work
- Copies of the JSON data files

Ping the maintainer for a sample `api_keys.env` template.

## Commit & PR guidelines

- Keep commits focused — one change per commit
- PR title should describe the change in a sentence: "Add judge roster for Travis County, TX"
- PRs touching user-facing copy (errors, labels, privacy promises) should be reviewed especially carefully — our language is what sets expectations
- No dependency additions without discussion. We're deliberately framework-free — adding React/Vue/build tooling is an architectural change, not a minor PR
- Test your change with devtools open, watching the Network tab. If you see any request going off-domain that our privacy model doesn't cover, stop and reconsider

## What's explicitly out of scope

- User accounts, logins, profiles, social features — the zero-knowledge guarantee forbids this
- Comments or discussion threads on submissions (same reason)
- Any telemetry, analytics, heatmaps, or user-behavior tracking
- Third-party embed widgets (Disqus, Facebook, etc.) — CSP blocks them anyway
- Non-public PII of any kind. Names of private parties, case numbers, DOBs, addresses, children's names do not go in this codebase

## Code style

- Vanilla JS, no transpiler, targeting modern evergreen browsers (Chrome/Firefox/Edge/Safari current)
- PHP 8.2 idioms — use prepared statements, htmlspecialchars on output, no `eval` / `system` / `shell_exec`
- One file, one purpose — the big HTML is an exception (it's the SPA by design)
- Comments explain WHY, not WHAT. If it's non-obvious why a line is the way it is, say so

## Security reports

Please do NOT open a public issue for security vulnerabilities. Email unsealedproject@proton.me with details. See [SECURITY.md](SECURITY.md) for scope and disclosure policy.

## License on your contributions

By submitting a PR, you agree your contribution is released under the same terms as the project (CC BY-SA 4.0 for data/analysis, same for code).
