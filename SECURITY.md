# Security policy

## Supported versions

Unsealed is a single live deployment at **unsealed.is**. The main branch is always the source of truth for what's running in production.

## Reporting a vulnerability

If you discover a security issue — anything that could compromise the zero-knowledge architecture, leak submitter data, expose credentials, or let an attacker execute code / bypass auth — please **do NOT open a public GitHub issue.**

Instead, email **unsealedproject@proton.me** with:

1. A description of the vulnerability
2. Steps to reproduce (or a proof-of-concept — but do NOT attack real user data)
3. The potential impact as you see it
4. Your preferred contact / credit name (or "anonymous" if you prefer)

We'll acknowledge receipt within 72 hours. If the issue is verified and in scope, we'll work on a fix and coordinate disclosure with you. Public acknowledgment in release notes is offered if desired.

## Scope

### In scope
- Any server-side RCE, SQLi, SSRF, LFI, authentication bypass
- Any leak of submission data (the SQLite DB, individual tokens, or order-hash log)
- Any way to deanonymize a submitter from released data
- Any third-party call that leaks the user's IP or payload without proxying
- Any configuration flaw that bypasses the Tor hidden service's isolation
- CSP bypass, missing security header, insecure cookie (we don't set cookies, so any cookie header is a finding)
- Anything that writes the user's IP, User-Agent, Referer, or other identifying header to persistent storage
- Client-side fingerprinting surface we missed

### Out of scope
- Denial-of-service via sustained high-volume traffic (we rely on fail2ban + rate limiting)
- Social-engineering of the operator
- Physical access to the server
- Vulnerabilities in upstream dependencies that we have no reasonable mitigation for
- Self-XSS / local-only attacks
- Missing security headers that don't apply to our threat model (we deliberately don't set some — HPKP, etc.)

## Safe-harbor

Good-faith security research that follows this policy will not lead to legal action. We ask that you:

- Stay within the scope above
- Don't access submitter data beyond what's necessary to demonstrate the vulnerability
- Don't publicly disclose the issue before we've had a reasonable window to fix it (typically 90 days, or sooner if there's active exploitation)
- Don't monetize the finding (no paid bug-bounty program — this is a public-interest project)

## Hall of fame

We keep a short list of researchers who've reported verified issues. Ask for credit in your report if you'd like to be listed.

---

*Last updated: 2026-04-22*
