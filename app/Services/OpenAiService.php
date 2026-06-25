<?php

namespace App\Services;

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

    public function generateReportInterpretations(array $phylumTotals, float $diversityScore, array $petContext = [], array $deterministic = []): array
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
            'vet_summary' => '',
            'recommended_actions' => '',
            'score_gut_wall' => '',
            'score_skin_allergy' => '',
            'score_behaviour_mood' => '',
            'score_gut_barrier' => '',
            'score_gas_digestive' => '',
            'score_stress_resilience' => '',
        ];

        // Prefer the encrypted setting; fall back to config/env so nothing
        // breaks if the DB value isn't set.
        $apiKey = Setting::getDecrypted(Setting::OPENAI_API_KEY);
        if (empty($apiKey)) {
            $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        }

        $model = config('services.openai.model', env('OPENAI_MODEL', 'gpt-4'));

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
                'score_gut_wall' => $parsed['score_gut_wall'] ?? '',
                'score_skin_allergy' => $parsed['score_skin_allergy'] ?? '',
                'score_behaviour_mood' => $parsed['score_behaviour_mood'] ?? '',
                'score_gut_barrier' => $parsed['score_gut_barrier'] ?? '',
                'score_gas_digestive' => $parsed['score_gas_digestive'] ?? '',
                'score_stress_resilience' => $parsed['score_stress_resilience'] ?? '',
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
            .', Medium up to '.\App\Support\ReportContent::num(\App\Support\ReportContent::DIVERSITY_HIGH_MIN)
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
                    ."You SHOULD reference the most notable of these taxa BY NAME where it is natural and relevant, especially in the summary and vet_summary, so the report names the specific bacteria actually present in this pet's sample. Mention a meaningful few (the most abundant or noteworthy), not an exhaustive list of all of them, and weave them in naturally rather than dumping a list. Describe them factually (e.g. \"one of the more abundant genera here is Bacteroides, at 12.4%\"). Do NOT characterise any taxon as high, low, elevated, raised, reduced, depleted, overgrown, deficient, abnormal, or normal — you have not been given reference ranges for them, so any such judgement is forbidden.\n";
            }
        }

        // When a classification is supplied, require the prose to stay consistent
        // with it — without changing tone, scale, or thresholds.
        $coherenceRule = $classification !== ''
            ? "\n- The overall microbiome classification above is a FIXED finding. Keep the WHOLE interpretation consistent with it: do not describe a gut classified \"Imbalanced\" or \"Imbalanced & Depleted\" as thriving, fully balanced, or problem free, and acknowledge low species richness or imbalance honestly where relevant. Stay calm, supportive, non-alarmist and non-diagnostic — note what to work on without overstating risk."
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
        $scoresSuffix = $this->directiveSuffix(Setting::OPENAI_DIRECTIVE_SCORES);

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
- "summary": A 4-5 sentence overall summary of the pet's microbiome health, written warmly for the owner, referencing the pet by name when provided and touching on the overall balance and what it means for this pet.{$summarySuffix}
- "goal": A warm, encouraging goal statement of 2 to 3 sentences (no em dashes) that sets out one clear, concrete goal for this pet based on the diagnostics, such as bringing an out-of-range phylum back toward its healthy range and/or improving diversity over a sensible timeframe like "the coming weeks" or "8 to 12 weeks". Give a little context on what we are aiming for and why it matters for this pet, keeping it focused and not padded. It MUST reference the pet by name when a name is provided.
- "bacteroidetes_interpretation": A 4-5 sentence plain-English explanation of the Bacteroidetes level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Bacteroidetes does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "firmicutes_interpretation": A 4-5 sentence plain-English explanation of the Firmicutes level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Firmicutes does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "fusobacteria_interpretation": A 4-5 sentence plain-English explanation of the Fusobacteria level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Fusobacteria does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "proteobacteria_interpretation": A 4-5 sentence plain-English explanation of the Proteobacteria level for this pet: state the level and its band EXACTLY as given in the Level assessment above (low / within the typical range / high — do NOT re-judge or contradict it), what Proteobacteria does, what this particular band means for the pet by name, and a brief forward-looking note.{$phylaSuffix}
- "diversity_interpretation": A 4-5 sentence plain-English explanation of the Shannon diversity score for this pet: state the score and its band EXACTLY as given in the Level assessment above (low / medium / high — do NOT re-judge it), what diversity means for gut health, what this band means for the pet by name, and a forward-looking note.{$phylaSuffix}
- "vet_summary": A detailed 4-5 sentence personal summary about this pet's specific microbiome findings. Reference the dominant phyla, the key imbalance(s) versus healthy ranges, what this means specifically for THIS pet (addressing the pet by name when a name is provided), and end with a forward-looking note. Keep it warm and readable for a pet owner — avoid clinical jargon. It MUST address the pet by name when a name is provided.{$vetSummarySuffix}
- "recommended_actions": 4 to 5 distinct, practical recommendations grounded in this pet's specific findings, separated by newlines. Each recommendation must be a full sentence stating the action together with a brief reason explaining why it helps this pet (the action plus why it helps). Make them substantial and genuinely useful, not padded or repetitive, and avoid em dashes.
For the following 6 health insight scores, use exactly one of these values: "Very High", "High", "Medium", or "Low".
Scale meaning:
- "Very High" = significant concern, strong microbial evidence of risk
- "High" = notable concern, clear microbial patterns present
- "Medium" = moderate, some indicators present
- "Low" = minimal concern, microbiome patterns generally healthy in this area

