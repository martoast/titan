<?php

namespace App\Support;

/**
 * PhenoAge — the mortality-validated blood biological-age clock (Levine/Liu 2018).
 *
 * Liu Z et al., "A new aging measure captures morbidity and mortality risk across diverse
 * subpopulations from NHANES IV", PLoS Med 2018;15(12):e1002718; popularised by Levine ME et al.,
 * "An epigenetic biomarker of aging…", Aging 2018;10(4):573. Trained against 10-year mortality in
 * NHANES III via a Gompertz model: nine routine CBC + CMP + CRP markers and chronological age →
 * "phenotypic age", the age whose population mortality risk matches yours. PhenoAge minus your real
 * age (the ACCELERATION) is the meaningful quantity — a risk score wearing an age label.
 *
 * UNITS ARE THE #1 IMPLEMENTATION BUG. The published coefficients are for SI units; our catalog
 * stores US units, so we convert: albumin g/dL→g/L (×10), creatinine mg/dL→µmol/L (×88.42), glucose
 * mg/dL→mmol/L (×0.0555), CRP mg/L→mg/dL (÷10) then ln. The rest (lymph %, MCV fL, RDW %, ALP U/L,
 * WBC 10³/µL) pass through. A golden-value unit test pins the whole chain.
 *
 * Honest scope: a wellness estimate from a single blood draw. CRP/glucose/WBC spike with acute
 * illness, recent meals (glucose must be FASTING) or hard exercise, so one draw can mislabel a
 * healthy person — we gate implausible values and frame the number as a trend, never a diagnosis.
 */
class PhenoAge
{
    private const INTERCEPT = -19.9067;
    private const C_AGE = 0.0804;
    private const GAMMA = 0.0076927;

    /**
     * Per-marker: [coefficient, catalog-unit → SI-unit factor, plausible [min,max] in CATALOG units].
     * CRP is special (log-transformed after conversion) and handled separately.
     */
    private const TERMS = [
        'albumin'              => [-0.0336, 10.0,    [2.0, 6.0]],     // g/dL → g/L
        'creatinine'           => [0.0095, 88.4017,  [0.3, 6.0]],     // mg/dL → µmol/L
        'fasting_glucose'      => [0.1953, 0.0555,   [50, 500]],      // mg/dL → mmol/L
        'lymphocyte_percent'   => [-0.0120, 1.0,     [1, 90]],        // %
        'mcv'                  => [0.0268, 1.0,      [60, 120]],      // fL
        'rdw'                  => [0.3306, 1.0,      [10, 30]],       // %
        'alkaline_phosphatase' => [0.00188, 1.0,     [10, 500]],      // U/L
        'wbc'                  => [0.0554, 1.0,      [1.0, 50.0]],    // 10³/µL
    ];
    private const CRP_COEF = 0.0954;          // on ln(CRP in mg/dL)
    private const CRP_PLAUSIBLE = [0.0, 200.0]; // mg/L

    /** The markers PhenoAge needs (catalog keys), for "what bloodwork to add" prompts. */
    public static function requiredMarkers(): array
    {
        return [...array_keys(self::TERMS), 'hs_crp'];
    }

    /**
     * @param  array<string,float|null>  $values  catalog-key → latest value (catalog units)
     * @return array{pheno_age:float,accel:float,chronological_age:float,n_markers:int}|null
     *         null when a required marker is missing or any value is implausible.
     */
    public static function compute(array $values, float $age): ?array
    {
        if ($age < 18 || $age > 100) {
            return null;
        }
        $xb = self::INTERCEPT + self::C_AGE * $age;

        foreach (self::TERMS as $marker => [$coef, $factor, [$lo, $hi]]) {
            $v = $values[$marker] ?? null;
            if (! is_numeric($v) || $v < $lo || $v > $hi) {
                return null;                                  // missing or implausible → no estimate
            }
            $xb += $coef * ((float) $v * $factor);
        }

        // CRP: mg/L → mg/dL (÷10), then natural log. Guard ln(0) with a tiny floor.
        $crp = $values['hs_crp'] ?? null;
        if (! is_numeric($crp) || $crp < self::CRP_PLAUSIBLE[0] || $crp > self::CRP_PLAUSIBLE[1]) {
            return null;
        }
        $xb += self::CRP_COEF * log(max((float) $crp / 10.0, 0.001));

        // Gompertz 10-year (120-month) mortality, then invert to phenotypic age.
        $g = self::GAMMA;
        $m = 1.0 - exp(-exp($xb) * (exp(120.0 * $g) - 1.0) / $g);
        $m = min(max($m, 1e-9), 1 - 1e-9);                    // numerical safety for the logs
        $phenoAge = 141.50225 + log(-0.00553 * log(1.0 - $m)) / 0.090165;

        $phenoAge = max(18.0, min(120.0, $phenoAge));
        return [
            'pheno_age' => round($phenoAge, 1),
            'accel' => round($phenoAge - $age, 1),
            'chronological_age' => $age,
            'n_markers' => 9,
        ];
    }
}
