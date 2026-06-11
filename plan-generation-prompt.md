# Plan generation prompt

Powers the "Plans" area (replaces Product Catalog). Each of the four plans is stored as a **scaffold** (fixed steps, products, prices, durations). When a report is generated, the app passes the **selected plan scaffold** + the **pet's report findings**, and the model returns the same structure with only the *copy* fields filled in. Everything factual (products, prices, dose, duration, URLs, inclusion) stays exactly as stored — the model never changes it.

---

## 1. System prompt

```
You are a microbiome plan writer for Biome4Pets, a canine and feline gut microbiome
testing service. Your job is to take a fixed plan scaffold and a pet's test findings,
and write the pet-specific copy that personalises the plan for that animal's owner.

You write ONLY the following fields:
  - intro                        (the "where to focus first" paragraph)
  - each product's how_it_helps  (why this product suits THIS pet)
  - each prose step's body and tip

Everything else in the scaffold — plan_name, step_title, stage_label, every product's
name, price, dose, duration, quantity, product_url, inclusion, and the subscription
block — is FIXED. Copy it through unchanged. Never add, remove, rename, reprice or
re-order products or steps. Never invent a product that is not in the scaffold.

Rules for the copy you write:
  - British English spelling (e.g. "fibre", "colonise", "faecal").
  - Warm, clear, plain language for a pet owner. Not clinical, not salesy.
  - Use the pet's name naturally; refer to the owner as "you".
  - Ground how_it_helps in the pet's actual findings passed in input. Reference the
    specific elevated/low taxa or scores this product addresses. If a product's role
    doesn't map to any finding, describe its general benefit for this pet instead.
  - Do NOT invent findings. Only use what is in the input.
  - 1–3 sentences per how_it_helps. 2–4 sentences per prose body.
  - This is gut-health support, NOT a diagnosis. Never state or imply a diagnosis,
    cure, or veterinary treatment. No guarantees of outcome.
  - tip is optional — include only when there is a genuinely useful, evidence-based
    note (e.g. diet steps). Otherwise return null.

Output: a single valid JSON object matching the scaffold's shape, with the copy fields
filled. No markdown, no code fences, no commentary before or after the JSON.
```

---

## 2. User message template

Inject the two variables, then send.

```
PET FINDINGS:
{{PET_FINDINGS_JSON}}

PLAN SCAFFOLD (fill the copy fields, return the whole object as JSON):
{{PLAN_SCAFFOLD_JSON}}
```

### `PET_FINDINGS_JSON` — what the app passes

```json
{
  "pet_name": "Zenia",
  "species": "dog",
  "owner_name": "Penny Leedal",
  "report_date": "28 April 2026",
  "elevated": [
    { "taxon": "Clostridia", "value": "52%", "note": "gastric symptoms, processed-food / medication linked" },
    { "taxon": "Erysipelotrichaceae", "note": "inflammation, allergies, metabolic" },
    { "taxon": "Collinsella", "note": "inflammation, leaky gut, stress" }
  ],
  "low": [],
  "scores": {
    "diversity_shannon": 3.1,
    "species_richness": 487,
    "dysbiosis_pattern": 6.4,
    "classification": "Imbalanced (Level 2)"
  }
}
```

---

## 3. The four plan scaffolds (your "Plans" records)

Store these as the four plans. `how_it_helps`, `intro`, prose `body`/`tip` are left empty for the model to fill; all other fields are the source of truth.

### Plan A — `restore-rebalance`  (trigger: AMR + Prebiotic)

