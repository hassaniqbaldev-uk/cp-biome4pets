# Changelog

All notable changes to the Biome4Pets portal are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/): newest version at the top, each
change grouped under **Added / Fixed / Changed / Improved**.

> **Workflow:** this file ships WITH the code. Add entries here as part of each
> release/deploy — the portal's **Changelog** page (System → Changelog) reads and
> displays this file read-only, so there is no in-portal editing. Update the file,
> commit it, deploy, and the new version shows up automatically.

## [v1.4.0] - 2026-06-29

### Added
- **Sensitive-pet plan variants.** A plan can now swap a product for a flagged pet —
  e.g. a sensitive dog is automatically moved from standard AMR to AMR (Rosemary
  Free), with the right checkout link and the swapped product shown everywhere on the
  report.
- **Bulk operations.** A Super-Admin tools page for regenerating and sending many
  reports at once, with a live progress card so you can see how a run is going.
- **In-app error log viewer.** Super Admins can review application errors in the
  portal (under Settings) without server access, including a manual "Clear logs"
  button for a clean slate after reviewing.

### Fixed
- **Apply-plan error.** Fixed a crash that could occur when applying a plan to a
  report.
- **Interstitial product mismatch.** The "creating your plan" screen now shows the
  swapped product for a sensitive-pet report, matching the rest of the report.

### Improved
- **Clickable contact links.** The email address and web address shown on reports
  (web and PDF) are now proper clickable links.

## [v1.3.0] - 2026-06-15

### Added
- **Send a report two ways.** Reports can be sent via Klaviyo email or as a direct
  email from the app.
- **Breed autocomplete.** Pet breed is backed by a managed suggestion list, so staff
  pick a consistent breed name instead of free-typing variants.

### Changed
- **"Tub" renamed to "pouch".** Powder quantities now read "one pouch per month"
  across plans and already-generated reports.

### Fixed
- **Report accuracy.** Corrected microbiome band calculations and tightened pricing
  wording so figures read clearly and consistently.

### Improved
- **Unpublish to edit safely.** A published report can be unpublished to make edits;
  while unpublished, its public link shows a friendly "being finalised" page instead
  of stale content.
