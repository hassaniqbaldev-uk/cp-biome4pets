# Build prompt — replace "Product Catalog" with "Plans"

> Hand this to the coding assistant working in the existing codebase.
> **Two files are attached and are part of this prompt:**
> 1. `plan-example.html` — the rendered look-and-feel a generated plan must match.
> 2. `plan-generation-prompt.md` — the LLM prompt + plan scaffolds that fill pet-specific copy at report time.

---

## 0. Read this before writing any code

You are working inside an **existing application** — not a greenfield project. Everything below (field names, the HTML example, the generation prompt, the plan scaffolds) was written **without knowledge of this codebase**. Treat it as a functional spec, not as literal names or structure to paste in.

Before implementing:

1. **Audit the current app.** Identify the framework, data layer, component library, routing, auth, admin structure, styling tokens, and — critically — the existing **naming conventions**. Find where "Product Catalog" lives and how products, the report builder, and the report's product/plan step are currently modelled.
2. **Conform to what exists.** Reuse the app's existing components, data access patterns, design tokens, and naming. Do **not** introduce a parallel pattern, a second styling system, or new conventions where the app already has one. Map the spec's field names onto the app's real ones.
3. **Where this spec conflicts with the codebase, the codebase wins** — follow existing conventions and note the deviation in your summary.
4. **Produce a short implementation plan first** (files to add/change, data migrations, open questions) and surface it before doing the full build.
5. **Don't silently resolve ambiguity.** A few client decisions are still open (listed in §8) — implement sensible defaults but flag them, don't bury them.

---

## 1. What's changing — summary

- Replace the **"Product Catalog"** admin area with a **"Plans"** area (rename in nav, routes, labels, and any page titles). Products themselves still exist and are still referenced by plans — only the catalog *section* is superseded by Plans. Provide a migration / redirect so existing links don't break.
- **Plans** is a CRUD area containing **four plans** (defined in §3). Each plan is an ordered list of **steps**; a step is either a **product step** (one or more products) or a **prose step** (free text).
- In the **report-creation flow**, in the section where products are currently selected, add a **Plan selector**. The admin selects which plan this pet is on; that selection drives the plan section of the generated report.
- A generated plan **renders to match `plan-example.html`** (subscribe panel at the top, stepped product cards, "included in plan" / "optional add-on" tags, individual buy buttons).
- **Subscriptions** are monthly and priced **20% below the combined individual product price** (see §4).
- Add **settings** to manage plans at the **individual plan level** (when editing a plan) and at the **platform level** (global defaults) — see §6.

---

## 2. Data model

Adapt names to the codebase. Conceptually a plan is:

```
Plan
  id, key (e.g. "restore-rebalance"), name, trigger_description, enabled
  species_availability (dog / cat / both)
  subscription: { enabled, discount_percent (default from platform), billing_interval: "monthly",
                  provider_ref (e.g. Loop selling-plan id, nullable) }
  steps: [ Step ]

Step
  id, order, type: "product" | "prose"
  step_title, stage_label
  (product) products: [ PlanProduct ]      # one OR many (Plan D step 1 has two)
  (prose)   body, tip                       # body/tip generated at report time

PlanProduct
  product_ref            # reference to an EXISTING product record — do not duplicate product data
  duration, quantity
  dose (default: "Follow recommended dose on label.")
  inclusion: "included" | "optional"        # "optional" = add-on, not in subscription (e.g. retest)
  how_it_helps           # generated at report time, empty in the stored scaffold
```

Key rule: **plans reference existing products by id/handle; they do not copy product price/name.** Price and name come from the live product record so the catalog stays the single source of truth.

The full JSON scaffolds for all four plans are in the attached `plan-generation-prompt.md` (§3 of that file) — seed the Plans table from them, mapping `product_url`/name onto your real product records.

---

## 3. The four plans (client's specification — build all four)

Implement **all four**. Plan A is fully worked in the attachment and the HTML example; build B, C and D to the same shape from the client's descriptions below.

**Plan A — Restore & Rebalance** *(trigger: AMR + Prebiotic recommended)*
3 months AMR → 4 months Prebiotic → Maintenance ongoing. Retest ~month 6. Steps: AMR (Phase 1) · dietary-change prose · Prebiotic (Phase 2) · retest checkpoint (optional) · Maintenance.

**Plan B — Reset & Recover** *(trigger: AMR + Antimicrobic recommended)*
3 months AMR → 4 months Antimicrobic → Maintenance ongoing. Retest ~month 6. Same shape as Plan A with Antimicrobic in Phase 2.

**Plan C — Maintain & Protect** *(trigger: microbiome overview all green)*
Maintenance only, ongoing. Single product step.

**Plan D — Rebuild & Renew** *(trigger: FMT recommended)*
3 months AMR **and** Gut Renew (FMT) taken **together** → retest at month 3 → then Maintenance. Step 1 holds two products in one step. This plan has a higher price point because Gut Renew is £130/mo, and needs its own subscription tier.