```json
{
  "plan_id": "restore-rebalance",
  "plan_name": "Restore & Rebalance",
  "pet_name": "{{pet_name}}",
  "intro": "",
  "subscription": {
    "available": true,
    "price": "£35 / month",
    "billing_note": "Billed monthly · powders rotate by phase",
    "includes": ["PetBiome AMR", "PetBiome Prebiotic", "PetBiome Maintenance"]
  },
  "steps": [
    { "type": "product", "step_title": "Step 1: Microbiome Reset", "stage_label": "Phase 1 · Months 1–3",
      "products": [ { "name": "PetBiome AMR", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "3 months (12 weeks)", "quantity": "3 (one tub per month)", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-amr-1", "inclusion": "included" } ] },
    { "type": "prose", "step_title": "Step 2: Implement Dietary Changes", "stage_label": "Alongside Phase 1", "body": "", "tip": "" },
    { "type": "product", "step_title": "Step 3: Rebuild & Restore", "stage_label": "Phase 2 · Months 4–7",
      "products": [ { "name": "PetBiome Prebiotic", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "4 months", "quantity": "4 (one tub per month)", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-amr", "inclusion": "included" } ] },
    { "type": "product", "step_title": "Step 4: Retest the Gut Microbiome", "stage_label": "Checkpoint · Around month 6",
      "products": [ { "name": "PetBiome Gut Microbiome Test Kit", "price": "£180.00", "dose": "Single sample collection at home.", "duration": "One-off retest", "quantity": "1", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-microbiome-test-kit", "inclusion": "optional" } ] },
    { "type": "product", "step_title": "Step 5: Maintain Gut Microbiome Health", "stage_label": "Phase 3 · Ongoing",
      "products": [ { "name": "PetBiome Maintenance", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "Ongoing", "quantity": "1 per month (subscription)", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-maintenance-1", "inclusion": "included" } ] }
  ]
}
```

### Plan B — `reset-recover`  (trigger: AMR + Antimicrobic)
Same shape as Plan A, with Step 3 swapped:

```json
{ "type": "product", "step_title": "Step 3: Targeted Support", "stage_label": "Phase 2 · Months 4–7",
  "products": [ { "name": "Antimicrobic", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "4 months", "quantity": "4 (one tub per month)", "how_it_helps": "", "product_url": "https://biome4pets.com/products/antimicrobic", "inclusion": "included" } ] }
```
Subscription `includes`: `["PetBiome AMR", "Antimicrobic", "PetBiome Maintenance"]`, price `£35 / month`.

### Plan C — `maintain-protect`  (trigger: all-green result)

```json
{
  "plan_id": "maintain-protect",
  "plan_name": "Maintain & Protect",
  "pet_name": "{{pet_name}}",
  "intro": "",
  "subscription": { "available": true, "price": "£35 / month", "billing_note": "Billed monthly · ongoing", "includes": ["PetBiome Maintenance"] },
  "steps": [
    { "type": "product", "step_title": "Step 1: Maintain Gut Microbiome Health", "stage_label": "Ongoing",
      "products": [ { "name": "PetBiome Maintenance", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "Ongoing", "quantity": "1 per month (subscription)", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-maintenance-1", "inclusion": "included" } ] }
  ]
}
```

### Plan D — `rebuild-renew`  (trigger: FMT) — two products run together in Step 1

```json
{
  "plan_id": "rebuild-renew",
  "plan_name": "Rebuild & Renew",
  "pet_name": "{{pet_name}}",
  "intro": "",
  "subscription": { "available": true, "price": "£165 / month (first 3 months)", "billing_note": "AMR + Gut Renew taken together, then Maintenance — separate Loop tier", "includes": ["PetBiome AMR", "Gut Renew", "PetBiome Maintenance"] },
  "steps": [
    { "type": "product", "step_title": "Step 1: Intensive Reset", "stage_label": "Phase 1 · Months 1–3 · taken together",
      "products": [
        { "name": "PetBiome AMR", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "3 months", "quantity": "3", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-amr-1", "inclusion": "included" },
        { "name": "Gut Renew", "price": "£130.00 / month", "dose": "Follow recommended dose on label.", "duration": "3 months", "quantity": "3", "how_it_helps": "", "product_url": "https://biome4pets.com/products/gut-renew", "inclusion": "included" }
      ] },
    { "type": "product", "step_title": "Step 2: Retest the Gut Microbiome", "stage_label": "Checkpoint · Month 3",
      "products": [ { "name": "PetBiome Gut Microbiome Test Kit", "price": "£180.00", "dose": "Single sample collection at home.", "duration": "One-off retest", "quantity": "1", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-microbiome-test-kit", "inclusion": "optional" } ] },
    { "type": "product", "step_title": "Step 3: Maintain Gut Microbiome Health", "stage_label": "Phase 2 · Ongoing",
      "products": [ { "name": "PetBiome Maintenance", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "Ongoing", "quantity": "1 per month (subscription)", "how_it_helps": "", "product_url": "https://biome4pets.com/products/petbiome-maintenance-1", "inclusion": "included" } ] }
  ]
}
```

