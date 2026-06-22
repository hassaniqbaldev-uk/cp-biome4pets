<?php

namespace Tests\Unit;

use App\Support\Utm;
use PHPUnit\Framework\TestCase;

/**
 * The single UTM helper: consistent tagging, existing params preserved, no
 * doubling/clobbering, fragment kept, blank URLs untouched.
 */
class UtmTest extends TestCase
{
    public function test_appends_the_default_scheme(): void
    {
        $url = Utm::report('https://app.test/report/AbC123', 'subscribe', 'subscribe_cta');

        $this->assertStringContainsString('utm_source=biome4pets_app', $url);
        $this->assertStringContainsString('utm_medium=report', $url);
        $this->assertStringContainsString('utm_campaign=subscribe', $url);
        $this->assertStringContainsString('utm_content=subscribe_cta', $url);
        // The path (and its token) is untouched — UTMs are query params only.
        $this->assertStringStartsWith('https://app.test/report/AbC123?', $url);
    }

    public function test_email_and_klaviyo_helpers_set_medium_and_source(): void
    {
        $email = Utm::email('https://app.test/x', 'password_reset', 'reset_button');
        $this->assertStringContainsString('utm_medium=email', $email);
        $this->assertStringContainsString('utm_source=biome4pets_app', $email);

        $klaviyo = Utm::klaviyo('https://app.test/report/X', 'report_published', 'email_button');
        $this->assertStringContainsString('utm_medium=email', $klaviyo);
        $this->assertStringContainsString('utm_source=klaviyo', $klaviyo);
    }

    public function test_preserves_existing_query_params(): void
    {
        $url = Utm::email('https://app.test/reset?email=a%40b.com&token=xyz', 'password_reset');

        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('a@b.com', $q['email']);   // existing param intact
        $this->assertSame('xyz', $q['token']);
        $this->assertSame('email', $q['utm_medium']); // utm added alongside
    }

    public function test_does_not_double_or_clobber_when_already_tagged(): void
    {
        $once = Utm::report('https://app.test/a', 'shop', 'shop_link');
        $twice = Utm::report($once, 'shop', 'shop_link');

        $this->assertSame($once, $twice);                       // idempotent
        $this->assertSame(1, substr_count($twice, 'utm_source=')); // not doubled
    }

    public function test_existing_utm_wins_and_is_not_overwritten(): void
    {
        $url = Utm::report('https://app.test/a?utm_source=manual', 'shop');

        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('manual', $q['utm_source']);   // pre-existing utm preserved
        $this->assertSame(1, substr_count($url, 'utm_source='));
    }

    public function test_keeps_fragment_after_the_query(): void
    {
        $url = Utm::report('https://app.test/p?ref=1#frag', 'shop');

        $this->assertStringEndsWith('#frag', $url);
        $this->assertStringContainsString('ref=1', $url);
        $this->assertStringNotContainsString('#frag?', $url);
    }

    public function test_blank_url_is_returned_unchanged(): void
    {
        $this->assertSame('', Utm::report('', 'shop'));
        $this->assertSame('', Utm::email('   ', 'password_reset'));
    }
}
