<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Restore Setting boilerplate that migrate:fresh wipes but no other seeder
     * owns. Each entry is set ONLY when the stored value is currently blank, so
     * re-seeding never clobbers a value an admin has customised in the Settings
     * page (same "don't overwrite a non-blank value" guard the old web bootstrap
     * used). Add further default-bearing settings here as needed.
     */
    public function run(): void
    {
        $defaults = [
            Setting::SIGNS_OF_STABILITY => "As {pet}'s microbiome moves toward balance, there are several signs that indicate things are stabilising. These include consistent, well-formed stools, a steady and healthy appetite, good energy levels, a shiny coat and healthy skin, and settled digestion with minimal gas or discomfort. Improvements are usually gradual rather than sudden, most dogs show noticeable changes over several weeks as the gut environment adjusts. If you notice ongoing digestive upset, low energy, or other concerns, we recommend speaking with your microbiome specialist or veterinarian.",
            // Review figures shown on the subscribe interstitial (formerly hardcoded
            // constants in ReportController), now admin-editable in Settings.
            Setting::REVIEW_RATING => Setting::REVIEW_RATING_DEFAULT,
            Setting::REVIEW_COUNT => Setting::REVIEW_COUNT_DEFAULT,
            // Static every-report text blocks (formerly hardcoded in both report
            // views), now admin-editable so web + PDF stay in lockstep.
            Setting::REPORT_ABOUT_TEXT => Setting::REPORT_ABOUT_TEXT_DEFAULT,
            Setting::REPORT_APPROACH_TEXT => Setting::REPORT_APPROACH_TEXT_DEFAULT,
            Setting::REPORT_SUPPORT_TEXT => Setting::REPORT_SUPPORT_TEXT_DEFAULT,
        ];

        foreach ($defaults as $key => $value) {
            if (blank(Setting::get($key))) {
                Setting::set($key, $value);
            }
        }
    }
}
