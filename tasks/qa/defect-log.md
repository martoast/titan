# Titan QA — Defect Log

Source of truth for defects found by the continuous QA loop. Canonical feature
inventory + test suites live in `feature-spec.csv`.

## DEF-001 — Imperial height loses sub-cm precision on onboarding  ✅ FIXED

| Field | Value |
|---|---|
| Feature ID | F-ONB-01 (Onboarding wizard) |
| Severity | Medium (data integrity) |
| Status | Fixed & verified |
| Found | 2026-06-21 |

**Reproduction steps**
1. Register a new (not-onboarded) user.
2. POST `/onboarding` with `units=imperial`, `height=71` (inches).
3. Inspect the persisted `profiles.height_cm`.

**Expected result:** `71 in × 2.54 = 180.34` → stored as `180.3` cm.
**Actual result:** `180.0` cm (the `.3` was lost).

**Root cause**
`OnboardingController` correctly computes `round($height * 2.54, 1) = 180.3`, but the
`profiles.height_cm` column was `unsignedSmallInteger` (integer), so the value was
truncated to `180` on save. `weight_kg` was already `decimal(6,2)` and unaffected.
The covering test `OnboardingTest::test_imperial_units_convert_to_metric` asserts
`assertEqualsWithDelta(180.3, height_cm, 0.2)`, which `180.0` fails (off by 0.3).

**Fix (smallest safe change)**
- Migration `2026_06_15_000000_create_profiles_table.php`: `height_cm` →
  `decimal('height_cm', 5, 1)`.
- `App\Models\Profile`: added cast `'height_cm' => 'decimal:1'`.
- Running dev DB synced non-destructively:
  `ALTER TABLE profiles MODIFY height_cm DECIMAL(5,1) NULL`.

No code performs integer-only arithmetic on `height_cm` (the `SealActivityJob` docblock
already types it `float`), so widening to decimal is safe.

**Verification**
- `OnboardingTest` → 8 passed.
- Full PHP suite → 320 passed, 0 failed.
- No regressions.
