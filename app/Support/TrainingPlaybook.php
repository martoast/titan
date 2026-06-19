<?php

namespace App\Support;

/**
 * The Titan coaching playbook -- the deep, advanced bodybuilding/physique knowledge the coach
 * draws on when a user wants to PUSH (not just the average person). Distilled from the published
 * methods of the greats (Arnold, Mentzer, Yates, Cutler/Rambod, O'Hearn, Coleman) and the modern
 * hypertrophy evidence base, organized into topics the coach can pull on demand.
 *
 * HARD RAIL: this is NATURAL, evidence-based coaching. Pro physiques almost always involve anabolic
 * pharmacology -- Titan never prescribes, doses, or advises PEDs/SARMs/diuretics/insulin. Each topic
 * separates training/nutrition/recovery principles (which transfer to drug-free athletes) from the
 * pro-only pharmacology we do not touch.
 */
class TrainingPlaybook
{
    /**
     * topic key => [title, tags (match terms), body]
     *
     * @var array<string,array{title:string,tags:array<int,string>,body:string}>
     */
    private const TOPICS = [
        'philosophies' => [
            'title' => 'The schools of thought (Arnold, Mentzer, Yates, FST-7, O\'Hearn)',
            'tags' => ['philosophy', 'arnold', 'mentzer', 'yates', 'cutler', 'rambod', 'fst7', 'fst-7', 'ohearn', 'coleman', 'hit', 'heavy duty', 'volume', 'school'],
            'body' => <<<'TXT'
            Two great traditions -- both work; the art is matching the athlete:
            - HIGH-VOLUME / "feel" school -- Arnold, Jay Cutler, Ronnie Coleman. Lots of sets, mind-muscle
              connection, the pump, supersets, attacking a muscle from many angles ("shock principle" /
              variety), and relentless weak-point priority. Arnold trained huge volume 5-6x/week and drilled
              posing. Works when recovery (sleep/food) is high.
            - HIGH-INTENSITY / low-volume (HIT) school -- Mike Mentzer ("Heavy Duty"), Dorian Yates ("Blood and
              Guts"). After warm-ups, ONE all-out work set taken to true momentary failure (often past it with
              forced reps, slow negatives, rest-pause), then leave. Infrequent training, full recovery between.
              Yates won 6 Mr. Olympias on brief, brutal sessions. Great for advanced lifters who can truly push
              to failure and who recover slowly.
            - FST-7 (Hany Rambod, used by Jay Cutler & many pros): regular straight sets for a body part, then
              finish with 7 sets of ~8-12 reps, ~30-45s rest, chasing a deep pump to "stretch the fascia."
            - POWER-BODYBUILDING (Mike O'Hearn): build the physique on heavy compound strength (squat/bench/
              deadlift/press) with long-term progressive overload, then add bodybuilding work. Decades of
              consistency beat any single program.
            Coaching takeaway: pick ONE coherent system, run it hard for a block, judge by progress, then adjust.
            Genetics + drugs explain pro extremes; the METHODS still teach intensity, overload and structure.
            TXT,
        ],
        'hypertrophy' => [
            'title' => 'Hypertrophy fundamentals -- what actually grows muscle',
            'tags' => ['hypertrophy', 'muscle', 'grow', 'gains', 'tension', 'volume', 'mev', 'mrv', 'sets', 'frequency', 'rir', 'failure', 'progressive overload', 'reps', 'fundamentals'],
            'body' => <<<'TXT'
            The drivers, in order:
            1. MECHANICAL TENSION through a full range of motion is the primary stimulus. Train hard, with
               control, emphasising the stretched/lengthened position (deep, loaded stretch) -- that's where
               recent evidence shows the most growth per set.
            2. PROGRESSIVE OVERLOAD over time -- add reps, load, or quality sets week to week. No overload, no
               growth. Log it and beat the logbook.
            3. VOLUME drives growth in a dose-response up to a point. Landmarks per muscle/week (Israetel/RP):
               MV ~6-10 (maintain), MEV ~8-12 (minimum to grow), MAV ~12-20 (where MOST growth lives -- most
               advanced work belongs here), MRV ~20+ (the recoverable ceiling; living here chronically stalls).
               Start a block near MEV, add ~1-2 sets/week toward MRV, then deload. More is not better past recovery.
            4. PROXIMITY TO FAILURE: keep most working sets ~0-3 reps in reserve. Isolation/machine work can be
               pushed to failure safely; big compounds usually live at 1-3 RIR to manage fatigue and form.
            5. FREQUENCY: hitting each muscle ~2x/week tends to beat 1x at equal volume (better per-session
               quality), but 1x brutal sessions (Yates) also work -- total weekly hard sets matter most.
            6. REP RANGE: ~5-30 reps all build muscle if taken close to failure; ~6-12 is the efficient meat,
               with heavier work for tendons/strength and higher reps for joint-friendly volume.
            7. EXERCISE SELECTION: 1-2 big compounds for load + isolations to fully stimulate and bring up lagging
               heads. Rotate movements every block to refresh the stimulus, not randomly mid-block.
            TXT,
        ],
        'intensity_techniques' => [
            'title' => 'Intensity techniques -- squeezing more from each set',
            'tags' => ['intensity', 'technique', 'drop set', 'dropset', 'rest pause', 'rest-pause', 'myo', 'superset', 'giant set', 'partials', 'lengthened', 'forced reps', 'negatives', 'eccentric', 'plateau', 'fst7'],
            'body' => <<<'TXT'
            Tools to raise stimulus or density -- use sparingly, mostly on isolations and the LAST set, and more
            in a gaining phase than a deep cut (they add fatigue):
            - DROP SETS: hit failure, strip ~20-30%, go again (1-3 drops). Great pump/volume in little time.
            - REST-PAUSE / MYO-REPS: take a set near failure, rest 10-20s, squeeze out mini-clusters. Big
              effective-rep density (Yates and HIT-style).
            - LENGTHENED PARTIALS: extra reps in the stretched portion after full-ROM failure -- strong recent
              evidence for added growth (e.g., partial stretch reps on a fly or pulldown).
            - SUPERSETS / GIANT SETS: pair antagonists or same-muscle moves for density and pump (Arnold's
              chest/back supersets); great for time efficiency and metabolic stress.
            - FORCED REPS / NEGATIVES (eccentric overload): a spotter helps past failure or loads the lowering;
              potent but very fatiguing -- reserve for occasional overload, not every session.
            - FST-7: 7 finishing sets, ~30-45s rest, deep pump on a lagging muscle.
            Rule: intensity techniques are seasoning, not the meal. The base is hard straight sets + overload.
            Overuse digs into recovery and stalls progress.
            TXT,
        ],
        'programming' => [
            'title' => 'Programming & periodization for advanced lifters',
            'tags' => ['program', 'programming', 'periodization', 'split', 'mesocycle', 'deload', 'specialization', 'weak point', 'autoregulation', 'block', 'plan', 'routine', 'frequency'],
            'body' => <<<'TXT'
            Structure that keeps an advanced lifter progressing:
            - SPLIT to taste: full-body, upper/lower, push/pull/legs, or a body-part "bro" split all work if
              weekly volume + frequency land right. PPL x2 and upper/lower hit each muscle ~2x/week.
            - MESOCYCLE WAVING (RP-style): start a 4-6 week block near MEV (lower sets), add ~1-2 sets/muscle per
              week as you push toward MAV/MRV, then DELOAD (halve volume/intensity ~1 week) to dissipate fatigue
              and resensitise -- then start the next block slightly higher. Progress = load/reps up week to week.
            - PROGRESSION SCHEMES: double progression (add reps to the top of a range, then add load and reset),
              or autoregulate by RIR/RPE on the day. Always aim to beat last week somewhere.
            - SPECIALIZATION / WEAK-POINT BLOCKS: prioritise a lagging muscle for a block -- train it first, fresh,
              with extra volume/frequency, while putting strong parts on maintenance. Arnold and every pro built
              their look by attacking weak points, not just adding everywhere.
            - DELOAD TRIGGERS: strength sliding, sleep/appetite/mood down, joints achy, motivation gone, reps
              stalling at the same RIR -- that's MRV/recovery talking. Deload or you regress.
            - FATIGUE MANAGEMENT: rotate the most fatiguing movements; don't run max volume and max intensity
              techniques at the same time.
            TXT,
        ],
        'nutrition_muscle' => [
            'title' => 'Eating to build -- lean gaining done right',
            'tags' => ['bulk', 'bulking', 'gain', 'surplus', 'lean gain', 'protein', 'calories', 'mass', 'building', 'eat big', 'off-season', 'offseason'],
            'body' => <<<'TXT'
            Building muscle (natural-athlete reality):
            - SURPLUS, but LEAN: ~+5-15% over maintenance (roughly +200-400 kcal/day). Advanced/lean trainees gain
              muscle slowly -- a bigger surplus just adds fat you'll have to cut. Aim ~0.25-0.5% bodyweight gain per
              week; faster = more fat. "Eat big to get big" works for pros on drugs; naturals do better lean-gaining.
            - PROTEIN: ~1.6-2.2 g/kg bodyweight per day is the well-supported range; the upper end (and ~2.5-3.0 g/kg
              in a deep cut) protects muscle. Spread across ~3-5 feedings of ~0.3-0.4 g/kg (~30-50g) each.
            - CARBS fuel hard training and recovery -- keep them generous in a gaining phase, weighted around workouts.
            - FATS ~0.5-1.0 g/kg for hormones; fill remaining calories.
            - Be lean ENOUGH to grow: chronically high body fat blunts insulin sensitivity and partitioning. Many
              pros "stay lean to grow lean" -- gain in the ~10-15% (men) range, then push.
            - SUPPLEMENTS that actually earn their place: creatine monohydrate (3-5 g/day, every day) is the one
              near-universally evidence-backed strength/size aid; the rest is mostly noise next to food + training.
            - CONSISTENCY and progressive overload in the gym is what turns the surplus into muscle, not the surplus
              alone. Track weight weekly and adjust intake to hit the target rate.
            TXT,
        ],
        'nutrition_cutting' => [
            'title' => 'Getting shredded -- cutting & contest-lean nutrition',
            'tags' => ['cut', 'cutting', 'shred', 'lean', 'diet', 'deficit', 'fat loss', 'shredded', 'abs', 'contest', 'prep', 'refeed', 'diet break', 'photoshoot'],
            'body' => <<<'TXT'
            Stripping fat while holding muscle:
            - DEFICIT, moderate: ~ -20-25% (≈ -0.5-1.0% bodyweight/week). Leaner you get, slower you go to protect
              muscle. Crash diets shed muscle and crater performance.
            - PROTEIN HIGH: push to ~2.2-3.0 g/kg in a cut -- the single biggest muscle-protector alongside training.
            - KEEP TRAINING HEAVY: maintain intensity/load; you may pull back total volume as recovery drops. Heavy
              work signals the body to keep the muscle. Don't switch to "toning" high-rep fluff.
            - REFEEDS / DIET BREAKS: periodic higher-carb days or a 1-2 week maintenance break ease hormonal/metabolic
              and psychological strain on longer cuts. Carbs refill glycogen for fullness and gym output.
            - CARDIO as a tool, not a crutch: add it to widen the deficit when diet alone stalls; protect recovery.
            - CONTEST PREP is phased: off-season (build) → prep (12-24+ weeks of careful deficit to stage-lean) →
              final week. Getting truly stage-shredded (~4-6% men) is not "healthy" long-term -- it's a peak you hold
              briefly. Most people want "lean and athletic" (~10-12%), not stage condition.
            TXT,
        ],
        'recovery' => [
            'title' => 'Recovery -- where the growth actually happens',
            'tags' => ['recovery', 'rest', 'sleep', 'overtraining', 'fatigue', 'cns', 'deload', 'recover', 'mrv', 'sore'],
            'body' => <<<'TXT'
            You grow on rest, not in the gym -- the HIT schools were right that under-recovery stalls most people:
            - SLEEP is the #1 anabolic "supplement": ~7-9 h. Poor sleep tanks recovery, strength, hunger control and
              testosterone. Protect it like a training variable.
            - MANAGE SYSTEMIC FATIGUE: heavy compounds + intensity techniques + a big deficit all draw on the same
              recovery budget. When strength, sleep, mood, joints or motivation slide together, that's your MRV --
              deload (cut volume ~50% for a week) rather than push through.
            - MUSCLE-SPECIFIC vs SYSTEMIC: a muscle can be ready again in ~48h; the nervous system and connective
              tissue from very heavy/all-out work take longer -- why Yates/Mentzer trained infrequently.
            - STRESS, steps, and food quality all feed recovery. Chronic life stress is training stress.
            - The athletes who last decades (O'Hearn, Coleman early) auto-regulated and stayed consistent rather than
              grinding themselves into injury. Longevity in training is the real cheat code.
            TXT,
        ],
        'peak_week' => [
            'title' => 'Peaking & "looking your best on the day" (advanced, caution)',
            'tags' => ['peak', 'peak week', 'show', 'stage', 'water', 'carb load', 'sodium', 'photoshoot', 'look best', 'depletion'],
            'body' => <<<'TXT'
            For a stage show or shoot, advanced competitors manipulate glycogen and water to look full and dry --
            this is ADVANCED, individual, and easy to get wrong:
            - The honest version: get genuinely lean WEEKS out (the diet does 95% of it); peak week only fine-tunes.
            - Carb depletion then a carb LOAD can fill the muscle for fullness; water/sodium are often kept normal
              and steady -- aggressive water cutting and diuretics are dangerous and are NOT something Titan advises.
            - Manipulations only "work" on an already shredded physique; they can't reveal abs that diet didn't.
            - Train light/pump work, deload, sleep, stay calm.
            Titan's stance: I'll help you arrive lean and full through training and diet, and explain the concepts --
            but real peak-week water/electrolyte/diuretic protocols belong with an experienced in-person coach and
            (for water/drugs) medical oversight. I will not give a diuretic or dehydration protocol.
            TXT,
        ],
        'mindset' => [
            'title' => 'Mindset & discipline -- the Titan mentality',
            'tags' => ['mindset', 'discipline', 'motivation', 'consistency', 'mental', 'drive', 'push', 'hard', 'quote'],
            'body' => <<<'TXT'
            The greats agree on this more than on any program:
            - CONSISTENCY over years beats any perfect routine. O'Hearn's whole message: show up, do the basics,
              for decades. Coleman: "Everybody wanna be a bodybuilder, but don't nobody wanna lift no heavy weight."
            - INTENSITY of effort is the variable you control every set -- Mentzer/Yates built empires on it.
              Arnold: "The last three or four reps is what makes the muscle grow -- this area of pain divides a
              champion from someone who is not a champion." Mentzer: "more is not better; better is better."
            - TRAIN WITH INTENT: every set has a target (beat the logbook); the mind-muscle connection (Arnold) makes
              the working muscle do the work, not momentum.
            - EMBRACE THE HARD: the last 2-3 reps that hurt are where growth lives. Discomfort tolerance is trainable.
            - PATIENCE: natural muscle gain is measured in years, not weeks. Track, trust the process, adjust on data.
            - Visualise the physique you're building (Arnold's posing/visualisation) -- a clear target drives the daily
              discipline. Titan's future-self render is exactly this.
            TXT,
        ],
        'glutes_waist' => [
            'title' => 'Glute growth & waistline definition (the hourglass)',
            'tags' => ['glute', 'glutes', 'butt', 'booty', 'waist', 'waistline', 'hourglass', 'hip thrust', 'abduction', 'medius', 'women', 'woman', 'female', 'shelf', 'snatched', 'curves', 'hips'],
            'body' => <<<'TXT'
            The most-requested women's physique goal: round, full glutes + a defined waist. Both have a clear
            evidence base (Bret Contreras leads the glute research).

            GLUTES -- train BOTH heads:
            - Gluteus MAXIMUS = the mass/projection (hip EXTENSION): hip thrusts, hinges (RDL, 45° back extension,
              pull-through), squats, lunges. The hip thrust has the highest mean glute activation and is the lift to
              progressively OVERLOAD (chase load/reps, pause + squeeze at lockout, ribs down -- don't arch the back).
            - Gluteus MEDIUS/MINIMUS = the upper-outer "shelf"/3D roundness (hip ABDUCTION): machine/cable/banded
              abduction, frog pumps, single-leg work. Neglecting abduction is the #1 reason a glute is strong but
              flat on top.
            - The training SPECTRUM, every glute week: HEAVY activators (hip thrust ~4×6, deadlift), STRETCH/
              muscle-damage (Bulgarian split squat, deficit lunge, deep/sumo squat, RDL -- ~4×8 slow eccentric,
              load the lengthened position), and PUMP (abduction, kickback, banded thrust, frog pump ~3×15-30,
              short rest). Stretchers grow the most per rep but are the most fatiguing.
            - VOLUME/FREQUENCY: glutes recover fast and tolerate a lot -- ~12-20+ hard sets/week, trained 2-4×/week
              (a specialization block can add near-daily PUMP sessions, since pumpers recover in 1-2 days). Spread
              volume across days; run heavy/stretch days fresh (early week), pump days when fatigued.
            - Mistakes: quad-dominant squatting, partial ROM (skipping the stretch), only going heavy, skipping
              abduction, and the lower back/anterior tilt stealing the work from the glutes.

            WAISTLINE -- it's mostly fat, ratio and posture, not endless ab work:
            - Spot reduction is a MYTH. A defined waist comes from getting LEAN (diet/energy balance) -- ab work
              shapes, it doesn't strip waist fat.
            - For a tight, flat look, train CONTROL not size: anti-rotation (Pallof press), anti-extension (plank,
              dead bug), and stomach VACUUMS (transverse abdominis). Keep direct oblique work LIGHT/UNLOADED --
              heavy loaded side bends / heavy weighted twists can thicken the obliques and WIDEN the waist.
            - The biggest visual win is RATIO: building the GLUTES/hips (waist-to-hip toward ~0.7) and the
              SHOULDERS + lats/upper back makes the waist read smaller by contrast. Posture (ribs stacked over
              pelvis, tall) instantly tightens the look. So "define the waist" = get lean + grow glutes & frame.
            Coach this as a real program: a glute-focus mesocycle (heavy/stretch/pump across the week, abduction
            every session, hip thrust overloaded), upper-body work for the frame, waist-friendly core, and a lean
            nutrition phase. generate_mesocycle with focus ["glutes"] sets the training up automatically.
            TXT,
        ],
        'natural_vs_enhanced' => [
            'title' => 'Natural vs enhanced -- the honest line Titan holds',
            'tags' => ['natural', 'enhanced', 'steroids', 'ped', 'peds', 'sarms', 'drugs', 'gear', 'trt', 'pharma', 'enhanced', 'safe'],
            'body' => <<<'TXT'
            Be honest with advanced users so their expectations and methods are realistic:
            - Pro bodybuilders at the Olympia level almost universally use anabolic steroids, growth hormone, insulin
              and other pharmacology. Their EXTREME size, the "eat huge" surpluses, and some peak-week protocols are
              only viable (or only survivable) on drugs.
            - The TRAINING, nutrition structure, recovery and mindset principles still transfer to natural athletes --
              that's what Titan coaches. Naturals just respond best to lean gaining, high relative protein, hard
              progressive overload, ~2x frequency, real recovery, and patience.
            - Titan's rail: I coach NATURAL, evidence-based methods. I will not prescribe, dose, source or design
              PED / SARM / diuretic / insulin protocols -- that's a medical and legal matter, and the risks are real.
              If someone's already on a doctor-supervised protocol (e.g. TRT), I'll coach the training/nutrition
              around it and otherwise direct them to a qualified physician.
            TXT,
        ],
    ];

    /** A one-line index of the topics, for the coach to know what's available. */
    public static function index(): array
    {
        return array_map(fn ($t) => $t['title'], self::TOPICS);
    }

    /**
     * Find the most relevant topic(s) for a free-text query, returning their bodies.
     *
     * @return array{matched:array<int,array{topic:string,title:string,body:string}>,disclaimer:string}
     */
    public static function lookup(string $query, int $limit = 2): array
    {
        $q = strtolower(trim($query));
        $words = array_filter(preg_split('/[^a-z0-9]+/', $q) ?: []);

        $scored = [];
        foreach (self::TOPICS as $key => $t) {
            $score = 0;
            foreach ($t['tags'] as $tag) {
                if ($q !== '' && str_contains($q, $tag)) {
                    $score += 3;
                }
                foreach ($words as $w) {
                    if (strlen($w) >= 3 && str_contains($tag, $w)) {
                        $score++;
                    }
                }
            }
            // light boost if a query word appears in the title
            foreach ($words as $w) {
                if (strlen($w) >= 4 && str_contains(strtolower($t['title']), $w)) {
                    $score += 2;
                }
            }
            if ($score > 0) {
                $scored[$key] = $score;
            }
        }
        arsort($scored);

        $matched = [];
        foreach (array_slice(array_keys($scored), 0, max(1, $limit)) as $key) {
            $matched[] = ['topic' => $key, 'title' => self::TOPICS[$key]['title'], 'body' => self::trim(self::TOPICS[$key]['body'])];
        }
        // Nothing matched → hand back the fundamentals + philosophies as a sensible default.
        if ($matched === []) {
            foreach (['hypertrophy', 'programming'] as $key) {
                $matched[] = ['topic' => $key, 'title' => self::TOPICS[$key]['title'], 'body' => self::trim(self::TOPICS[$key]['body'])];
            }
        }

        return [
            'matched' => $matched,
            'disclaimer' => 'Natural, evidence-based coaching. Titan never prescribes or advises PEDs/SARMs/diuretics/insulin; pro-level pharmacology is a medical matter.',
        ];
    }

    private static function trim(string $body): string
    {
        // Heredoc lines are indented for code readability -- strip the leading indentation.
        return trim(implode("\n", array_map('ltrim', explode("\n", $body))));
    }
}