> Products map to the live shop: PetBiome AMR (£35), PetBiome Prebiotic (£35), Antimicrobic (£35), PetBiome Maintenance (£35), Gut Renew (£130), PetBiome Gut Microbiome Test Kit (£180, retest, optional add-on).

---

## 4. Subscription pricing rule

Subscriptions are **monthly** and cost **20% less than buying the plan's products individually**.

- For each month, the **individual monthly cost** = sum of the prices of the products active that month (i.e. the current phase's `included` products; `optional` add-ons like the retest are excluded).
- **Subscription monthly price = individual monthly cost × (1 − discount_percent)**, discount_percent defaulting to 20% (platform setting, overridable per plan).
- Display the **combined individual price**, the **discounted subscription price**, and the **saving / "Save 20%"** on the subscribe panel.
- Where the monthly cost changes by phase, the subscription price changes by phase (handle as a phased subscription if the subscription provider supports it).

Worked examples (using current shop prices):
- Plans A, B, C — active powder £35/mo individual → **£28/mo** subscription (save £7/mo).
- Plan D — months 1–3: AMR £35 + Gut Renew £130 = £165/mo → **£132/mo** (save £33/mo); then Maintenance £35 → **£28/mo**.

Compute these from product prices at render time — never hardcode — so a price change in the catalog flows through automatically.

---

## 5. Report-creation integration

In the report-builder, in the existing **products section**:

1. Add a **Plan selector** (single-select of enabled plans, optionally filtered by the pet's species).
2. On selection, load the plan scaffold, then run the **plan generation prompt** (attached `plan-generation-prompt.md`) with this pet's report findings to fill the copy fields (`intro`, each `how_it_helps`, prose `body`/`tip`). Use the model/settings from platform settings (§6).
3. **Validate** the returned JSON against the scaffold: product name, price, dose, duration, quantity, URL and inclusion must match the stored scaffold exactly — regenerate or reject if the model altered any factual field (guardrail described in the attachment, §5).
4. Persist the generated plan against the report so it's stable and editable.
5. Preserve the existing "add/adjust individual products" capability if the current flow has it — a report can use a plan **and/or** individual product selections, per the existing behaviour. Don't remove existing functionality.

---

## 6. Settings to add

### Per-plan settings (when editing a plan)
- Name, key, trigger description, enabled/disabled, species availability.
- Ordered steps: add / remove / **reorder**; set step type (product / prose); set step title and stage label.
- Per product in a step: pick from existing products, set duration, quantity, dose (default text), inclusion (included / optional).
- Copy controls: an `intro` template/guidance and a `how_it_helps` fallback the generator can use.
- Subscription: enable/disable for this plan, **discount override** (defaults to platform value), and the subscription-provider mapping (e.g. Loop selling-plan id) — Plan D will need its own tier.
- Retest step: toggle on/off and set its timing label.

### Platform-level settings (global defaults)
- Default **subscription discount %** (20%) and global enable/disable of subscriptions.
- Currency and default **dose** text.
- Default retest handling (optional add-on vs bundled) and default retest timing.
- Plan availability defaults by species.
- Generation config: model, temperature, and the editable system/generation prompt text.
- Subscription provider configuration (credentials / mapping) if one is integrated.
- Branding tokens used by the rendered plan (so the HTML example's palette/fonts come from platform branding, not hardcoded).

---

## 7. Rendering

The rendered plan must match **`plan-example.html`** — subscribe panel up top (with the 20%-off pricing), "plan at a glance" phase strip, stepped product cards with Dose / Duration / Quantity / "How it will help {pet}", individual **buy** buttons, and **included in plan** / **optional add-on** tags. Rebuild it using the app's existing component system and design tokens — do not drop the raw HTML in as-is if the app has a component layer. Keep it responsive and accessible.

---

## 8. Open questions — surface these, don't silently decide

Implement a sensible default and flag each for the client:
- **"Probiotic" vs "Prebiotic":** the client wrote "Probiotic" for Plan A but the live product is **PetBiome Prebiotic** — assume Prebiotic, confirm.
- **Plan D ending:** the client's note says "then move to AMR" after retest, which looks like a typo for **Maintenance** — implemented as Maintenance, confirm.
- **Retest timing/bundling:** retest is at month 6 for A/B (mid Phase 2) and month 3 for D; treated as an **optional add-on**, not inside the subscription — confirm whether it should be bundled.
- **20% basis:** discount applied to the **active-phase monthly cost** (so monthly price varies by phase). Confirm this rather than a single blended plan price.

---

## 9. Deliverables

1. A short **implementation plan** (before the full build) listing touched files, migrations, and how you mapped the spec onto existing conventions.
2. The **rename/replace** of Product Catalog → Plans with migration/redirects.
3. The **Plans CRUD** area, seeded with all four plans.
4. The **plan selector** wired into report creation, with generation + validation.
5. The **rendered plan** matching the attached HTML, using existing components/tokens.
6. **Per-plan and platform settings** as specified.
7. A brief **summary of changes** and the **open questions** from §8, plus anywhere you deviated from this spec to honour existing conventions.
