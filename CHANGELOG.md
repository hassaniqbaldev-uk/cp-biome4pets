# Changelog

All notable changes to the Biome4Pets portal are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/): newest version at the top, each
change grouped under **Added / Fixed / Changed / Improved**.

> **Workflow:** this file ships WITH the code. Add entries here as part of each
> release/deploy. The portal's **Changelog** page (System → Changelog) reads and
> displays this file read-only, and the version shown in the admin footer is taken
> from the top entry below, so there is no in-portal editing and the version cannot
> drift. Update the file, commit it, deploy, and the new version shows up everywhere
> automatically.

## [v1.4.0] - 2026-06-29

### Added
- Sensitive-pet plans: pets marked as sensitive now automatically receive the rosemary-free AMR product and the correct subscription checkout link.
- Bulk operations tool for administrators: regenerate or send many reports at once, with progress tracking and the ability to resume if it is interrupted.
- In-app error log viewer for administrators, with a manual option to clear the log.
- A changelog section in the admin so the team can see what has shipped in each release.

### Fixed
- Corrected an issue where applying a plan to a report could fail in certain cases.
- The subscription preview screen now shows the correct product for sensitive pets throughout.

### Improved
- Email and web addresses shown in reports are now clickable links.
- Reports can be sent to Klaviyo more than once when needed.
- Tidied the downloadable PDF, with a clearer plan button and a stray web address removed.

## [v1.3.0] - 2026-06-15

### Added
- Reports can now be sent in two ways: through Klaviyo or through the app's own email.
- "Sensitive animal" and "Large breed" options on pet profiles, with large breed selected automatically for pets over 35kg.
- The breed field now suggests existing breeds as you type and remembers new ones.
- "Home Cooked" added as a diet option.

### Changed
- Powder product quantities now read "pouch" instead of "tub".

### Improved
- Reports can be unpublished for editing, and anyone who visits in the meantime sees a friendly "being finalised" message.

## [v1.2.0] - 2026-06-08

### Added
- Bulk report regeneration tool for administrators, to apply report improvements to reports that already exist.
- An option to hide the subscription section on a report, for retests or customers already on a programme.

### Improved
- Reports are now fully mobile friendly, with no sideways scrolling and pricing shown correctly on phones.
- Clearer subscription pricing, showing the discounted monthly price.
- A tidier footer on the downloadable PDF, with a clearer logo and neater layout.

## [v1.1.0] - 2026-06-01

### Added
- Branded welcome and password-reset emails, with a consistent look across all system emails.
- Year of birth for pets, instead of a full date, since owners do not always know the exact day.

### Fixed
- Improved report accuracy so the wording about each microbe level always matches the underlying figures.
- An automatic safety check that flags a report for review when its wording does not match the data.

### Changed
- Updated the platform's branding colour.
- Refined staff access levels for Admins and Super Admins.

## [v1.0.0] - 2026-05-19

### Added
- Initial release of the Biome4Pets portal: gut microbiome report generation, treatment plans, client and pet management, and report delivery to customers.
