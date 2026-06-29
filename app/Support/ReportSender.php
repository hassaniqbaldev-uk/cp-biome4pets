<?php

namespace App\Support;

use App\Mail\ReportPublishedMail;
use App\Models\Report;
use App\Services\KlaviyoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The SINGLE send path for a report, shared by the single-send admin actions and
 * (later) bulk send — so content, UTM tagging and send-tracking can never drift
 * between them. This is the exact logic that used to live inline in EditReport's
 * two send actions, lifted verbatim:
 *
 *   - publish-gate (never email a draft),
 *   - email-presence (skip a report with no client email),
 *   - per-channel dispatch reusing KlaviyoService::sendEvent / ReportPublishedMail,
 *   - recording the outcome via recordKlaviyoSend / recordAppSend.
 *
 * A success records a successful send (so hasBeenSent() flips); a hard failure
 * records a failed attempt (hasBeenSent() stays false); a Klaviyo 429 (rate limit)
 * records NOTHING and is flagged retryable, so a caller can leave the report for a
 * later retry without burning it as a permanent failure. The method NEVER throws.
 */
class ReportSender
{
    public const CHANNEL_KLAVIYO = 'klaviyo';

    public const CHANNEL_APP = 'app';

    /**
     * Send one report via one channel.
     *
     * @return array{ok:bool, skipped:bool, reason:?string, channel:string, message:string, retryable:bool}
     */
    public static function send(Report $report, string $channel): array
    {
        // Publish-gate: the public link doesn't work for a draft, so never email one.
        if ($report->status !== 'published') {
            return self::skip($channel, 'not_published', 'Report is not published.');
        }

        // Email-presence: nothing to send to.
        $email = $report->petClient?->email;
        if (blank($email)) {
            return self::skip($channel, 'no_email', 'No client email on this report.');
        }

        return match ($channel) {
            self::CHANNEL_KLAVIYO => self::sendViaKlaviyo($report, $email),
            self::CHANNEL_APP => self::sendViaApp($report, $email),
            default => self::skip($channel, 'unknown_channel', "Unknown send channel [{$channel}]."),
        };
    }

    /**
     * Klaviyo: the IDENTICAL report_published event + Utm::klaviyo payload the
     * single-send action built. A 429 is retryable and is deliberately NOT recorded.
     *
     * @return array{ok:bool, skipped:bool, reason:?string, channel:string, message:string, retryable:bool}
     */
    protected static function sendViaKlaviyo(Report $report, string $email): array
    {
        $result = app(KlaviyoService::class)->sendEvent('report_published', $email, [
            'report_id' => $report->id,
            'pet_name' => $report->pet?->name,
            // Klaviyo delivers this as an email link → tag for attribution.
            'report_url' => Utm::klaviyo($report->report_url, 'report_published', 'email_button'),
            // report_date is proxied from the linked Test; for a report with no test
            // (legacy/orphan) it is null — send null rather than Carbon::parse(null)
            // which would silently emit today.
            'report_date' => $report->report_date
                ? Carbon::parse($report->report_date)->format('F j, Y')
                : null,
            'client_name' => $report->petClient?->name,
            // Stamp the send time so the event's unique_id varies per send: a
            // deliberate re-send is a DISTINCT Klaviyo event that actually delivers,
            // instead of being deduped against the first send. Second-granularity, so
            // a same-second double-fire still collapses to one. Also used as the
            // event `time`, keeping the recorded time and the dedupe key consistent.
            'time' => Carbon::now()->toIso8601String(),
        ]);

        // Rate-limited → record NOTHING, flag retryable (leave it cleanly retriable).
        if ($result['retryable'] ?? false) {
            return [
                'ok' => false, 'skipped' => false, 'reason' => 'rate_limited',
                'channel' => self::CHANNEL_KLAVIYO, 'message' => $result['message'], 'retryable' => true,
            ];
        }

        $report->recordKlaviyoSend(
            $result['ok'],
            $result['ok'] ? 'Report sent to Klaviyo' : $result['message'],
        );

        return [
            'ok' => (bool) $result['ok'],
            'skipped' => false,
            'reason' => $result['ok'] ? null : 'send_failed',
            'channel' => self::CHANNEL_KLAVIYO,
            'message' => $result['message'],
            'retryable' => false,
        ];
    }

    /**
     * App (direct SMTP): the IDENTICAL ReportPublishedMail send, wrapped in the same
     * try/catch the single-send action used, with report($e) on failure.
     *
     * @return array{ok:bool, skipped:bool, reason:?string, channel:string, message:string, retryable:bool}
     */
    protected static function sendViaApp(Report $report, string $email): array
    {
        try {
            Mail::to($email)->send(new ReportPublishedMail($report));
            $report->recordAppSend(true, 'Report emailed to '.$email);

            return [
                'ok' => true, 'skipped' => false, 'reason' => null,
                'channel' => self::CHANNEL_APP, 'message' => 'Report emailed to '.$email, 'retryable' => false,
            ];
        } catch (\Throwable $e) {
            report($e);
            $report->recordAppSend(false, $e->getMessage());

            return [
                'ok' => false, 'skipped' => false, 'reason' => 'send_failed',
                'channel' => self::CHANNEL_APP, 'message' => $e->getMessage(), 'retryable' => false,
            ];
        }
    }

    /**
     * @return array{ok:bool, skipped:bool, reason:string, channel:string, message:string, retryable:bool}
     */
    protected static function skip(string $channel, string $reason, string $message): array
    {
        return ['ok' => false, 'skipped' => true, 'reason' => $reason, 'channel' => $channel, 'message' => $message, 'retryable' => false];
    }
}
