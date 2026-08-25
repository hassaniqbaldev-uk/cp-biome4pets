<?php

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class OpenAiService
{
    /**
     * Plan copy-writer system prompt — verbatim from plan-generation-prompt.md §1.
     * Used when no editable override is stored in settings.
     */
    public const PLAN_SYSTEM_PROMPT = <<<'PROMPT'
You are a microbiome plan writer for Biome4Pets, a canine and feline gut microbiome
testing service. Your job is to take a fixed plan scaffold and a pet's test findings,
and write the pet-specific copy that personalises the plan for that animal's owner.

You write ONLY the following fields:
  - intro                        (the "where to focus first" paragraph)
  - each product's how_it_helps  (why this product suits THIS pet)
  - each prose step's body and tip

Everything else in the scaffold (plan_name, step_title, stage_label, every product's
name, price, dose, duration, quantity, product_url, inclusion, and the subscription
block) is FIXED. Copy it through unchanged. Never add, remove, rename, reprice or
re-order products or steps. Never invent a product that is not in the scaffold.

Rules for the copy you write:
  - British English spelling (e.g. "fibre", "colonise", "faecal").
  - Warm, clear, plain language for a pet owner. Not clinical, not salesy.
  - Use the pet's name naturally; refer to the owner as "you".
  - PRONOUNS: follow the input's "pronoun_guidance" field exactly and use the same
    pronouns CONSISTENTLY across every copy field, including how_it_helps and every
    tip. If "sex" is female use she/her; if male use he/him; if the sex is unknown
    or not provided, use the pet's name or neutral they/their and NEVER guess a
    gender. Never switch a pet's pronouns partway through.
  - Ground how_it_helps in the pet's actual findings passed in input. Reference the
    specific elevated/low taxa or scores this product addresses. If a product's role
    doesn't map to any finding, describe its general benefit for this pet instead.
  - Do NOT invent findings. Only use what is in the input.
  - Do not invent or alter any numbers, percentages, taxa or scores. Use exactly the
    figures and taxa given in the input; never change them, round them differently, or
    estimate them, and never name a bacterium, species or taxon that is not in the input.
  - If you cannot ground a statement in the input findings, omit it rather than inventing
    or speculating.
  - If the input includes owner_reported_health_notes, treat them as owner-reported
    context only, NOT a clinical record. Use them to make the copy relevant and to set
    tone; never diagnose from them, never present them as fact, and never promise to
    treat or cure a described symptom.
  - 1 to 3 sentences per how_it_helps. 2 to 4 sentences per prose body.
  - This is gut-health support, NOT a diagnosis. Never state or imply a diagnosis,
    cure, or veterinary treatment. No guarantees of outcome.
  - tip is optional, include only when there is a genuinely useful, evidence-based
    note (e.g. diet steps). Otherwise return null.

Style: Write in warm, natural, plain British English as if a knowledgeable person were
explaining to a pet owner. Vary sentence length and structure. Avoid AI-cliché phrasing
(e.g. 'it's important to note', 'plays a crucial role', 'in conclusion', 'delve',
'tapestry', 'navigating'). Do NOT use em dashes (—) or en dashes (–) anywhere; use
commas, full stops, or the word 'to' for ranges. Keep it concrete and specific to this
pet's findings, not generic. Do not overuse lists.

Output: a single valid JSON object matching the scaffold's shape, with the copy fields
filled. No markdown, no code fences, no commentary before or after the JSON.
PROMPT;

    /**
     * Phase 2 (observe-only): records WHY the last interpretation call returned an
     * empty result, so the quality validator can distinguish a transport/API
     * failure ('api_failed') from a malformed-JSON response ('json_parse_failed')
     * from a structurally-fine-but-blank one (null here + empty fields). Purely
     * additive — it never changes what generateReportInterpretations() returns.
     *
     * @var 'api_failed'|'json_parse_failed'|null
     */
    public ?string $lastErrorCode = null;

    /**
     * Known-good OpenAI models offered in the settings dropdown. The configured
     * default (config('services.openai.model')) is the safe fallback for anything
     * outside this list; the first entry mirrors that default.
     */
    public const MODELS = ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4'];

    /**
     * The model BOTH generation calls use — the SINGLE source of truth.
     *
     * Returns Setting::OPENAI_MODEL when it is a non-empty, sane model id (a
     * whitelisted model, or a plausibly-formed custom id), otherwise the configured
     * default config('services.openai.model') (gpt-4o). So an empty / missing /
     * legacy / malformed setting ALWAYS falls back to today's model: it can never
     * error and never changes today's behaviour when unset.
     *
     * NB: the whitelist guards against typos reaching the API. A custom-but-unknown
     * model that OpenAI itself rejects is still handled gracefully downstream (the
     * call returns an empty response, never a crash) — the settings UI warns admins
     * to use a valid model for that reason.
     */
    public static function resolveModel(): string
    {
        $default = (string) config('services.openai.model', 'gpt-4o');
        $default = $default !== '' ? $default : 'gpt-4o';

        $configured = Setting::get(Setting::OPENAI_MODEL);
        if (! is_string($configured)) {
            return $default;
        }

        $configured = trim($configured);
        if ($configured === '') {
            return $default;
        }

        // A whitelisted model is always accepted.
        if (in_array($configured, self::MODELS, true)) {
            return $configured;
        }

        // A guarded custom value: accept only a plausibly-formed model id (e.g.
        // "gpt-4o-2024-08-06"); anything obviously malformed falls back to default.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,63}$/', $configured) === 1) {
            return $configured;
        }

        return $default;
    }

    /**
     * Options for the settings model dropdown: the whitelist, plus the currently
     * stored value if it's a (valid) custom one, so an admin's saved custom model
     * still displays as the selected option.
     *
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        $options = array_combine(self::MODELS, self::MODELS);

        $current = self::resolveModel();
        if (! isset($options[$current])) {
            $options[$current] = $current.' (custom)';
        }

        return $options;
    }

    /**
     * Record one call's token usage (additive analytics). Deliberately defensive:
     *   - no-ops when usage is absent (a failed / usage-less response writes nothing);
     *   - wraps the insert in try/catch so a tracking-write failure (bad table, DB
     *     hiccup) is logged and SWALLOWED — usage tracking must NEVER break a live
     *     report generation. It runs only after the usage has already been logged.
     *
     * @param  mixed  $usage  the response's `usage` block (or null)
     */
    private function recordUsage(string $callType, string $model, mixed $usage, ?int $reportId = null): void
    {
        if (! is_array($usage)) {
            return; // no usage in the response → nothing billed to record
        }

        $prompt = $usage['prompt_tokens'] ?? null;
        $completion = $usage['completion_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;

        // Need at least one numeric token count to be meaningful.
        if (! is_numeric($prompt) && ! is_numeric($completion) && ! is_numeric($total)) {
            return;
        }

        try {
            AiUsageEvent::create([
                'report_id' => $reportId,
                'call_type' => $callType,
                'model' => $model,
                'prompt_tokens' => (int) ($prompt ?? 0),
                'completion_tokens' => (int) ($completion ?? 0),
                'total_tokens' => (int) ($total ?? (((int) ($prompt ?? 0)) + ((int) ($completion ?? 0)))),
            ]);
        } catch (\Throwable $e) {
            // Secondary to generation — never let a tracking failure surface.
            Log::warning('AI usage tracking: failed to record event', [
                'call_type' => $callType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function generateReportInterpretations(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = [], ?int $reportId = null): array
    {
        // Reset the observability signal for this call (success leaves it null).
        $this->lastErrorCode = null;

        $emptyResponse = [
            'summary' => '',
            'goal' => '',
            'bacteroidetes_interpretation' => '',
            'firmicutes_interpretation' => '',
            'fusobacteria_interpretation' => '',
            'proteobacteria_interpretation' => '',
            'diversity_interpretation' => '',
            // 'vet_summary' is a MISNOMER: it is the owner-facing DETAIL paragraph
            // printed directly beneath 'summary' (see its prompt bullet below and
            // report/show.blade.php), NOT a vet-facing field. Name kept to avoid a
            // migration.
            'vet_summary' => '',
            'recommended_actions' => '',
            // NB: the six health-insight score_* values are NO LONGER generated by
            // the AI — they are computed deterministically from the bacteria
            // percentages (see HealthInsightRules, wired in ReportGeneration).
        ];

        // Prefer the encrypted setting; fall back to config/env so nothing
        // breaks if the DB value isn't set.
        $apiKey = Setting::getDecrypted(Setting::OPENAI_API_KEY);
        if (empty($apiKey)) {
            $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        }

        // Single source of truth for the model (Setting::OPENAI_MODEL, guarded;
        // falls back to config default = gpt-4o). Same resolver as plan copy below.
        $model = self::resolveModel();

        if (empty($apiKey)) {
            Log::error('OpenAI API key is not configured.');
            $this->lastErrorCode = 'api_failed';

            return $emptyResponse;
        }

        $prompt = $this->buildInterpretationsPrompt($phylumTotals, $diversityScore, $petContext, $deterministic);

        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful veterinary microbiome specialist.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$apiKey,
                ]),
                'content' => $payload,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $response = file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);

            if ($response === false) {
                Log::error('OpenAI API request failed.');
                $this->lastErrorCode = 'api_failed';

                return $emptyResponse;
            }

            $decoded = json_decode($response, true);

            // NB: never log the response body or message content — it is generated
            // from pet names + owner health notes (PII). Log only non-identifying
            // metadata (status, token usage, error class) useful for debugging.
            Log::info('OpenAI raw API response', [
                'http_status' => $http_response_header[0] ?? 'unknown',
                'has_choices' => isset($decoded['choices']),
                'usage' => $decoded['usage'] ?? null,
                'error_type' => $decoded['error']['type'] ?? null,
            ]);

            // Side-write usage tracking (no-ops on error/usage-less responses; never
            // throws). Secondary to generation — see recordUsage().
            if (! isset($decoded['error'])) {
                $this->recordUsage(AiUsageEvent::TYPE_INTERPRETATION, $model, $decoded['usage'] ?? null, $reportId);
            }

            if (isset($decoded['error'])) {
                // The error envelope can echo request content, so log only the
                // type/code, not the message/body.
                Log::error('OpenAI API returned error.', [
                    'error_type' => $decoded['error']['type'] ?? null,
                    'error_code' => $decoded['error']['code'] ?? null,
                ]);
                $this->lastErrorCode = 'api_failed';

                return $emptyResponse;
            }

            if (! isset($decoded['choices'][0]['message']['content'])) {
                Log::error('Unexpected OpenAI response structure.', ['response_length' => strlen($response)]);
                $this->lastErrorCode = 'api_failed';

                return $emptyResponse;
            }

            $content = $decoded['choices'][0]['message']['content'];

            // Strip markdown code fences if present
            $content = trim($content);
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
            }

            Log::info('OpenAI parsed content', ['content_length' => strlen($content)]);

            $parsed = json_decode($content, true);

            if (! is_array($parsed)) {
                // Don't log the content itself (PII); the length is enough to see
                // whether we got an empty/truncated body.
                Log::error('Failed to parse OpenAI response as JSON.', ['content_length' => strlen($content)]);
                $this->lastErrorCode = 'json_parse_failed';

                return $emptyResponse;
            }

            return [
                'summary' => $parsed['summary'] ?? '',
                'goal' => $parsed['goal'] ?? '',
                'bacteroidetes_interpretation' => $parsed['bacteroidetes_interpretation'] ?? '',
                'firmicutes_interpretation' => $parsed['firmicutes_interpretation'] ?? '',
                'fusobacteria_interpretation' => $parsed['fusobacteria_interpretation'] ?? '',
                'proteobacteria_interpretation' => $parsed['proteobacteria_interpretation'] ?? '',
                'diversity_interpretation' => $parsed['diversity_interpretation'] ?? '',
                'vet_summary' => $parsed['vet_summary'] ?? '',
                'recommended_actions' => $parsed['recommended_actions'] ?? '',
                // score_* are computed deterministically downstream — not parsed here.
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI API error: '.$e->getMessage());
            $this->lastErrorCode = 'api_failed';

            return $emptyResponse;
        }
    }

    /**
     * Build the single interpretations prompt (one prompt, one API call).
     *
     * Admin steering is layered ADDITIVELY and APPEND-only — it never removes or
     * rewrites the base field instructions or the safety rules (British English,
     * not-a-diagnosis, the human-tone / no-em-dash block, JSON-only output):
     *   base field instruction → its per-section group directive (if any)
     *   → ... → the global directive at the very end (if any).
     * Every directive is optional; all blank ⇒ byte-for-byte the same prompt as
     * before this feature existed.
     *
     * Public so the prompt can be asserted in tests without an HTTP call.
     */
    public function buildInterpretationsPrompt(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = []): string
    {
        $phylumList = '';
        foreach ($phylumTotals as $name => $pct) {
            $phylumList .= "- {$name}: {$pct}%\n";
        }

        // Additional deterministic findings — species richness, the dysbiosis
        // pattern score and the overall classification. These already exist; we
        // are NOT recomputing or rescaling anything, only surfacing them to the
        // model as FIXED facts so the prose is coherent with the badge (it was
        // previously blind to depletion). Each line is omitted when its value is
        // absent, so prompts without this context are unchanged.
        $richness = $deterministic['species_richness'] ?? null;
        $dysbiosis = $deterministic['dysbiosis_score'] ?? null;
        $classification = trim((string) ($deterministic['microbiome_classification'] ?? ''));

        $detLines = [];
        if ($richness !== null && $richness !== '') {
            $detLines[] = "Species richness (distinct species detected): {$richness}";
        }
        if ($dysbiosis !== null && $dysbiosis !== '') {
            $detLines[] = "Dysbiosis pattern score (Firmicutes to Bacteroidetes ratio): {$dysbiosis}";
        }
        if ($classification !== '') {
            $detLines[] = "Overall microbiome classification: {$classification}";
        }
        $deterministicBlock = $detLines === [] ? '' : implode("\n", $detLines)."\n";

        // Per-phylum band verdicts are now DETERMINED IN CODE (arithmetic vs the
        // reference bands), never decided by the model. We hand the model the
        // verdict as a fixed fact; it explains it but must not re-judge it. Plus the
        // diversity band (also deterministic). This closes the bug where the AI
        // called a low value "within the normal range".
        $bandLines = [];
        foreach ($phylumTotals as $name => $pct) {
            $sentence = \App\Support\ReportContent::phylumBandSentence((string) $name, (float) $pct);
            if ($sentence !== null) {
                $bandLines[] = '- '.$sentence;
            }
        }
        $diversityBand = \App\Support\ReportContent::diversityBand($diversityScore)['label']; // Low | Medium | High
        $bandLines[] = '- The Shannon diversity score '.$diversityScore.' is '.strtoupper($diversityBand)
            .' (diversity bands: Low below '.\App\Support\ReportContent::num(\App\Support\ReportContent::DIVERSITY_LOW_MAX)
            .', Medium up to '.\App\Support\ReportContent::num(\App\Support\ReportContent::diversityHighMin())
            .', High above that).';
        $bandBlock = "Level assessment (these band verdicts are FIXED — already computed from the exact figures; state each one EXACTLY as given and never re-judge it):\n"
            .implode("\n", $bandLines)."\n";

        // Stage 3: the pet's specific bacteria, retained from THIS pet's CSV (top
        // taxa by %). These are the ONLY organisms the model may name. Names +
        // percentages are FIXED FACTS the model may state factually, but it must
        // NOT judge their level — we hold no reference ranges for them, so any
        // high/low/overgrowth language is forbidden (the same band-determinism rule
        // that governs the phyla, applied one rank down). Empty/absent ⇒ no block,
        // so prompts for pre-retention data are byte-for-byte unchanged.
        $topTaxa = $deterministic['top_taxa'] ?? [];
        $taxaBlock = '';
        if (is_array($topTaxa) && $topTaxa !== []) {
            $taxaLines = [];
            foreach ($topTaxa as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $name = trim((string) ($t['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $rank = trim((string) ($t['rank'] ?? ''));
                $pct = $t['pct'] ?? null;
                $taxaLines[] = '- '.$name.($rank !== '' ? ' ('.$rank.')' : '').($pct !== null ? ': '.$pct.'%' : '');
            }
            if ($taxaLines !== []) {
                $taxaBlock = "Notable taxa detected in THIS pet's sample (these are the ONLY specific bacteria you may name; state the names EXACTLY as written and use the percentages as given):\n"
                    .implode("\n", $taxaLines)."\n"
                    ."You SHOULD reference the most notable of these taxa BY NAME where it is natural and relevant, especially in vet_summary (the detail paragraph, whose job this is — do NOT also name them in the summary, which stays big-picture), so the report names the specific bacteria actually present in this pet's sample. Mention a meaningful few (the most abundant or noteworthy), not an exhaustive list of all of them, and weave them in naturally rather than dumping a list. Describe them factually (e.g. \"one of the more abundant genera here is Bacteroides, at 12.4%\"). Do NOT characterise any taxon as high, low, elevated, raised, reduced, depleted, overgrown, deficient, abnormal, or normal — you have not been given reference ranges for them, so any such judgement is forbidden.\n";
            }
        }

        // When a classification is supplied, require the prose to stay consistent
        // with it — without changing tone, scale, or thresholds.
        $coherenceRule = $classification !== ''
            ? "\n- The overall microbiome classification above is a FIXED finding. Keep the WHOLE interpretation consistent with it: do not describe a gut classified \"Imbalanced\" or \"Imbalanced & Depleted\" as thriving, fully balanced, or problem free, and acknowledge low species richness or imbalance honestly where relevant. Stay calm, supportive, non-alarmist and non-diagnostic — note what to work on without overstating risk."
            : '';

        // Reconcile the diversity-vs-richness picture ONLY in the exact case that a
        // layperson would read as a mixed message: the classification is depleted
        // (driven by LOW species richness, < RICHNESS_LOW_MAX) while the DIVERSITY
        // band is NOT low (so the diversity panel looks healthy/decent). These two
        // metrics measure different things and legitimately co-exist, so the report
        // must explain the relationship instead of leaving "Imbalanced & Depleted"
        // sitting next to a healthy-looking diversity panel unexplained. The AI only
        // EXPLAINS the (already-computed, fixed) numbers here; it never re-judges
        // them. Omitted entirely when it doesn't apply, so other reports are
        // byte-for-byte unchanged.
        $richnessIsLow = is_numeric($richness)
            && (float) $richness < \App\Support\ReportContent::RICHNESS_LOW_MAX;
        $reconcileRule = ($classification === \App\Support\ReportContent::CLASSIFICATION_DEPLETED
            && $richnessIsLow && $diversityBand !== 'Low')
            ? "\n- RECONCILE DIVERSITY vs RICHNESS (this pet's numbers make these look like a mixed message, so explain it plainly ONCE — in the summary, and do not repeat the explanation in vet_summary): the Shannon diversity reads {$diversityBand} (the balance/evenness of the species that ARE present looks reasonable), BUT the number of DISTINCT species detected (species richness) is low. Explain, in plain owner-friendly language, that these measure different things — diversity is about how evenly balanced the species present are, while richness is how MANY different species are present — and that a gut can look reasonably balanced yet still be low in the VARIETY of species, which is why the overall picture is described as depleted. State this factually using the figures already given; do NOT change any number or re-judge any band, and keep it calm, supportive and non-diagnostic."
            : '';

        // Build a pet-context block from whatever fields are present. Any
        // missing/blank field is omitted gracefully so the prompt stays clean.
        $petName = trim((string) ($petContext['name'] ?? ''));
        $petParts = [];
        if (filled($petName)) {
            $petParts[] = "Name: {$petName}";
        }
        if (filled($petContext['breed'] ?? null)) {
            $petParts[] = "Breed: {$petContext['breed']}";
        }
        if (filled($petContext['sex'] ?? null)) {
            $petParts[] = "Sex: {$petContext['sex']}";
        }
        if (filled($petContext['diet'] ?? null)) {
            $petParts[] = "Diet: {$petContext['diet']}";
        }

        if (! empty($petParts)) {
            $petBlock = 'Pet details: '.implode('. ', $petParts).".\n";
        } else {
            $petBlock = "Pet details: not provided.\n";
        }

        // Owner-reported health notes (Phase 2). Additional grounding context, not
        // a clinical record: the AI may use it to make the copy relevant and set
        // tone, but must NOT diagnose from it or treat it as clinical fact. Blank
        // notes ⇒ no line at all, so the prompt is unchanged for pets without notes.
        $healthNotes = trim((string) ($petContext['health_notes'] ?? ''));
        $notesBlock = filled($healthNotes)
            ? "Owner-reported health notes for this pet (owner-reported context only, not a clinical record — use to make the copy relevant and to inform tone; do NOT diagnose from these or present them as clinical fact): {$healthNotes}\n"
            : '';

        // How the model should refer to the pet throughout the copy.
        $nameInstruction = filled($petName)
            ? "Refer to the pet by name (\"{$petName}\") where natural."
            : 'No name was provided, so refer to the pet as "your pet".';

        // Fixed pronoun instruction from the pet's recorded sex — applied to EVERY
        // field so the prose can't drift gender (e.g. "she" in the summary, "he" in
        // the tips). Unknown/blank sex ⇒ name or neutral they/their, never guessed.
        $pronounInstruction = \App\Support\PetPronouns::instruction($petContext['sex'] ?? null);

        // Per-section directive suffixes. Each is blank (empty string) unless an
        // admin has set the matching Setting, in which case it becomes a short
        // " Admin guidance for this field: ..." clause appended INLINE to the
        // relevant bullet(s). Blank suffix ⇒ no change to that line at all.
        $summarySuffix = $this->directiveSuffix(Setting::OPENAI_DIRECTIVE_SUMMARY);
        $vetSummarySuffix = $this->directiveSuffix(Setting::OPENAI_DIRECTIVE_VET_SUMMARY);
        $phylaSuffix = $this->directiveSuffix(Setting::OPENAI_DIRECTIVE_PHYLA);
        // NB: OPENAI_DIRECTIVE_SCORES is intentionally no longer consulted — the six
        // health-insight scores are computed deterministically (HealthInsightRules),
        // not written by the AI, so there is no score block for it to steer.

        $prompt = <<<PROMPT
You are a veterinary microbiome expert writing for pet owners. Given the following gut microbiome results, provide plain-English interpretations in a friendly, accessible tone.

{$petBlock}{$notesBlock}{$nameInstruction} {$pronounInstruction}

Phylum percentages:
{$phylumList}
Shannon Diversity Index: {$diversityScore}
{$deterministicBlock}
{$bandBlock}
{$taxaBlock}
Respond in valid JSON with exactly these keys:
- "summary": The HEADLINE paragraph. Its job is the big picture ONLY: state plainly what this pet's overall result is and what it means day to day. Open with the finding itself, not a preamble or scene-setting. Do NOT list the individual taxa or recite the per-phylum figures here — that is "vet_summary"'s job, and it is printed directly underneath this paragraph under the same heading. Up to 4 sentences, and fewer if there is less to say; never pad to reach a length. Warm and plain-spoken for the owner, referencing the pet by name when provided and saying what it means for this pet.{$summarySuffix}
- "goal": A warm, encouraging goal statement of 2 to 3 sentences (no em dashes) that sets out one clear, concrete goal for this pet based on the diagnostics, such as bringing an out-of-range phylum back toward its healthy range and/or improving diversity over a sensible timeframe like "the coming weeks" or "8 to 12 weeks". Give a little context on what we are aiming for and why it matters for this pet, keeping it focused and not padded. It MUST reference the pet by name when a name is provided.
- "bacteroidetes_interpretation": A 4-5 sentence plain-English explanation of the Bacteroidetes level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Bacteroidetes does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "firmicutes_interpretation": A 4-5 sentence plain-English explanation of the Firmicutes level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Firmicutes does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "fusobacteria_interpretation": A 4-5 sentence plain-English explanation of the Fusobacteria level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Fusobacteria does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "proteobacteria_interpretation": A 4-5 sentence plain-English explanation of the Proteobacteria level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Proteobacteria does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "diversity_interpretation": A 4-5 sentence plain-English explanation of the Shannon diversity score for this pet: state the score and its band EXACTLY as given in the Level assessment above (low / medium / high — do NOT re-judge it), what diversity means for gut health, what this band means for the pet by name, and a forward-looking note.{$phylaSuffix}
- "vet_summary": The DETAIL paragraph, printed DIRECTLY BELOW "summary" under the same heading, so it must ADD to it and never restate it. Its job is the specifics BEHIND the headline: the dominant phyla with their figures, the notable taxa actually named in this pet's sample, the key imbalance(s) versus the healthy ranges, and what that means practically, ending with a brief forward-looking note. Do NOT repeat the overall verdict, any figure, or any sentence already given in "summary" — the reader sees both paragraphs together, one after the other. Up to 4 sentences, and fewer if there is less to say; never pad. Keep it warm and readable for a pet owner — avoid clinical jargon. It MUST address the pet by name when a name is provided.{$vetSummarySuffix}
- "recommended_actions": 4 to 5 distinct, practical recommendations grounded in this pet's specific findings, separated by newlines. Each recommendation must be a full sentence stating the action together with a brief reason explaining why it helps this pet (the action plus why it helps). Make them substantial and genuinely useful, not padded or repetitive, and avoid em dashes.

Grounding rules (these are critical and override any temptation to embellish):
- NO REPETITION ACROSS FIELDS: these fields are printed together as consecutive paragraphs of one report, not read in isolation — "summary" and "vet_summary" sit directly one above the other under a single heading. Every field must do its OWN job and add NEW information. Never write the same point, the same figure or the same sentence twice in two different fields, and never re-introduce in a later paragraph something the reader has just read. Say each thing ONCE, in the field whose job it is. Rephrasing the same point in different words still counts as repeating it.
- BE DIRECT AND OBJECTIVE: lead with the finding, then explain it. State what the numbers actually show in plain, declarative language. Cut hedging and filler ("it is worth noting", "generally speaking", "may potentially", "as we can see"). Do not pad to reach a length: if there is less to say, write less — a short, specific paragraph is better than a long, vague one. Being direct does NOT mean being cold or clinical: this is written for a worried owner, so stay warm, human and reassuring while being straight with them.
- PRONOUNS: {$pronounInstruction} This applies to EVERY field without exception — the summary, vet_summary, goal, recommended_actions, every per-phylum interpretation and the diversity interpretation. Do not switch pronouns partway through the report.
- The findings and metrics provided above are FIXED facts. Restate them faithfully and never contradict, embellish, or "improve" them.
- Do not invent or alter any numbers. When you state a percentage or the diversity score, use exactly the figures provided in the data above; do not round them differently, estimate, or infer values that were not given.
- You may name the phyla provided above and the specific taxa listed in the "Notable taxa detected" section (when that section is present). Do NOT name, invent, or infer ANY organism that is not in those lists — this applies especially to the open-prose fields (summary, vet_summary, recommended_actions). If there is no "Notable taxa detected" section, name only the phyla. Never introduce a bacterium, genus, or species you were not given.
- If you cannot ground a statement in the provided data, omit it rather than inventing or speculating.
- You must still state the actual figures where a field asks for them: the phylum and diversity fields should state the level/score as instructed. State them accurately using the exact numbers above; do not drop the numbers, just never change them.
- The band verdicts in the Level assessment above (low / within the typical range / high; and low / medium / high for diversity) are FIXED and already computed from the figures. State each level EXACTLY as given and keep the prose consistent with it. NEVER describe a value given as LOW as normal, within range, healthy, or elevated; NEVER describe a value given as HIGH as low, normal, or within range; only a value given as WITHIN the range may be called normal or within the typical range. Do not invent specific clinical consequences of a level; keep the explanation to accurate general statements.{$coherenceRule}{$reconcileRule}

Style: Write in warm, natural, plain British English as if a knowledgeable person were explaining to a pet owner. Vary sentence length and structure. Avoid AI-cliché phrasing (e.g. 'it's important to note', 'plays a crucial role', 'in conclusion', 'delve', 'tapestry', 'navigating'). Do NOT use em dashes (—) or en dashes (–) anywhere; use commas, full stops, or the word 'to' for ranges. Keep it concrete and specific to this pet's findings, not generic. Do not overuse lists. Write tight: every sentence must earn its place by adding something the reader does not already know from the sentence before it. Prefer the shorter, plainer wording over the longer, more decorative one.

Return ONLY the JSON object, no markdown or extra text.
PROMPT;

        // Append admin-configured GLOBAL directives (if any) as additional
        // instructions, without removing or rewriting the base prompt above.
        // This stays at the very end, exactly as it did before per-section
        // directives existed.
        $directives = Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES);
        if (filled($directives)) {
            $prompt .= "\n\nAdditional instructions from the administrator (follow these as well, while still returning only the JSON object):\n{$directives}";
        }

        return $prompt;
    }

    /**
     * Read a per-section directive setting and render it as an inline suffix to
     * append to a prompt bullet. Returns '' when the setting is blank, so the
     * bullet is left byte-for-byte unchanged.
     */
    protected function directiveSuffix(string $settingKey): string
    {
        $value = Setting::get($settingKey);

        if (blank($value)) {
            return '';
        }

        return ' Admin guidance for this field: '.trim((string) $value);
    }

    /**
     * Generate the pet-specific copy for a plan. Takes the pet findings and the
     * fixed plan scaffold; returns the same scaffold shape with the model's copy
     * fields filled. The CALLER validates factual fields against the scaffold —
     * this method only performs the request/parse. On ANY failure it returns the
     * scaffold with empty copy fields and never throws.
     *
     * Reuses the same auth/HTTP/error-handling style as
     * generateReportInterpretations().
     */
    public function generatePlanCopy(array $petFindings, array $planScaffold, ?int $reportId = null): array
    {
        $apiKey = Setting::getDecrypted(Setting::OPENAI_API_KEY);
        if (empty($apiKey)) {
            $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        }

        if (empty($apiKey)) {
            Log::error('Plan copy generation: OpenAI API key is not configured.');

            return $this->emptyPlanCopy($planScaffold);
        }

        // Model: the single source of truth shared with the interpretation call
        // (Setting::OPENAI_MODEL, guarded; falls back to config default = gpt-4o).
        $model = self::resolveModel();

        // Temperature is unchanged: its own dedicated setting, default 0.4.
        $temperature = Setting::get(Setting::PLAN_GENERATION_TEMPERATURE);
        $temperature = is_numeric($temperature) ? (float) $temperature : 0.4;

        // System prompt: editable override if present, else the §1 default.
        $system = Setting::get(Setting::PLAN_GENERATION_SYSTEM_PROMPT);
        if (blank($system)) {
            $system = self::PLAN_SYSTEM_PROMPT;
        }

        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $userMessage = "PET FINDINGS:\n"
            .json_encode($petFindings, $jsonFlags)
            ."\n\nPLAN SCAFFOLD (fill the copy fields, return the whole object as JSON):\n"
            .json_encode($planScaffold, $jsonFlags);

        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'temperature' => $temperature,
        ]);

        try {
            $response = $this->requestChatCompletion($apiKey, $payload);

            if ($response === false) {
                Log::error('Plan copy generation: OpenAI API request failed.');

                return $this->emptyPlanCopy($planScaffold);
            }

            $decoded = json_decode($response, true);

            // Same PII rule as above: log only non-identifying metadata, never the
            // body/content (it embeds the pet's findings + owner health notes).
            Log::info('Plan copy generation: raw API response', [
                'has_choices' => isset($decoded['choices']),
                'usage' => $decoded['usage'] ?? null,
                'error_type' => $decoded['error']['type'] ?? null,
            ]);

            // Side-write usage tracking (no-ops on error/usage-less responses; never
            // throws). Secondary to generation — see recordUsage().
            if (! isset($decoded['error'])) {
                $this->recordUsage(AiUsageEvent::TYPE_PLAN_COPY, $model, $decoded['usage'] ?? null, $reportId);
            }

            if (isset($decoded['error'])) {
                Log::error('Plan copy generation: OpenAI API returned error.', [
                    'error_type' => $decoded['error']['type'] ?? null,
                    'error_code' => $decoded['error']['code'] ?? null,
                ]);

                return $this->emptyPlanCopy($planScaffold);
            }

            if (! isset($decoded['choices'][0]['message']['content'])) {
                Log::error('Plan copy generation: unexpected OpenAI response structure.', ['response_length' => strlen($response)]);

                return $this->emptyPlanCopy($planScaffold);
            }

            $content = trim($decoded['choices'][0]['message']['content']);

            // Strip markdown code fences if present.
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
            }

            $parsed = json_decode($content, true);

            if (! is_array($parsed)) {
                Log::error('Plan copy generation: failed to parse response as JSON.', ['content_length' => strlen($content)]);

                return $this->emptyPlanCopy($planScaffold);
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('Plan copy generation error: '.$e->getMessage());

            return $this->emptyPlanCopy($planScaffold);
        }
    }

    /**
     * Perform the chat-completion HTTP request and return the raw response body
     * (or false on transport failure). Isolated so tests can stub the HTTP layer
     * by overriding this single method, without changing the plumbing style.
     */
    protected function requestChatCompletion(string $apiKey, string $payload): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$apiKey,
                ]),
                'content' => $payload,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        return file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);
    }

    /**
     * Return the scaffold with all copy fields blanked — the safe "nothing
     * generated" result the caller falls back to (keeps placeholders + warns).
     */
    protected function emptyPlanCopy(array $scaffold): array
    {
        $scaffold['intro'] = '';

        foreach ($scaffold['steps'] ?? [] as $i => $step) {
            if (($step['type'] ?? 'product') === 'prose') {
                $scaffold['steps'][$i]['body'] = '';
                $scaffold['steps'][$i]['tip'] = null;

                continue;
            }

            foreach ($step['products'] ?? [] as $j => $product) {
                $scaffold['steps'][$i]['products'][$j]['how_it_helps'] = '';
            }
        }

        return $scaffold;
    }

    /**
     * System instruction for the diet-guidance call: STRICT JSON, exactly two string
     * keys. PLAIN TEXT (no HTML/markdown/fences) so the render layer can escape it and
     * there is no XSS surface. One food per line as "Food: reason" (a colon, not a
     * dash, matching the report's no-dash style).
     */
    public const DIET_GUIDANCE_SYSTEM_PROMPT = 'You are a veterinary microbiome and nutrition expert writing for pet owners. Return STRICT JSON only: a single object with EXACTLY two keys, "include" and "avoid". Each value is a STRING of warm, client-friendly PLAIN TEXT (no HTML, no markdown, no code fences, no dashes): a short one-sentence intro, then one food per line written as "Food: brief microbiome-linked reason". Across the two sections together, list between 8 and 12 foods. Base every item on this dog\'s actual findings and the guidance rules provided. Include no keys other than "include" and "avoid". Return ONLY the JSON object.';

    /**
     * Generate the two "Personalised Diet Guidance" boxes ("Foods to Include" /
     * "Foods to Avoid") from this dog's findings. Mirrors generatePlanCopy() /
     * generateReportInterpretations() exactly — same key resolution, resolveModel(),
     * requestChatCompletion() HTTP style, fence-stripping, PII-safe logging and usage
     * tracking. On ANY failure or missing key it returns ['include' => '', 'avoid' => '']
     * and sets lastErrorCode; it NEVER throws, so the rest of report generation is
     * unaffected. Both returned values are guaranteed strings (non-strings are coerced
     * to empty and logged), so the render layer never sees junk.
     *
     * The findings inputs are the SAME arrays the interpretation call receives
     * ($phylumTotals, $diversityScore, $petContext, $deterministic) — no parallel
     * findings derivation. The editable prompt + rules come from Settings.
     *
     * @return array{include: string, avoid: string}
     */
    public function generateDietGuidance(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = [], ?int $reportId = null): array
    {
        $this->lastErrorCode = null;
        $empty = ['include' => '', 'avoid' => ''];

        $apiKey = Setting::getDecrypted(Setting::OPENAI_API_KEY);
        if (empty($apiKey)) {
            $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        }

        if (empty($apiKey)) {
            Log::error('Diet guidance generation: OpenAI API key is not configured.');
            $this->lastErrorCode = 'api_failed';

            return $empty;
        }

        // Same single-source model as the other calls (guarded → gpt-4o default).
        $model = self::resolveModel();
        // Reasonable creativity for descriptive food copy; kept simple (no Setting).
        $temperature = 0.5;

        $userMessage = $this->buildDietGuidancePrompt($phylumTotals, $diversityScore, $petContext, $deterministic);

        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::DIET_GUIDANCE_SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'temperature' => $temperature,
        ]);

        try {
            $response = $this->requestChatCompletion($apiKey, $payload);

            if ($response === false) {
                Log::error('Diet guidance generation: OpenAI API request failed.');
                $this->lastErrorCode = 'api_failed';

                return $empty;
            }

            $decoded = json_decode($response, true);

            // PII-safe: log only non-identifying metadata, never the body/content.
            Log::info('Diet guidance generation: raw API response', [
                'has_choices' => isset($decoded['choices']),
                'usage' => $decoded['usage'] ?? null,
                'error_type' => $decoded['error']['type'] ?? null,
            ]);

            if (! isset($decoded['error'])) {
                $this->recordUsage(AiUsageEvent::TYPE_INTERPRETATION, $model, $decoded['usage'] ?? null, $reportId);
            }

            if (isset($decoded['error'])) {
                Log::error('Diet guidance generation: OpenAI API returned error.', [
                    'error_type' => $decoded['error']['type'] ?? null,
                    'error_code' => $decoded['error']['code'] ?? null,
                ]);
                $this->lastErrorCode = 'api_failed';

                return $empty;
            }

            if (! isset($decoded['choices'][0]['message']['content'])) {
                Log::error('Diet guidance generation: unexpected OpenAI response structure.', ['response_length' => strlen($response)]);
                $this->lastErrorCode = 'api_failed';

                return $empty;
            }

            $content = trim($decoded['choices'][0]['message']['content']);

            // Strip markdown code fences if present.
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
            }

            $parsed = json_decode($content, true);

            if (! is_array($parsed)) {
                Log::error('Diet guidance generation: failed to parse response as JSON.', ['content_length' => strlen($content)]);
                $this->lastErrorCode = 'json_parse_failed';

                return $empty;
            }

            // VALIDATION: both keys must be present and STRINGS; coerce anything else
            // to empty (never render junk).
            $include = $parsed['include'] ?? '';
            $avoid = $parsed['avoid'] ?? '';
            if (! is_string($include)) {
                Log::warning('Diet guidance generation: "include" was not a string; coerced to empty.');
                $include = '';
            }
            if (! is_string($avoid)) {
                Log::warning('Diet guidance generation: "avoid" was not a string; coerced to empty.');
                $avoid = '';
            }

            return ['include' => trim($include), 'avoid' => trim($avoid)];
        } catch (\Throwable $e) {
            Log::error('Diet guidance generation error: '.$e->getMessage());
            $this->lastErrorCode = 'api_failed';

            return $empty;
        }
    }

    /**
     * Deterministically decide which diet-guidance rules genuinely FIRE for this dog,
     * so the AI is handed only rules matched to the dog's real values and can no longer
     * invoke a "high X" rule for a dog whose X isn't high (the report-149 bug: white
     * fish "due to high Proteobacteria" when Proteobacteria was 5.12%).
     *
     * Each rule's finding is parsed to a direction (High/Low) + subject, then checked
     * against the SAME thresholds the rest of the report uses:
     *   - a phylum in ReportContent::PHYLUM_BANDS → fires only when the dog's value is
     *     above 'high' (for "High X") or below 'low' (for "Low X");
     *   - "diversity" → the report's diversity band (Low < 1.9 / High > 2.5);
     *   - "richness"  → RICHNESS_LOW_MAX (Low) / richnessHealthyMin() (High);
     *   - "dysbiosis" → DYSBIOSIS_HEALTHY_MAX (High) / DYSBIOSIS_HEALTHY_MIN (Low).
     * FAIL-SAFE: a finding with no parseable High/Low direction, an unknown subject, or
     * a value that can't be resolved for this dog goes to 'general' (offered as generic
     * guidance, NEVER fired as a dog-specific claim). Computable rules that do NOT fire
     * are dropped entirely. Pure array logic — never throws.
     *
     * @param  array<int,array{finding:string,recommend:string,reason:string}>  $rules
     * @return array{matched: array<int,array>, general: array<int,array>}
     */
    public function gateDietRules(array $rules, array $phylumTotals, float $diversityScore, array $deterministic = []): array
    {
        $matched = [];
        $general = [];

        $norm = static fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $insight = $deterministic['insight_taxa'] ?? [];

        // Case/format-insensitive value lookup: phylum totals first, then genus insight
        // taxa (snake_case keys like 'escherichia_shigella'). Null when not present.
        $resolve = function (string $subject) use ($phylumTotals, $insight, $norm): ?float {
            $target = $norm($subject);
            foreach ($phylumTotals as $k => $v) {
                if ($norm((string) $k) === $target && is_numeric($v)) {
                    return (float) $v;
                }
            }
            if (is_array($insight)) {
                foreach ($insight as $k => $v) {
                    if ($norm((string) $k) === $target && is_numeric($v)) {
                        return (float) $v;
                    }
                }
            }

            return null;
        };

        // Canonical PHYLUM_BANDS key matching the subject, or null.
        $phylumKey = function (string $subject) use ($norm): ?string {
            foreach (array_keys(\App\Support\ReportContent::PHYLUM_BANDS) as $k) {
                if ($norm($k) === $norm($subject)) {
                    return $k;
                }
            }

            return null;
        };

        foreach ($rules as $rule) {
            $finding = trim((string) ($rule['finding'] ?? ''));
            $lower = strtolower($finding);
            $dir = null;
            $subject = '';
            if (str_starts_with($lower, 'high ')) {
                $dir = 'high';
                $subject = trim(substr($finding, 5));
            } elseif (str_starts_with($lower, 'low ')) {
                $dir = 'low';
                $subject = trim(substr($finding, 4));
            }

            // No parseable High/Low direction → general guidance.
            if ($dir === null || $subject === '') {
                $general[] = $rule;

                continue;
            }

            // 1) Phylum-based finding (Proteobacteria, Prevotella, Firmicutes, …).
            if ($pk = $phylumKey($subject)) {
                $value = $resolve($pk);
                if ($value === null) {           // can't resolve this dog's value → fail safe
                    $general[] = $rule;

                    continue;
                }
                $verdict = \App\Support\ReportContent::phylumBandVerdict($pk, $value);
                if ($verdict === null) {
                    $general[] = $rule;

                    continue;
                }
                if (($dir === 'high' && $verdict['band'] === 'high')
                    || ($dir === 'low' && $verdict['band'] === 'low')) {
                    $matched[] = $rule;          // fires
                }
                // else: computable but not firing → dropped.
                continue;
            }

            // 2) Shannon diversity.
            if (str_contains(strtolower($subject), 'diversity')) {
                $band = \App\Support\ReportContent::diversityBand($diversityScore)['label']; // Low|Medium|High
                if (($dir === 'high' && $band === 'High') || ($dir === 'low' && $band === 'Low')) {
                    $matched[] = $rule;
                }

                continue;
            }

            // 3) Species richness.
            if (str_contains(strtolower($subject), 'richness')) {
                $r = $deterministic['species_richness'] ?? null;
                if (! is_numeric($r)) {
                    $general[] = $rule;

                    continue;
                }
                if (($dir === 'low' && (float) $r < \App\Support\ReportContent::RICHNESS_LOW_MAX)
                    || ($dir === 'high' && (float) $r > \App\Support\ReportContent::richnessHealthyMin())) {
                    $matched[] = $rule;
                }

                continue;
            }

            // 4) Dysbiosis.
            if (str_contains(strtolower($subject), 'dysbiosis')) {
                $d = $deterministic['dysbiosis_score'] ?? null;
                if (! is_numeric($d)) {
                    $general[] = $rule;

                    continue;
                }
                if (($dir === 'high' && (float) $d > \App\Support\ReportContent::DYSBIOSIS_HEALTHY_MAX)
                    || ($dir === 'low' && (float) $d < \App\Support\ReportContent::DYSBIOSIS_HEALTHY_MIN)) {
                    $matched[] = $rule;
                }

                continue;
            }

            // 5) Uncomputable subject (butyrate producers, protein fermentation, bile
            //    acid bacteria, inflammatory bacteria, Sutterella, carbohydrate
            //    fermenters, …) → general guidance, never a dog-specific claim.
            $general[] = $rule;
        }

        return ['matched' => $matched, 'general' => $general];
    }

    /**
     * Build the diet-guidance USER message: the editable prompt (Setting::
     * dietGuidancePrompt) + the finding→food rules (Setting::dietGuidanceRules),
     * DETERMINISTICALLY GATED (see gateDietRules) into APPLICABLE (matched to this
     * dog's real values) and GENERAL (uncomputable) sets, + THIS dog's findings (the
     * SAME inputs the interpretation call receives — phylum totals, diversity,
     * richness, dysbiosis, classification, notable taxa, genus insight taxa, plus the
     * pet's diet + health notes). Public so tests can assert its contents without HTTP.
     */
    public function buildDietGuidancePrompt(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = []): string
    {
        $phylumLines = [];
        foreach ($phylumTotals as $name => $pct) {
            $phylumLines[] = "- {$name}: {$pct}%";
        }
        $phylumList = implode("\n", $phylumLines);

        $findings = ["Shannon diversity index: {$diversityScore}"];
        $richness = $deterministic['species_richness'] ?? null;
        if ($richness !== null && $richness !== '') {
            $findings[] = "Species richness: {$richness}";
        }
        $dysbiosis = $deterministic['dysbiosis_score'] ?? null;
        if ($dysbiosis !== null && $dysbiosis !== '') {
            $findings[] = "Dysbiosis score: {$dysbiosis}";
        }
        $classification = trim((string) ($deterministic['microbiome_classification'] ?? ''));
        if ($classification !== '') {
            $findings[] = "Overall microbiome classification: {$classification}";
        }
        $findingsBlock = implode("\n", $findings);

        // Notable taxa (top_taxa) — the specific bacteria detected in this sample.
        $taxaBlock = '';
        $topTaxa = $deterministic['top_taxa'] ?? [];
        if (is_array($topTaxa) && $topTaxa !== []) {
            $taxaLines = [];
            foreach ($topTaxa as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $name = trim((string) ($t['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $pct = $t['pct'] ?? null;
                $taxaLines[] = '- '.$name.($pct !== null ? ": {$pct}%" : '');
            }
            if ($taxaLines !== []) {
                $taxaBlock = "\nNotable taxa detected in this sample:\n".implode("\n", $taxaLines)."\n";
            }
        }

        // Genus insight taxa (%), same source the deterministic insights use.
        $insightBlock = '';
        $insightTaxa = $deterministic['insight_taxa'] ?? [];
        if (is_array($insightTaxa) && $insightTaxa !== []) {
            $insightLines = [];
            foreach ($insightTaxa as $genus => $pct) {
                if (! is_numeric($pct)) {
                    continue;
                }
                $insightLines[] = "- {$genus}: {$pct}%";
            }
            if ($insightLines !== []) {
                $insightBlock = "\nGenus-level markers:\n".implode("\n", $insightLines)."\n";
            }
        }

        // Pet diet + owner health notes (from the shared petContext mapper).
        $petLines = [];
        $diet = trim((string) ($petContext['diet'] ?? ''));
        if ($diet !== '') {
            $petLines[] = "Current diet: {$diet}";
        }
        $notes = trim((string) ($petContext['health_notes'] ?? ''));
        if ($notes !== '') {
            $petLines[] = "Owner-reported health notes (context only, not clinical fact): {$notes}";
        }
        $petBlock = $petLines === [] ? '' : "\n".implode("\n", $petLines)."\n";

        // Editable finding→food rules (Settings), DETERMINISTICALLY GATED against this
        // dog's real values so the AI is handed only the rules that genuinely apply —
        // it can no longer invoke a "high X" rule for a dog whose X isn't high. Any
        // failure here falls back to treating all rules as GENERAL guidance (never a
        // dog-specific claim) and never throws (the caller's never-throws contract).
        try {
            $gated = $this->gateDietRules(Setting::dietGuidanceRules(), $phylumTotals, $diversityScore, $deterministic);
        } catch (\Throwable $e) {
            Log::warning('Diet guidance: rule gating failed; passing all rules as general guidance.', ['error' => $e->getMessage()]);
            $gated = ['matched' => [], 'general' => Setting::dietGuidanceRules()];
        }

        $fmt = static fn (array $r): string => '- '.trim((string) ($r['finding'] ?? '')).' => '.trim((string) ($r['recommend'] ?? '')).' ('.trim((string) ($r['reason'] ?? '')).')';
        $matchedBlock = $gated['matched'] === [] ? "(none matched this dog's measured values)" : implode("\n", array_map($fmt, $gated['matched']));
        $generalBlock = $gated['general'] === [] ? '(none)' : implode("\n", array_map($fmt, $gated['general']));

        $editablePrompt = Setting::dietGuidancePrompt();

        return <<<PROMPT
{$editablePrompt}

APPLICABLE RULES — the system has ALREADY matched these to THIS dog's measured values. Apply them where they fit the foods you choose; you MAY state the linked finding (e.g. that a level is raised or reduced) because it is confirmed for this dog:
{$matchedBlock}

GENERAL GUIDANCE — NOT tied to a specific measured value for this dog. You may draw on these for sensible everyday suggestions, but do NOT state that this dog has the associated finding (never call a level high or low) unless it appears under APPLICABLE RULES above:
{$generalBlock}

Do not invoke any finding that is not listed under APPLICABLE RULES. In particular, never describe a phylum or marker as high, low, elevated or reduced unless the system listed it as applicable above — base everything else on the findings below.

THIS DOG'S MICROBIOME FINDINGS:
Phylum percentages:
{$phylumList}
{$findingsBlock}
{$taxaBlock}{$insightBlock}{$petBlock}
Return ONLY the JSON object with exactly the keys "include" and "avoid".
PROMPT;
    }
}