> Note: Plan D's "move to" after retest and its Loop pricing tier are still pending Holly's confirmation.

---

## 4. Example output (Plan A for Zenia)

```json
{
  "plan_id": "restore-rebalance",
  "plan_name": "Restore & Rebalance",
  "pet_name": "Zenia",
  "intro": "Zenia's results show elevated Clostridia at 52%, alongside raised Erysipelotrichaceae and Collinsella, and an overall imbalanced (Level 2) microbiome. This plan tackles those imbalances in order — reset first, then rebuild — before settling into long-term maintenance. Working through the steps in sequence will give Zenia the best chance of a stable, diverse gut.",
  "subscription": { "available": true, "price": "£35 / month", "billing_note": "Billed monthly · powders rotate by phase", "includes": ["PetBiome AMR", "PetBiome Prebiotic", "PetBiome Maintenance"] },
  "steps": [
    { "type": "product", "step_title": "Step 1: Microbiome Reset", "stage_label": "Phase 1 · Months 1–3",
      "products": [ { "name": "PetBiome AMR", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "3 months (12 weeks)", "quantity": "3 (one tub per month)", "how_it_helps": "Helps disrupt biofilms and reduce resistant organisms, bringing Zenia's elevated Clostridia back toward a healthier range and making room for beneficial bacteria to re-establish.", "product_url": "https://biome4pets.com/products/petbiome-amr-1", "inclusion": "included" } ] },
    { "type": "prose", "step_title": "Step 2: Implement Dietary Changes", "stage_label": "Alongside Phase 1",
      "body": "To create a gut environment less favourable to Clostridium, reduce or remove highly processed foods from Zenia's diet. If it suits her, add small amounts of raw or lightly cooked meat. Allow at least four weeks for any change to show in the microbiome, and avoid further diet changes during this window.",
      "tip": "A recent study found daily bone broth reduced Clostridium species in 95% of dogs over four weeks — home-cooked bone broth works just as well as shop-bought." },
    { "type": "product", "step_title": "Step 3: Rebuild & Restore", "stage_label": "Phase 2 · Months 4–7",
      "products": [ { "name": "PetBiome Prebiotic", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "4 months", "quantity": "4 (one tub per month)", "how_it_helps": "Feeds beneficial bacteria and supports the gut barrier, helping calm the inflammation linked to Zenia's raised Erysipelotrichaceae and Collinsella while improving her overall diversity.", "product_url": "https://biome4pets.com/products/petbiome-amr", "inclusion": "included" } ] },
    { "type": "product", "step_title": "Step 4: Retest the Gut Microbiome", "stage_label": "Checkpoint · Around month 6",
      "products": [ { "name": "PetBiome Gut Microbiome Test Kit", "price": "£180.00", "dose": "Single sample collection at home.", "duration": "One-off retest", "quantity": "1", "how_it_helps": "Retesting shows how Zenia's microbiome has responded so far and lets us fine-tune the powders before moving to maintenance.", "product_url": "https://biome4pets.com/products/petbiome-microbiome-test-kit", "inclusion": "optional" } ] },
    { "type": "product", "step_title": "Step 5: Maintain Gut Microbiome Health", "stage_label": "Phase 3 · Ongoing",
      "products": [ { "name": "PetBiome Maintenance", "price": "£35.00 / month", "dose": "Follow recommended dose on label.", "duration": "Ongoing", "quantity": "1 per month (subscription)", "how_it_helps": "A daily blend of prebiotics and beneficial bacteria to keep Zenia's microbiome stable, support diversity and protect the progress made in the earlier phases.", "product_url": "https://biome4pets.com/products/petbiome-maintenance-1", "inclusion": "included" } ] }
  ]
}
```

---

## 5. Wiring notes

- **Model:** `claude-sonnet-4-6` is plenty for this; set a low temperature (~0.4) for consistent copy.
- **Validation:** parse the returned JSON and assert the product `name`, `price`, `dose`, `duration`, `quantity`, `product_url` and `inclusion` match the stored scaffold exactly — reject/regenerate if they drifted. This is your guardrail against any factual edits.
- **Rendering:** the returned object maps 1:1 onto the plan template (step → step-head, product → card, prose → prose block, subscription → the subscribe panel).
- **Report flow:** on report generation, pick `plan_id` → load scaffold → inject with pet findings → generate → render.
