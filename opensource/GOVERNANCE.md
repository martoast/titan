# Titan Governance

This document describes how decisions are made in the Titan project. We write it down
**early, on purpose** — the single clearest cautionary tale in open wearables is the
Pebble **Core-vs-Rebble** app-store conflict, where an undefined governance and
ownership boundary turned into community friction. A governance vacuum is a documented
stall mode. So here are the rules, before we need them.

---

## Current model: BDFL-lite / stewards

Titan currently operates under a lightweight **stewardship** model. The two founding
stewards (the brothers who started the project) hold final decision-making authority
across the `titan-wearable` org. "BDFL-lite" means:

- Stewards set technical direction, resolve ties, and have the final say on what ships.
- Stewards are explicitly **temporary** holders of that authority — the whole point of
  this document is the path *away* from sole stewardship (see below).
- Stewards commit to the **two rails** and the mission guardrails like everyone else:
  claim discipline (wellness only), stay on the information side, data ownership, no
  subscriptions, no overclaiming, no medical advice. **No steward may override the
  rails** — they are constitutional, not discretionary.

### Day-to-day: maintainers per repo
Each `titan-*` repo has one or more **maintainers** (commit + merge rights for that
repo), appointed by the stewards. Maintainers own review and merge decisions in their
repo. New maintainers are nominated based on a track record of quality contributions and
good-faith participation, and confirmed by the stewards.

### Roles summary
| Role | Rights | How you get it |
|---|---|---|
| Contributor | Open issues/PRs (DCO sign-off) | Just contribute |
| Maintainer | Review + merge in a repo | Sustained quality contributions; steward confirmation |
| Steward | Org-wide final say, appoint maintainers | Founding; transitions per the path below |

---

## How decisions get made

1. **Lazy consensus.** Most changes proceed by normal PR review. If no maintainer
   objects, it merges. Silence is assent.
2. **Discussion for bigger calls.** Architecture changes, new repos, license choices,
   governance changes, and anything touching the rails go to a public issue/discussion.
   We seek rough consensus.
3. **Escalation.** If maintainers can't reach consensus, a steward decides. Stewards
   document the reasoning publicly.
4. **The rails are not up for a vote.** Claim discipline and the information-side
   posture cannot be overridden by consensus, by a steward, or by a future council.
   Changing them would change what Titan *is* and its legal/regulatory posture.

All substantive governance, license, and direction decisions happen **in public**
(issues, discussions, or a `governance/` log) so the community can see how and why.

---

## The path to a maintainer council (Rebble lesson)

Sole stewardship does not scale and it is not the destination. As the project grows, we
transition to a **maintainer council**. Concretely:

- **Trigger.** When the project has a stable set of active maintainers across multiple
  repos (target: ~5+ active maintainers sustained over time) and reaches the
  sustainability phase, the stewards initiate the transition. This is planned for
  **Phase 3** of the roadmap.
- **Structure.** A **maintainer council** of active maintainers becomes the standing
  decision-making body for cross-repo and org-wide matters. The council elects/rotates
  seats among active maintainers. Stewards become ordinary council members (with, at
  most, a time-limited tie-break that sunsets).
- **App store & trademark — write it down now.** To pre-empt the Core-vs-Rebble split:
  the `titan-apps` catalog is community-governed under these same rules and **PR-to-
  publish** is the permanent submission model; the **Titan trademark** is held by the
  project's fiscal host / foundation entity (see below) for the benefit of the
  community, not by any individual or company. Any commercial use of the mark is by
  explicit trademark license, never silent capture.
- **Fiscal host / foundation.** Sustainability runs through **Open Collective** under
  the Open Source Collective (501(c)(6)) fiscal host — no separate legal entity required
  initially (the Nightscout Foundation precedent). The council directs spending
  transparently via the public ledger. A lightweight foundation may be formed later to
  hold the trademark and fund 1–2 maintainers.

---

## Changing this document

Changes to GOVERNANCE.md follow the bigger-call process: public proposal, discussion,
rough consensus, and — until the council exists — steward sign-off. Once the council
exists, governance changes are council decisions. The rails remain unamendable by
ordinary process.

---

*This is a community governance document, not legal advice. Entity, fiscal-host, and
trademark steps will be set up with appropriate counsel.*