- "score_gut_wall": Assess gut wall integrity based on the microbiome composition.
- "score_skin_allergy": Assess skin and allergy risk based on the microbiome composition.
- "score_behaviour_mood": Assess behaviour and mood impact based on the microbiome composition.
- "score_gut_barrier": Assess gut barrier and metabolic function based on the microbiome composition.
- "score_gas_digestive": Assess gas and digestive comfort based on the microbiome composition.
- "score_stress_resilience": Assess environmental stress resilience based on the microbiome composition.{$scoresSuffix}

Grounding rules (these are critical and override any temptation to embellish):
- PRONOUNS: {$pronounInstruction} This applies to EVERY field without exception — the summary, vet_summary, goal, recommended_actions, every per-phylum interpretation and the diversity interpretation. Do not switch pronouns partway through the report.
- The findings and metrics provided above are FIXED facts. Restate them faithfully and never contradict, embellish, or "improve" them.
- Do not invent or alter any numbers. When you state a percentage or the diversity score, use exactly the figures provided in the data above; do not round them differently, estimate, or infer values that were not given.
- You may name the phyla provided above and the specific taxa listed in the "Notable taxa detected" section (when that section is present). Do NOT name, invent, or infer ANY organism that is not in those lists — this applies especially to the open-prose fields (summary, vet_summary, recommended_actions). If there is no "Notable taxa detected" section, name only the phyla. Never introduce a bacterium, genus, or species you were not given.
- Each score_* value must be EXACTLY one of: Very High, High, Medium, Low, with no other text, punctuation, or explanation inside the score field.
- If you cannot ground a statement in the provided data, omit it rather than inventing or speculating.
- You must still state the actual figures where a field asks for them: the phylum and diversity fields should state the level/score as instructed. State them accurately using the exact numbers above; do not drop the numbers, just never change them.
- The band verdicts in the Level assessment above (low / within the typical range / high; and low / medium / high for diversity) are FIXED and already computed from the figures. State each level EXACTLY as given and keep the prose consistent with it. NEVER describe a value given as LOW as normal, within range, healthy, or elevated; NEVER describe a value given as HIGH as low, normal, or within range; only a value given as WITHIN the range may be called normal or within the typical range. Do not invent specific clinical consequences of a level; keep the explanation to accurate general statements.{$coherenceRule}

Style: Write in warm, natural, plain British English as if a knowledgeable person were explaining to a pet owner. Vary sentence length and structure. Avoid AI-cliché phrasing (e.g. 'it's important to note', 'plays a crucial role', 'in conclusion', 'delve', 'tapestry', 'navigating'). Do NOT use em dashes (—) or en dashes (–) anywhere; use commas, full stops, or the word 'to' for ranges. Keep it concrete and specific to this pet's findings, not generic. Do not overuse lists.

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
    public function generatePlanCopy(array $petFindings, array $planScaffold): array
    {
        $apiKey = Setting::getDecrypted(Setting::OPENAI_API_KEY);
        if (empty($apiKey)) {
            $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        }

        if (empty($apiKey)) {
            Log::error('Plan copy generation: OpenAI API key is not configured.');

            return $this->emptyPlanCopy($planScaffold);
        }

        // Model/temperature: dedicated settings first, then the shared default.
        $model = Setting::get(Setting::PLAN_GENERATION_MODEL)
            ?: config('services.openai.model', env('OPENAI_MODEL', 'gpt-4o'));

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
}
