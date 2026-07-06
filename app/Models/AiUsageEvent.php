<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded OpenAI call's token usage. Written as a side-effect of a successful
 * generation (never on the critical path — a write failure is swallowed), so the
 * OpenAI settings can show real usage totals and a cost estimate.
 */
class AiUsageEvent extends Model
{
    protected $fillable = [
        'report_id',
        'call_type',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
    ];

    /** The two OpenAI calls per the audit. */
    public const TYPE_INTERPRETATION = 'interpretation';

    public const TYPE_PLAN_COPY = 'plan_copy';

    public const CALL_TYPES = [self::TYPE_INTERPRETATION, self::TYPE_PLAN_COPY];

    /**
     * Observed baseline token usage for ONE report (1 interpretation + 1 plan
     * copy), split into prompt/completion so the "cost of ~100 reports" guide can
     * price each direction correctly. Used as the fallback until enough real
     * usage is tracked. The totals mirror the audit's ~3,751 interpretation +
     * ~2,753 plan copy ≈ 6,500 tokens/report; the prompt/completion split within
     * each is an assumption (the interpretation prompt is large with a moderate
     * JSON completion; the plan-copy prompt and completion are closer in size).
     */
    public const BASELINE_INTERPRETATION_PROMPT = 3000;

    public const BASELINE_INTERPRETATION_COMPLETION = 751;

    public const BASELINE_PLAN_COPY_PROMPT = 1500;

    public const BASELINE_PLAN_COPY_COMPLETION = 1253;

    /** Nullable — a call is often made before the report row is saved. */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * Aggregate usage for the settings totals view. Defensive: if the table is not
     * present yet (e.g. before migration) it returns a zeroed structure rather than
     * throwing, so the settings page can never break on it.
     *
     * @return array{
     *   overall: array{calls:int, prompt_tokens:int, completion_tokens:int, total_tokens:int},
     *   last_30_days: array{calls:int, total_tokens:int},
     *   by_type: array<string, array{calls:int, total_tokens:int}>
     * }
     */
    public static function summary(): array
    {
        $zero = [
            'overall' => ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'last_30_days' => ['calls' => 0, 'total_tokens' => 0],
            'by_type' => [
                self::TYPE_INTERPRETATION => ['calls' => 0, 'total_tokens' => 0],
                self::TYPE_PLAN_COPY => ['calls' => 0, 'total_tokens' => 0],
            ],
        ];

        try {
            $overall = static::query()
                ->selectRaw('COUNT(*) AS calls')
                ->selectRaw('COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens')
                ->selectRaw('COALESCE(SUM(completion_tokens), 0) AS completion_tokens')
                ->selectRaw('COALESCE(SUM(total_tokens), 0) AS total_tokens')
                ->first();

            $recent = static::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('COUNT(*) AS calls')
                ->selectRaw('COALESCE(SUM(total_tokens), 0) AS total_tokens')
                ->first();

            $byType = $zero['by_type'];
            foreach (self::CALL_TYPES as $type) {
                $row = static::query()
                    ->where('call_type', $type)
                    ->selectRaw('COUNT(*) AS calls')
                    ->selectRaw('COALESCE(SUM(total_tokens), 0) AS total_tokens')
                    ->first();
                $byType[$type] = [
                    'calls' => (int) ($row->calls ?? 0),
                    'total_tokens' => (int) ($row->total_tokens ?? 0),
                ];
            }

            return [
                'overall' => [
                    'calls' => (int) ($overall->calls ?? 0),
                    'prompt_tokens' => (int) ($overall->prompt_tokens ?? 0),
                    'completion_tokens' => (int) ($overall->completion_tokens ?? 0),
                    'total_tokens' => (int) ($overall->total_tokens ?? 0),
                ],
                'last_30_days' => [
                    'calls' => (int) ($recent->calls ?? 0),
                    'total_tokens' => (int) ($recent->total_tokens ?? 0),
                ],
                'by_type' => $byType,
            ];
        } catch (\Throwable) {
            return $zero;
        }
    }

    /**
     * The editable per-model token rates, as model => {input_per_1k, output_per_1k}.
     * Starts from the seeded defaults and lets a valid stored JSON map override /
     * extend them, so a model always resolves to *some* rate and a malformed stored
     * value can never break the estimate (it simply falls back to the defaults).
     *
     * @return array<string, array{input_per_1k: float, output_per_1k: float}>
     */
    public static function resolveRates(): array
    {
        $rates = Setting::OPENAI_TOKEN_RATES_DEFAULT;

        $stored = Setting::get(Setting::OPENAI_TOKEN_RATES);
        if (filled($stored)) {
            $decoded = json_decode((string) $stored, true);
            if (is_array($decoded)) {
                foreach ($decoded as $model => $rate) {
                    if (! is_string($model) || $model === '' || ! is_array($rate)) {
                        continue;
                    }
                    $input = $rate['input_per_1k'] ?? null;
                    $output = $rate['output_per_1k'] ?? null;
                    if (! is_numeric($input) || ! is_numeric($output)) {
                        continue;
                    }
                    $rates[$model] = [
                        'input_per_1k' => (float) $input,
                        'output_per_1k' => (float) $output,
                    ];
                }
            }
        }

        return $rates;
    }

