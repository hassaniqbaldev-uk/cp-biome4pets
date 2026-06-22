<?php

namespace App\Support;

/**
 * Single source of UTM tagging for outbound / shareable links (reports + emails).
 *
 * ALL UTM tagging goes through here so the scheme is consistent and the campaign
 * can be changed in ONE place — never hardcode utm_* per link. Tagging is
 * idempotent and non-destructive: existing query params (including any pre-existing
 * utm_*) are preserved and WIN, so a URL is never doubled or clobbered, and the
 * token in a /report/{token} path is untouched (UTMs are query params, independent
 * of route resolution).
 *
 * Scheme:
 *   utm_source   — where the click originates. Default 'biome4pets_app';
 *                  'klaviyo' for Klaviyo-sent emails.
 *   utm_medium   — the surface: 'report' (links inside a report) or 'email'.
 *   utm_campaign — the purpose, e.g. 'report_share', 'subscribe', 'nutritionist',
 *                  'shop', 'password_reset', 'report_published'.
 *   utm_content  — optional finer detail (which button/link).
 */
class Utm
{
    public const DEFAULT_SOURCE = 'biome4pets_app';

    public const SOURCE_KLAVIYO = 'klaviyo';

    public const MEDIUM_REPORT = 'report';

    public const MEDIUM_EMAIL = 'email';

    /**
     * Append UTM params to $url. A blank URL is returned unchanged. Existing
     * params win over the UTMs we add, so calling this twice (or on a URL that is
     * already tagged) never doubles or overwrites a param.
     */
    public static function tag(string $url, string $medium, string $campaign, ?string $content = null, string $source = self::DEFAULT_SOURCE): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        $utms = array_filter([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
            'utm_content' => $content,
        ], static fn ($v): bool => $v !== null && $v !== '');

        return self::appendParams($url, $utms);
    }

    /** Tag a link that lives inside a report (utm_medium=report). */
    public static function report(string $url, string $campaign, ?string $content = null, string $source = self::DEFAULT_SOURCE): string
    {
        return self::tag($url, self::MEDIUM_REPORT, $campaign, $content, $source);
    }

    /** Tag a link in a platform email (utm_medium=email). */
    public static function email(string $url, string $campaign, ?string $content = null, string $source = self::DEFAULT_SOURCE): string
    {
        return self::tag($url, self::MEDIUM_EMAIL, $campaign, $content, $source);
    }

    /** Tag a link delivered via a Klaviyo email (utm_source=klaviyo, medium=email). */
    public static function klaviyo(string $url, string $campaign, ?string $content = null): string
    {
        return self::tag($url, self::MEDIUM_EMAIL, $campaign, $content, self::SOURCE_KLAVIYO);
    }

    /**
     * Merge $params into the URL's query string WITHOUT clobbering existing keys
     * (existing keys win → idempotent), preserving any #fragment.
     */
    protected static function appendParams(string $url, array $params): string
    {
        // Keep any fragment aside — UTMs belong in the query, before the '#'.
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');

        parse_str($query, $existing);

        // Union: existing params are kept as-is; only UTM keys not already present
        // are added — so existing query params and any prior utm_* are never
        // overwritten or duplicated.
        $merged = $existing + $params;

        $qs = http_build_query($merged);

        return $base.($qs !== '' ? '?'.$qs : '').$fragment;
    }
}
