# Contributing to Titan

Thanks for helping build an honest, open, subscription-free wellness wearable. This
guide covers how we accept contributions across all `titan-*` repos.

First, please read:

- [`../DISCLAIMER.md`](../DISCLAIMER.md) — Titan is a DIY wellness project, **not a
  medical device**.
- [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) — be kind; harassment is not tolerated.
- [`GOVERNANCE.md`](GOVERNANCE.md) — who decides what.
- [`SAFETY.md`](SAFETY.md) — battery and skin-contact rules if you touch hardware.

---

## The two rails (every contribution must respect them)

1. **Claim discipline.** Wellness vocabulary only. We describe stress, recovery,
   readiness, sleep, activity. We **never** diagnose, monitor, treat, or screen for
   disease. We do **not** accept features for ECG/AFib, sleep-apnea detection, or blood
   pressure. Pull requests that add disease-detection language or features will be
   closed.
2. **Stay on the information side.** We publish designs and code. We don't turn Titan
   into a sold finished product, and we don't put paywalls or trackers on the
   health-data path.

Also non-negotiable: **no advertising or analytics SDKs on the health-data path**, and
cloud sync stays **off by default** behind an explicit opt-in.

---

## Developer Certificate of Origin (DCO sign-off) — we do NOT use a CLA

Titan uses the **Developer Certificate of Origin (DCO)**, not a Contributor License
Agreement. You keep the copyright to your work. You just certify, with each commit, that
you have the right to submit it under the repo's license.

**How:** add a `Signed-off-by` line to every commit. Git does this for you:

```bash
git commit -s -m "firmware: add WebSerial flashing fallback"
```

That appends:

```
Signed-off-by: Your Name <your.email@example.com>
```

By signing off you certify the [DCO v1.1](https://developercertificate.org/):

> By making a contribution to this project, I certify that:
>
> (a) The contribution was created in whole or in part by me and I have the right to
> submit it under the open source license indicated in the file; or
> (b) The contribution is based upon previous work that, to the best of my knowledge, is
> covered under an appropriate open source license and I have the right under that
> license to submit that work with modifications; or
> (c) The contribution was provided directly to me by some other person who certified
> (a), (b) or (c) and I have not modified it.
> (d) I understand and agree that this project and the contribution are public and that a
> record of the contribution (including all personal information I submit with it) is
> maintained indefinitely.

Use your real name (no anonymous or pseudonymous sign-offs) and a working email. Each
repo runs a DCO bot that blocks un-signed PRs. Forgot to sign off? Run:

```bash
git rebase --signoff HEAD~<n>   # then force-push your branch
```

By contributing to a given repo, your contribution is licensed under **that repo's
license** (see [`README.md`](README.md) for the license-by-role map).

---

## Contribution flow

1. **Find or open an issue.** Look for [`good first issue`](#good-first-issues) labels.
   For anything non-trivial, comment on (or open) an issue first so we agree on the
   approach before you write code.
2. **Fork** the relevant `titan-*` repo and create a topic branch.
3. **Build and test locally.** Each repo's README has setup instructions. Hardware-free
   contributors can use the **simulator** (no device required).
4. **Commit with `-s`** (DCO sign-off, see above). Keep commits focused; write clear
   messages.
5. **Open a PR.** Fill in the template. Link the issue. Describe what you changed and how
   you tested it. For hardware/firmware changes, note any safety implications.
6. **Review.** A steward or maintainer reviews. Address feedback. Once approved and CI
   (including the DCO check) is green, we merge.

For documentation (`titan-docs`, CC-BY-4.0), the same flow applies — docs are the
product, so doc PRs are first-class.

---

## Good first issues

We tag approachable, well-scoped work with **`good first issue`** and slightly larger
onboarding work with **`help wanted`**. Good starting points:

- Improving build-guide docs, photos, or translations.
- Adding a watch app to the `titan-apps` catalog.
- Triage: reproducing reported issues, improving error messages.
- Writing or extending validation notebooks in `titan-algorithms`.

---

## Tutorials: add a new device or a new app (the Gadgetbridge model)

We deliberately lower the contributor floor. Two flagship tutorials live in
`titan-docs`:

### "Add a new device"
Walks you through bringing a new sensor board or wearable variant into Titan: declaring
the hardware in `titan-hardware`, documenting the BLE characteristics in the
`titan-firmware` protocol spec, and wiring it into the SDK so the app and server can
talk to it. You can develop most of this against the simulator and the documented BLE
protocol without owning every board.

### "Add a new app"
Walks you through building a watch app or companion app and submitting it to the
`titan-apps` static-site catalog via a simple **PR-to-publish** flow (the Bangle.js App
Loader model): build against the simulator, test with browser Web-BT, open a PR, and on
merge your app appears in the catalog. No server ops, no gatekeeping beyond review.

---

*Questions? Open a discussion or join the community chat (Discord/Matrix). Welcome aboard.*