    /**
     * Estimated spend from the tracked usage, computed PER EVENT with THAT event's
     * model's rate (the model can change over time), summed. Prompt tokens are
     * priced at the model's input rate, completion tokens at its output rate.
     *
     * A model with no configured rate is flagged in `missing_rate_models` and, so
     * the figure is never silently zero, priced at the gpt-4o fallback rate rather
     * than erroring. Defensive like summary(): a missing table returns zeros.
     *
     * @param  array<string, array{input_per_1k: float, output_per_1k: float}>|null  $rates
     * @return array{all_time: float, last_30_days: float, missing_rate_models: array<int, string>}
     */
    public static function costSummary(?array $rates = null): array
    {
        $rates ??= self::resolveRates();
        $fallback = $rates['gpt-4o'] ?? ['input_per_1k' => 0.0, 'output_per_1k' => 0.0];

        $zero = ['all_time' => 0.0, 'last_30_days' => 0.0, 'missing_rate_models' => []];

        try {
            $missing = [];

            $price = function ($rows) use ($rates, $fallback, &$missing): float {
                $total = 0.0;
                foreach ($rows as $row) {
                    $model = (string) $row->model;
                    $rate = $rates[$model] ?? null;
                    if ($rate === null) {
                        $missing[$model] = true; // flag, but still estimate with a fallback
                        $rate = $fallback;
                    }
                    $total += ((int) $row->prompt_tokens / 1000) * $rate['input_per_1k'];
                    $total += ((int) $row->completion_tokens / 1000) * $rate['output_per_1k'];
                }

                return $total;
            };

            $byModel = static::query()
                ->selectRaw('model')
                ->selectRaw('COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens')
                ->selectRaw('COALESCE(SUM(completion_tokens), 0) AS completion_tokens')
                ->groupBy('model')
                ->get();

            $recentByModel = static::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('model')
                ->selectRaw('COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens')
                ->selectRaw('COALESCE(SUM(completion_tokens), 0) AS completion_tokens')
                ->groupBy('model')
                ->get();

            $allTime = $price($byModel);
            $recent = $price($recentByModel);

            return [
                'all_time' => $allTime,
                'last_30_days' => $recent,
                'missing_rate_models' => array_keys($missing),
            ];
        } catch (\Throwable) {
            return $zero;
        }
    }

    /**
     * The "cost of ~N reports" guide. A report = 1 interpretation + 1 plan-copy
     * call. Uses the AVERAGE tokens per report from real tracked data when both
     * call types have been recorded, otherwise the observed baseline
     * (BASELINE_* ≈ 6,500 tokens/report). Priced at the CURRENT model's rate.
     *
     * Returns the full breakdown so the UI can show it transparently. This is an
     * estimate PER GENERATION EVENT — each regeneration is another interpretation
     * call (~the interpretation share of tokens) and adds cost.
     *
     * @param  array<string, array{input_per_1k: float, output_per_1k: float}>|null  $rates
     * @return array{
     *   reports: int, model: string, rate_configured: bool, source: string,
     *   prompt_per_report: float, completion_per_report: float, tokens_per_report: float,
     *   total_tokens: float, interpretation_tokens: float, cost: float
     * }
     */
    public static function reportEstimate(int $reports = 100, ?array $rates = null, string $model = 'gpt-4o'): array
    {
        $rates ??= self::resolveRates();
        $rateConfigured = isset($rates[$model]);
        $rate = $rates[$model] ?? $rates['gpt-4o'] ?? ['input_per_1k' => 0.0, 'output_per_1k' => 0.0];

        $tracked = self::averagePerReportTokens();

        if ($tracked !== null) {
            $prompt = $tracked['prompt'];
            $completion = $tracked['completion'];
            $interpretation = $tracked['interpretation_total'];
            $source = 'tracked';
        } else {
            $prompt = self::BASELINE_INTERPRETATION_PROMPT + self::BASELINE_PLAN_COPY_PROMPT;
            $completion = self::BASELINE_INTERPRETATION_COMPLETION + self::BASELINE_PLAN_COPY_COMPLETION;
            $interpretation = self::BASELINE_INTERPRETATION_PROMPT + self::BASELINE_INTERPRETATION_COMPLETION;
            $source = 'baseline';
        }

        $costPerReport = ($prompt / 1000) * $rate['input_per_1k'] + ($completion / 1000) * $rate['output_per_1k'];

        return [
            'reports' => $reports,
            'model' => $model,
            'rate_configured' => $rateConfigured,
            'source' => $source,
            'prompt_per_report' => $prompt,
            'completion_per_report' => $completion,
            'tokens_per_report' => $prompt + $completion,
            'total_tokens' => ($prompt + $completion) * $reports,
            'interpretation_tokens' => $interpretation,
            'cost' => $costPerReport * $reports,
        ];
    }

    /**
     * Average prompt/completion tokens for ONE report from tracked data, taking
     * each call type's own average (a report = 1 interpretation + 1 plan copy).
     * Returns null unless BOTH call types have at least one recorded event, so the
     * caller falls back to the documented baseline until enough data accrues.
     * Defensive: a missing table returns null.
     *
     * @return array{prompt: float, completion: float, interpretation_total: float}|null
     */
    protected static function averagePerReportTokens(): ?array
    {
        try {
            $avg = function (string $type): ?array {
                $row = static::query()
                    ->where('call_type', $type)
                    ->selectRaw('COUNT(*) AS calls')
                    ->selectRaw('COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens')
                    ->selectRaw('COALESCE(SUM(completion_tokens), 0) AS completion_tokens')
                    ->first();

                $calls = (int) ($row->calls ?? 0);
                if ($calls === 0) {
                    return null;
                }

                return [
                    'prompt' => (int) $row->prompt_tokens / $calls,
                    'completion' => (int) $row->completion_tokens / $calls,
                ];
            };

            $interp = $avg(self::TYPE_INTERPRETATION);
            $plan = $avg(self::TYPE_PLAN_COPY);

            if ($interp === null || $plan === null) {
                return null; // not enough real data yet → use the baseline
            }

            return [
                'prompt' => $interp['prompt'] + $plan['prompt'],
                'completion' => $interp['completion'] + $plan['completion'],
                'interpretation_total' => $interp['prompt'] + $interp['completion'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
