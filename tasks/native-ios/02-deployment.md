# 02 — Deployment & App Store approval

Full path from zero to App Store for a native iOS health app with a custom BLE wearable +
background Bluetooth. Synthesized from research B (Apple developer docs + review guidelines;
sources at bottom).

## Apple Developer Program
- **$99/yr**, same for Individual and Organization. Free Apple IDs cannot distribute.
- **Enroll as Individual** (recommendation): fast (1–3 days), needs only your Apple ID + 2FA +
  card. Your legal name becomes the seller name. Organization needs a **D-U-N-S number** + a
  live website on the org domain + legal-entity verification (~7–10 days, sometimes multi-week
  backlogs in 2026). Switch to Org later if Titan needs a company seller name.
- A **free app needs no Paid Apps agreement / banking / tax** setup. Simpler.
- **Action for Alex:** enroll as Individual now — it's the long pole; everything else can be
  built in parallel.

## Signing & capabilities (Xcode automatic signing)
Chain: Certificate → App ID/Bundle ID (e.g. `com.titan.app`) → Provisioning Profile. Turn on
"Automatically manage signing" and Xcode handles all of it, regenerating the profile when you
add a capability.

Capabilities to enable (Target → Signing & Capabilities → +):
- **Background Modes** → check **"Uses Bluetooth LE accessories"** (= `bluetooth-central`) and
  **"Remote notifications"** (so a push can wake the app).
- **Push Notifications** (APNs).
- **HealthKit** (if we write to Apple Health — recommended, see below). Adds the
  `com.apple.developer.healthkit` entitlement.
- **(Fast follow) Location** for region monitoring to recover the force-quit/reboot case.

Info.plist usage strings (missing any = crash/reject on iOS 13+):
- `NSBluetoothAlwaysUsageDescription`
- `NSHealthShareUsageDescription`, `NSHealthUpdateUsageDescription` (if HealthKit)
- `NSLocationWhenInUseUsageDescription` (+ Always, if region monitoring)

## HealthKit — recommended, with eyes open
Writing recovery/sleep/workouts to Apple Health is high value (Health is the ecosystem hub).
Cost: **longer review (~5–10 days)**, reviewers check usage strings + privacy policy + that we
don't sell health data, and a **privacy policy is mandatory**. Decision: **include HealthKit
write in v1** (read optional later); it materially increases the product's legitimacy and
"feels like Whoop" factor.

## Push notifications (APNs)
1. Create an **APNs Auth Key (.p8)** in the developer portal (Keys). One-time download — store
   it securely (Apple deletes its copy of the private key). Note the **Key ID** + **Team ID**.
2. Xcode: Push Notifications + Background Modes → Remote notifications. App registers and sends
   its device token to our backend.
3. Laravel: `laravel-notification-channels/apn` (token-based JWT over APNs HTTP/2), configured
   with Key ID + Team ID + bundle ID + .p8. Slots into the existing `queue`/`scheduler` path
   the coach already uses for nudges/briefings. (Replaces the current Web Push/VAPID, which
   can't reach native iOS.) See `03-architecture.md` §backend.

## TestFlight
- **Internal** (up to 100 team testers): builds appear in **minutes, no review** — this is how
  Alex tests on a real iPhone with the real band. Use this constantly.
- **External** (up to 10k via link): first build per version needs **Beta App Review (~24h)**;
  attach the demo video + notes (same as full review).
- Every build expires after **90 days**.

## App Store review — the risks that matter here (ranked) + mitigations
1. **Reviewer has no band → can't test the core flow (Guideline 2.1).** THE top risk for a
   custom-wearable app. Mitigate: in App Review Information attach a **demo video (real device +
   real band pairing and streaming)**, provide a **demo account**, and/or ship a **demo mode**
   (needs Apple's prior OK) that exercises full functionality with synthetic data. Spell it all
   out in the review notes. (Our `Send test window` synthetic path is a ready basis for demo
   mode.)
2. **Background `bluetooth-central` scrutiny (2.5.4).** Apps that declare the background mode but
   don't really use Core Bluetooth get rejected. We ship genuine CB scan/connect + state
   restoration, and justify background use as **continuous recovery/HRV/sleep monitoring** — a
   real, user-facing benefit. Low risk given we actually do it.
3. **Health accuracy / medical claims + privacy (1.4.1, 5.1.1, 5.1.3).** Don't claim
   sensor-only BP/SpO2/glucose. Disclose methodology behind any accuracy claims. Add
   "consult a doctor" disclaimers. Never use health data for ads/marketing/data-mining. Publish
   a **privacy policy URL** (in App Store Connect AND in-app). Fill the **App Privacy nutrition
   label** honestly (primary category: Health & Fitness; also device token Identifiers, Usage
   Data) — biosignals sent to our Laravel/MinIO backend ARE "collected" and must be disclosed;
   on-device-only data is not.
4. **"Just a web wrapper" (4.2).** Not a risk — native BLE + HealthKit + native SwiftUI clears
   this easily. Laravel as a pure JSON API is fine; just don't ship a WebView of the site.
5. **Free / open-source, no IAP.** Fully allowed. No issue.

## Step-by-step checklist
**A. Account (week 0, Alex):** Apple ID + 2FA → enroll Individual ($99).
**B. Xcode signing/capabilities:** Bundle ID + auto-signing → add Background Modes (BLE
accessories + Remote notifications), Push, HealthKit → add Info.plist usage strings.
**C. APNs server:** create .p8 → wire `laravel-notification-channels/apn` → `POST
/api/devices/push-token` to register tokens.
**D. TestFlight:** archive → upload → Internal test on Alex's iPhone + band (instant).
**E. App Store:** fill App Privacy label + privacy policy URL → App Review Information with demo
account + **demo video of the band pairing** + background-Bluetooth justification + health
disclaimers → submit. Budget **~5–10 days** for first review (HealthKit).

## Sources
- Enroll / memberships: https://developer.apple.com/programs/enroll/ ·
  https://developer.apple.com/help/account/membership/program-enrollment/
- Adding capabilities: https://developer.apple.com/documentation/xcode/adding-capabilities-to-your-app
- App Review Guidelines: https://developer.apple.com/app-store/review/guidelines/
- TestFlight: https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/
- HealthKit access: https://developer.apple.com/documentation/xcode/configuring-healthkit-access
- App privacy details: https://developer.apple.com/app-store/app-privacy-details/
- Laravel APNs channel: https://github.com/laravel-notification-channels/apn
- bluetooth-central background rejection example: https://forums.estimote.com/t/ios-app-store-rejection-on-using-bluetooth-central-in-the-uibackgroundmodes-key-in-info-plist/9624
