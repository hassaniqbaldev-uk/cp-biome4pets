# Changelog

> **Workflow:** This changelog records the changes shipped to the Biome4Pets Portal in each release. Entries are added here as part of each release and displayed read-only in the portal. The platform version shown in the footer follows the most recent entry below.

All notable changes to the Biome4Pets Portal are listed here, most recent first.

## [v1.4.1] - 2026-07-06
### Improved
- Reworked the OpenAI settings so the AI model can now be chosen from the portal (previously it was fixed), with safe handling so an invalid choice cannot break report generation.
- Added AI usage tracking, recording the tokens used each time a report or plan is generated.
- Added a cost estimate in settings, including an estimated cost per report and a guide to the cost of generating around 100 reports, with editable pricing so it stays accurate. Clearly marked as an estimate rather than the actual OpenAI bill.
- Tidied the settings screens so the AI model now sits in the OpenAI section where it belongs.

## [v1.4.0] - 2026-06-30
### Added
- A changelog section in the admin so the team can see what has shipped in each release.
### Changed
- Moved the error logs into the Settings area for easier access.
### Improved
- Made email and web addresses in reports clickable links.
- Tidied the downloadable PDF, with a clearer plan button and a stray web address removed.

## [v1.3.9] - 2026-06-29
### Added
- Sensitive-pet plans: pets marked as sensitive now automatically receive the rosemary-free AMR product and the correct subscription checkout link, across the relevant plans.
- A bulk operations tool for administrators to regenerate or send multiple reports at once, with live progress and the ability to resume if interrupted.
- An in-app error log viewer so administrators can review recent issues without leaving the portal.
### Fixed
- The subscription preview screen now shows the correct product for sensitive pets throughout.
- Corrected an issue where applying a plan could fail in certain cases.
### Improved
- Reports can now be sent to Klaviyo more than once when needed, with a confirmation if a report was already sent.
- Reports can be unpublished for editing, showing a friendly "being finalised" message to anyone who visits the link in the meantime.
- Powder product quantities now read "pouch" instead of "tub".

## [v1.3.8] - 2026-06-25
### Added
- Reports now name the specific bacteria found in each pet's sample, giving owners more detail while staying readable.
- Breed field now suggests existing breeds as you type and remembers new ones, keeping breed names consistent.
### Improved
- Reports now show a "Sent" status once they have been delivered to a customer.
- The retest kit now shows its discounted price clearly alongside the usual price.
- Improved the review flags so a report is only flagged when it genuinely needs attention, and reflects the current state rather than the state when it was generated.
### Fixed
- Corrected pet pronouns so a report refers to each pet consistently throughout, using the pet's name where the sex is not recorded.

## [v1.3.7] - 2026-06-24
### Fixed
- Improved report accuracy so the wording about each microbe level always matches the underlying figures, including for Fusobacteria and Proteobacteria.
- Added an automatic safety check that flags a report for review if its wording does not match the data.
### Added
- A tool to regenerate existing reports in bulk, so report improvements can be applied to reports already created, with progress tracking and resume if interrupted.
- An option to hide the subscription section on a report, for retests or customers already on a programme.
- "Home Cooked" added as a diet option.
### Improved
- Pet date of birth is now recorded as year of birth, since owners do not always know the exact date.
- Tidied the downloadable PDF footer, with a clearer logo and neater layout.
- Reports can no longer be sent before they are published, preventing customers receiving links to unfinished reports.

## [v1.3.5] - 2026-06-22
### Added
- Reports can now be sent two ways: via Klaviyo or via the app's own email.
- "Sensitive animal" and "Large breed" options on pet profiles, with large breed selected automatically for pets over 35kg.
### Improved
- Clearer subscription pricing, showing the discounted monthly price and the saving.
- Made the report layout fully mobile friendly, with no sideways scrolling and pricing that displays properly on phones.
### Changed
- Updated the platform's branding colour across the portal.
- Refined staff access levels for Admins and Super Admins.

## [v1.3.3] - 2026-06-19
### Added
- New branded welcome and password-reset emails, with a consistent look across all system emails.
- A report history view so previous reports can be found and reviewed easily.
### Improved
- Clearer subscription pricing on the report, including the 15 percent saving and a dedicated price panel.
- Refined the subscribe screen customers see after viewing their report.
- General admin experience improvements throughout the portal.

## [v1.3.0] - 2026-06-11
### Added
- Full treatment plans: a plan can be defined with multiple phases and products, and applied to a report so the customer gets one subscription that adapts each month.
- A plan builder for administrators to create and manage these plans.
### Improved
- Reports and the downloadable PDF now render the full plan for the customer.

## [v1.2.6] - 2026-06-06
### Added
- An admin dashboard with key statistics, a reports-per-month chart, and a recent-reports list.
### Improved
- Tidied the admin navigation, grouping settings and tools together and adding a clear logout option.

## [v1.2.4] - 2026-06-03
### Added
- OpenAI settings: the API key is now stored securely in the portal, along with editable prompt directives that guide the AI's wording.
- Configurable product trigger rules, so the rules that suggest products can be managed from settings.
- A "Report an Issue" option so the team can flag problems directly from the portal.
- Shopify reference fields on clients and pets, to link portal records with Shopify.

## [v1.2.2] - 2026-05-31
### Added
- Email sending set up (SMTP), so reports and system emails can be delivered to customers.
- Klaviyo integration, so reports can be sent through Klaviyo.

## [v1.2.0] - 2026-05-29
### Added
- A reusable product catalogue with multi-trigger tagging, replacing the earlier simple product list.
- Automatic matching of catalogue products from the trigger rules during report creation.
### Improved
- Reports and the PDF now use the product catalogue throughout.

## [v1.1.6] - 2026-05-27
### Added
- The Pet layer: a client can now have multiple pets, and each report belongs to a specific pet.
### Improved
- Pets are managed under each client, with the report form updated so the pet is chosen after the client.

## [v1.1.4] - 2026-05-25
### Improved
- The report's final section became a full plan preparation step, replacing the simple product selection.
### Added
- Automatic product suggestions based on each pet's results.

## [v1.1.0] - 2026-05-23
### Added
- AI interpretations: reports now include AI-generated wording explaining each pet's results.
- A global AI directive setting to guide the AI's tone and content.

## [v1.0.6] - 2026-05-22
### Added
- Expanded the report with the full set of sections owners see today, including a veterinary summary, a personal summary of the pet's results, an explanation of the findings, direct links, and help and contacts.
- Added a "Very High" level to the health insights.
### Improved
- Rebuilt the downloadable PDF report for reliable rendering, and later made it more compact (reduced from 18 pages to 11).
- Fixed spacing and layout issues so later PDF pages and the metric boxes display correctly.

## [v1.0.3] - 2026-05-21
### Added
- Product trigger rules, suggesting the right products based on each pet's results.
### Improved
- Redesigned the report with Biome4Pets branding.

## [v1.0.1] - 2026-05-20
### Improved
- Added the "Process CSV and Generate Content" step, so uploading a lab CSV produces the report content in one action.
- Expanded the report with microbiome classification, diversity and richness scores, health scores, a vet summary, and recommended actions.

## [v1.0.0] - 2026-05-19
### Added
- Initial release of the Biome4Pets Portal: client and report management, a step-by-step report creation form (pet details, lab CSV upload, and product selection), automatic processing of the uploaded CSV into microbiome levels and a diversity score, a public report page for customers, and a downloadable PDF.
