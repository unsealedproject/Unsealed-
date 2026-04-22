# Unsealed

A zero-knowledge family-court accountability platform exposing IV-D revenue extraction, judicial conduct, and state enforcement patterns.

Production: **https://unsealed.is**
Tor: **rdua7tdy2557n23gsvhndykigmvp4lfpchumcu4dcv3s6azu5ejg3xyd.onion**

---

## What this is

A single-page public-records analysis tool that:

- Aggregates every state's HHS OCSE IV-D child-support data (8 fiscal years)
- Maps 190+ family-court jurisdictions and their sitting judges
- Accepts anonymized case submissions via a token-only architecture (no names, no emails, no IPs, no cookies)
- Extracts statistical fields from uploaded court orders via AI (PII is stripped server-side; the PDF is never written to disk)
- Surfaces the structural incentive system: 42 USC 658a / 45 CFR §305 incentive payments, state evader-list paradoxes, cost-effectiveness ratios
- Publishes rep voting records on family-court and SCRA bills
- Generates AI-drafted judicial complaints and rep-contact letters

The full case-aggregation and pattern analysis is live in the browser. The only server-side code is proxies (for third-party APIs we rate-limit) and a submission queue that release-randomizes entries to defeat timestamp correlation.

## Privacy model

- **No cookies, no analytics, no fingerprinting, no referrer retention, no IP logging.**
- Submissions generate a one-time client-side token. We store only a hash mapping, never the submitter's IP.
- Uploaded court orders are sent to Claude Vision for extraction, then discarded — only a SHA-256 hash is kept for dedup.
- Submissions held 1–3 weeks before public release; small jurisdictions get 3–9 weeks. Published timestamps are bucketed to ISO-week, never day-granularity.
- All external requests proxy through our PHP layer (so the user's browser never talks to Anthropic / FEC / Congress.gov / OpenStates directly).

## Tech

- Vanilla HTML/JS/CSS — no build step, no framework
- Self-hosted d3, topojson, Chart.js
- PHP 8.2 FPM + SQLite for the few endpoints that need persistence
- nginx + Let's Encrypt
- Tor hidden service (v3)

## Repository layout

```
family_court_full_platform_v2.html  Main app (everything lives here)
court_map_module.js                 Map rendering + FIN50 dataset
state.html                          Per-state deep-dive page
edit.html                           Submission edit/delete via token

api_*.php                           Server-side proxies
  api_submit.php                    Case-submission intake
  api_token.php                     Token-based read/update/delete
  api_order_verify.php              Claude Vision PDF extractor
  api_press.php                     Journalist inquiry queue
  api_ai.php                        Letter / complaint drafting
  api_stats.php                     Per-judge aggregate counts
  api_census.php                    Census ACS proxy (ZIP → district)
  api_congress.php                  Congress.gov v3 proxy
  api_fec.php                       FEC OpenFEC v1 proxy
  api_courtlistener.php             CourtListener bio lookup
  api_openstates.php                OpenStates v3 proxy
  api_evaders.php                   Per-state evader-count server + scraper
  api_ftm.php                       FollowTheMoney.org proxy (state-level $)
  api_profile.php                   Entity profile submission queue
  api_keys.php                      Shared helpers (rate-limit, HTTP, cache)

judges_verified.json                Judge + jurisdiction roster
appeals_courts.json                 Appellate court roster
acf_state_data.json                 HHS OCSE per-state multi-year data
state_evader_lists.json             Per-state evader registry URLs + metadata
state_evader_counts.json            Counts (scraped or manually verified)
military_pay_2026.json              DFAS basic pay + BAS tables
bah_2026.json                       Housing allowance rates + ZIP→MHA
afcc_public_roster.json             AFCC board members (public-record)
warrant_canary.json                 Warrant canary status
legislation_feed.json               Tracked bills

lib/                                Self-hosted JS libs (d3, topojson, chart.umd)
```

## Running locally

You can clone this and load `family_court_full_platform_v2.html` in a browser to explore the frontend. Endpoints under `/api_*.php` will only work if pointed at a PHP-FPM server with an Anthropic key configured (see `API_KEYS_SETUP.md`).

For contributors: the frontend works standalone for UI changes. Anything that talks to `api_*.php` needs either a local PHP server or a dev tunnel to the production server (ask the maintainer).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Help is welcome on:

- Filling out jurisdictions — there are still ~160 counties waiting for judge rosters
- Scraping each state's "deadbeat parent" / evader registry (14 states still on seed counts)
- Intermediate appellate court rosters (50+ courts have no justices yet)
- State judicial campaign-finance portal scrapers (attorney → judge donations)
- Accessibility (keyboard nav, screen-reader labels on charts)

## License

Public-interest journalism. All statistical analysis and aggregation is published under CC BY-SA 4.0.

Analysis of public officials acting in their official capacity is First Amendment protected activity (*Near v. Minnesota*, 1931; *NYT v. US*, 1971; *Hustler v. Falwell*, 1988).

## Contact

- Journalists: inquiry form at https://unsealed.is/#journalists
- Security issues: see [SECURITY.md](SECURITY.md)
- Everything else: file a GitHub issue or email unsealedproject@proton.me
