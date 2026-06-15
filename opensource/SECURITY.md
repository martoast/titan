# Security Policy

Titan handles people's physiological data, runs firmware on a body-worn device, and
ships a self-hostable server. We take security seriously and we practice **coordinated
disclosure**. Thank you for helping keep builders safe.

---

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Report privately by either:

- **GitHub Private Vulnerability Reporting** — on the affected `titan-*` repo, go to the
  **Security** tab → **Report a vulnerability** (preferred), or
- **Email** **security@titan-wearable.org** *(placeholder — replace with the project's
  real security contact and PGP key before launch)*.

Please include:

- the affected repo and version/commit,
- a description of the issue and its impact,
- steps to reproduce or a proof of concept,
- any suggested remediation, and
- whether you'd like to be credited.

If you encrypt, use the project PGP key published in the repo (to be added at launch).

---

## What's in scope

- `titan-server` — auth, sync, data isolation between users, injection, SSRF, etc.
- `titan-firmware` / BLE protocol — pairing, auth, data exposure over the air.
- `titan-app` / `titan-sdk` — local data storage, key handling, the local/HTTP API.
- `titan-apps` catalog — supply-chain / malicious-app submission concerns.

Privacy-impacting issues are explicitly in scope — for example, anything that could put
**advertising or analytics on the health-data path**, leak health data off-device
without consent, or weaken the local-first / end-to-end-encryption guarantees.

### Out of scope
- The fact that Titan is a DIY wellness device and **not a medical device** (by design —
  see [`../DISCLAIMER.md`](../DISCLAIMER.md)).
- Issues requiring physical disassembly of *your own* device, or self-inflicted misuse.
- Findings in third-party dependencies already publicly disclosed upstream (report
  upstream; tell us so we can pin/patch).

---

## Our commitment (coordinated disclosure)

- **Acknowledge** your report within **3 business days**.
- Provide an initial **assessment within 10 business days**.
- Work with you on a fix and a **coordinated disclosure timeline** — our target is to
  remediate and publish within **90 days**, sooner for actively exploited issues.
- **Credit** you in the advisory and release notes unless you ask us not to.
- Publish a **security advisory** (GitHub Security Advisories) for confirmed issues so
  self-hosters and builders can update.

We ask, in return, that you give us reasonable time to fix the issue before public
disclosure, avoid privacy violations and data destruction, and only test against your
own builds / instances.

We do not currently run a paid bug-bounty program; this is a non-commercial,
donation-funded project. We deeply appreciate good-faith research and will credit it.

---

## For self-hosters

When a server advisory is published, update your `titan-server` instance promptly.
Security-relevant releases are tagged and announced on the project's release channel and
community chat.

---

*This policy describes process, not legal terms. Good-faith security research conducted
under this policy is welcome.*
