# Inbound localized routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `veltix/wayfinder-locales` able to serve the URLs it generates, and make `hide_default_prefix` correct for a non-English default locale.

**Architecture:** `Route::localized()` currently registers one `{locale}`-parameterised URI plus, optionally, an unprefixed twin built from the declared literal. It will instead register **one route per configured locale**, each carrying that locale's translated segment and its locale as a route default, plus the unprefixed default-locale route. Matching then happens in Laravel's own matcher — no Request rebuilding, no pre-routing middleware — which keeps `route:cache` working and means `SetLocale` needs no change, since it already reads the locale from route defaults.

**Tech Stack:** PHP 8.4, Laravel 12/13, PHPUnit via orchestra/testbench, laravel/wayfinder (`dev-next`), laravel/ranger.

**Spec:** `/Users/kristenlooke/Herd/pood/docs/superpowers/specs/2026-08-28-localized-routing-design.md` (lives in the consuming app; this package is Part 1 of it)

## The two gaps, measured

Both confirmed by running the code, not by reading the README.

**Gap 1 — nothing matches a translated inbound URL.** `LocaleRouteResolver` is consumed only by `LocaleAwareRouteTransformer`, bound over Wayfinder's converter at generation time. `SetLocale` only reads the matched route's locale to call `app()->setLocale()`. So `products.url({locale:'de'})` emits `/de/produkte` and Laravel has no route that matches it.

**The existing suite cannot see this.** `tests/SetLocaleMiddlewareTest.php:38` asserts `$this->get('/de/products')` — the *untranslated* path — is OK. It never requests `/de/produkte`. The suite tests the URI Laravel matches rather than the URI the package emits, so the two have never been compared.

**Gap 3 — `lroute()` emits untranslated URLs.** Found during Task 1. `src/helpers.php:17-30` fills the locale *parameter* and delegates to `app('url')->route()`; it never consults `LocaleRouteResolver`. So `lroute('products', [], 'de')` returns `/de/products`, not `/de/produkte` — the server-side helper has the same blind spot as the router. Only the TypeScript generation path is locale-aware. This matters beyond tidiness: the consuming app's plan is to make its `LocalizedUrl` a façade over `lroute()`, which would have reproduced the very bug this work removes.

**Gap 2 — the unprefixed twin uses the declared URI literal.** Probe with `default_locale = 'et'`:

```
Route::get('/{locale?}/products', ...)->localized(['en' => 'products', 'et' => 'tooted']);

probe.products.default   uri=products              defaults={"locale":"et"}
probe.products           uri={locale?}/products    defaults=[]
```

Estonian-as-default serves at `/products`, not `/tooted`. `WayfinderLocalesServiceProvider.php:107-118` strips the locale placeholder and registers the remainder, never consulting the translation map.

## Global Constraints

- **The round-trip property is this plan's whole point** and every task serves it: for every localized route and every configured locale, the URL the generator emits must be matched by the router, land on the same action, and set `app()->getLocale()` to that locale. A change that does not preserve it is wrong however well it reads.
- **Do not add a pre-routing middleware that rebuilds the Request.** That is what the consuming app does today and what this work replaces. Matching belongs in Laravel's matcher so `route:cache` keeps working.
- Run the suite with `vendor/bin/phpunit`. It is green at **32 tests, 85 assertions** as of `v2.3.0` with dependencies updated — that is the baseline; it may grow, never regress.
- `laravel/wayfinder` is pinned to `dev-next`, a moving branch. If the suite errors with `Target class [Laravel\Wayfinder\Converters\...] does not exist`, the local vendor is stale: `composer update laravel/wayfinder laravel/ranger -W`. That is not a defect.
- `vendor/bin/pint` before committing. PHP classes `final` where they already are; explicit return and parameter types throughout.
- **This package is published.** Public API changes are breaking changes for consumers. `localized()`'s signature and `SetLocale`'s behaviour must stay source-compatible; the new capability is additive.
- Do not change `composer.json`'s constraints beyond what a task explicitly requires.
- Semantic versioning: these changes are a **minor** release (additive plus a bug fix), not a major.

---

### Task 1: A failing test that proves Gap 1

**Files:**
- Create: `tests/InboundRoutingTest.php`

**Interfaces:**
- Consumes: `Route::localized()`, the `setlocale` middleware alias, `tests/TestCase.php`'s testbench harness.

This task writes **no implementation**. It exists so the defect is characterised before it is fixed, and so the fix has something to turn green.

- [ ] **Step 1: Write the round-trip test**

Register a localized route in the testbench app, then assert both halves:

```php
Route::middleware('setlocale')->get('/{locale?}/products', fn () => app()->getLocale())
    ->name('products')
    ->localized(['en' => 'products', 'de' => 'produkte']);
```

Assert that `/de/produkte` returns 200 and echoes `de`. Follow `tests/SetLocaleMiddlewareTest.php`'s shape for route registration and assertion style — it already does this for the untranslated path.

Add a second test asserting the same property from the *generator's* side: take the URL the package emits for `de` and request exactly that string, rather than a hand-written path. That is the assertion the suite has never had, and it is the one that cannot drift.

- [ ] **Step 2: Run it and record the failure**

Run: `vendor/bin/phpunit --filter InboundRoutingTest`
Expected: FAIL — `/de/produkte` 404s, because the only registered URIs are `{locale?}/products` and `products`.

**Paste the actual failure output into your report.** It is the evidence that the rest of this plan is necessary.

- [ ] **Step 3: Commit the failing test**

Commit it failing, marked incomplete, so the next task's diff shows it turning green:

```bash
vendor/bin/pint
git add tests/InboundRoutingTest.php
git commit -m "test: characterise the missing inbound match for translated URLs"
```

If committing a red test conflicts with repo convention, mark them skipped with the reason and unskip in Task 2 — but say which you did.

---

### Task 2: Register one route per locale

**Files:**
- Modify: `src/WayfinderLocalesServiceProvider.php` (the `localized()` macro)
- Test: `tests/InboundRoutingTest.php` (unskip / turn green), `tests/RegistrationTest.php` (extend)

**Interfaces:**
- Produces: for each configured locale, a registered route whose URI carries that locale's segment and whose `locale` default is that locale.

**The approach.** Rather than one `{locale}`-parameterised URI, `localized()` registers one concrete route per locale — `/de/produkte`, `/en/products` — each pointing at the same action with `locale` set as a route default. Matching becomes native, `route:cache` keeps working, and `SetLocale` needs no change because it already reads `$route->defaults[$parameter]`.

**Naming.** Locale routes need distinct names or the router's name lookup collides. Follow the existing `.default` convention — the twin is already named `{name}.default`. Choose a scheme, state it in your report, and make sure `route('products')` still resolves to something sensible for a caller who does not care about locale.

**Keep the parameterised route** if removing it breaks generation — `LocaleRouteResolver` reads `$route->uri()` and detects the locale placeholder. Check what the resolver needs before deleting anything, and say what you found.

- [ ] **Step 1: Make Task 1's test pass**

Run: `vendor/bin/phpunit --filter InboundRoutingTest`
Expected: PASS.

- [ ] **Step 2: Make `lroute()` locale-aware**

`src/helpers.php`'s `lroute()` fills the locale parameter and calls `app('url')->route()`, so it emits `/de/products`. Once a route exists per locale, `lroute()` should resolve to **that locale's** route and return its translated URL.

Add a test asserting `lroute('products', [], 'de')` returns the same string as the resolver's `uriForLocale('de')` — comparing the two generators against each other rather than against a hand-written path, so they cannot drift apart later.

Keep its documented fallback intact: a route with **no** locale parameter (Fortify's `login`, say) must still generate unchanged. That behaviour is in the function's docblock and consumers rely on it.

- [ ] **Step 3: Keep the whole suite green**

Run: `vendor/bin/phpunit`
Expected: at least 32 tests, 0 failures. `LocalizedRouteEmitTest` and `RegistrationTest` both inspect registered routes and are the most likely to notice a changed route table — if either needs updating, that is a signal about the public contract, so explain the change rather than just making it pass.

- [ ] **Step 4: Prove it discriminates**

Revert the per-locale registration, confirm `InboundRoutingTest` fails again, restore. Report the observed output.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint
git add src tests
git commit -m "feat: match translated URLs by registering a route per locale"
```

---

### Task 3: The unprefixed twin uses the default locale's segment

**Files:**
- Modify: `src/WayfinderLocalesServiceProvider.php`
- Test: `tests/DefaultLocaleTest.php` (extend)

Today the twin is the declared URI literal minus the locale placeholder, so `default_locale => 'et'` with `['en' => 'products', 'et' => 'tooted']` serves Estonian at `/products`.

- [ ] **Step 1: Write the failing test**

In `tests/DefaultLocaleTest.php`, with `default_locale` set to a locale whose segment **differs** from the declared literal, assert the unprefixed route's URI is the *default locale's* segment. Then assert the round trip: requesting `/tooted` returns 200 with locale `et`, and `/products` — the old literal — does **not** resolve to it.

That second assertion matters. Without it the test passes if the twin is registered under both names, which would leave a duplicate-content URL live.

- [ ] **Step 2: Run it, implement, verify**

Run: `vendor/bin/phpunit --filter DefaultLocaleTest`

The twin's URI comes from `$translations[$defaultLocale]` rather than the stripped literal. Preserve the existing behaviour when they happen to be equal — that is the common English-default case and it must not move.

- [ ] **Step 3: Prove it discriminates, then commit**

```bash
vendor/bin/pint
git add src tests
git commit -m "fix: build the unprefixed twin from the default locale's segment"
```

---

### Task 4: `default_locale` accepts a resolver

**Files:**
- Modify: `src/WayfinderLocalesServiceProvider.php`, and wherever else `config('wayfinder-locales.default_locale')` is read
- Modify: `config/wayfinder-locales.php` (document the callable form)
- Test: `tests/DefaultLocaleTest.php` (extend)

The consuming app needs the default locale to come from its own storage — a shop setting — resolved at route-registration time. The package should not know or care where it comes from.

- [ ] **Step 1: Write the failing test**

Set `wayfinder-locales.default_locale` to a closure returning a locale, register a localized route, and assert the unprefixed twin is built for that locale. Add a second test: a closure that **throws** must not take routing down — the package falls back to the configured string, or to the first configured locale, and registration still completes.

That second test is the important one. Route registration happens at boot; if an app's resolver hits a database that is briefly unavailable, a throw would mean total routing failure rather than a slightly wrong prefix.

- [ ] **Step 2: Implement**

Resolve the value once per registration pass rather than per route — a closure hitting storage should not be called once per route. Say in your report how many times you call it for N routes, and how you verified that.

`grep` for every read of `default_locale` in `src/` and route them all through the same resolution, or the halves disagree.

- [ ] **Step 3: Verify, prove discrimination, commit**

```bash
vendor/bin/pint
git add src config tests
git commit -m "feat: allow default_locale to be resolved at runtime"
```

---

### Task 5: Migrate the suite to Pest 5

**Files:**
- Modify: `composer.json` (require-dev), `phpunit.xml.dist`
- Create: `tests/Pest.php`
- Modify: every file under `tests/`

**Interfaces:**
- Produces: a Pest suite the next task writes its matrix test into directly.

This lands **before** the matrix test so that test is authored in Pest natively rather than written in PHPUnit and migrated an hour later.

The suite is 46 tests across 10 files, all PHPUnit classes extending `Tests\TestCase` (a bare `Orchestra\Testbench\TestCase` registering `WayfinderLocalesServiceProvider`). `pestphp/pest` also ships `Pint/phpdoc_type_annotations_only`, so the ruleset adopted in the previous commit already matches where this is going.

- [ ] **Step 1: Install Pest and bind the base test case**

Add `pestphp/pest` (^5) and its Laravel plugin to `require-dev`. Create `tests/Pest.php` binding `Tests\TestCase` across the suite — that is what lets the migrated files drop their `extends` and their `getPackageProviders()` boilerplate.

Point `phpunit.xml.dist` at Pest, or replace it per Pest 5's convention. Check what `pestphp/pest`'s own repo does and follow it.

- [ ] **Step 2: Migrate file by file, running the suite after each**

Convert each `public function it_x(): void` into `it('x', function () { ... })`. Migrate one file, run the suite, commit; then the next. A single sweeping commit makes a bisect useless if one conversion changes a test's meaning.

**The count is the invariant: 46 tests before, 46 after.** Any file that comes out with fewer is a test silently dropped — Pest's `it()` at file scope will not error if a conversion loses a block. Report the per-file counts.

- [ ] **Step 3: Preserve what the classes carried**

Some files set config in `setUp()` or use helper traits (`tests/Concerns/WritesLangFiles.php`). Those become `beforeEach()` and plain function imports or `uses()`. Config set per test must stay per test — Pest's `beforeEach` runs before each test, but a `setUp()` that mutated shared state and relied on ordering will not survive unchanged, and this suite has tests that change `default_locale` mid-file.

- [ ] **Step 4: Verify equivalence, not just green**

Run the full suite. Then pick the two tests that pin the load-bearing properties — one from `InboundRoutingTest`, one from `DefaultLocaleTest` — break the source they cover, and confirm the migrated tests still fail. A migration that turns a discriminating test into a passing no-op is the failure mode here, and only that check finds it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint
git add composer.json phpunit.xml.dist tests
git commit -m "test: migrate the suite to Pest 5"
```

---

### Task 6: Round-trip symmetry across the matrix, then release

**Files:**
- Create: `tests/RoundTripSymmetryTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: everything the previous four tasks built.

- [ ] **Step 1: Write the matrix test**

For a set of routes covering the shapes this package supports — required `{locale}`, optional `{locale?}`, a route with extra path segments after the localized one, a route with model-bound parameters, and `mode => 'tail'` as well as `'segment'` — assert for **every configured locale**: the generated URL is matched by the router, lands on the expected action, and sets `app()->getLocale()` to that locale. Assert it for **both** generators — the resolver's `uriForLocale()` (which the TypeScript output is built from) and `lroute()` (the server-side helper) — since Task 1 found they disagreed.

Drive it from the route table rather than a hand-written list of paths, so a future route shape is covered without anyone remembering to add it.

- [ ] **Step 2: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: green, comfortably above the 32-test baseline.

- [ ] **Step 3: Update the README**

The README currently shows `products.url({ locale: 'de' })` returning `/de/produkte` without stating that anything serves it — which was true, and was the gap. Document that localized routes are matched inbound, that one route is registered per locale, and that `default_locale` accepts a callable. Correct the "How it works" section's claim that Laravel serves *a single* `{locale}`-parameterised URI.

- [ ] **Step 4: Release**

Tag a **minor** version — additive capability plus a bug fix, no breaking API change — and push the tag. Report the version chosen.

```bash
vendor/bin/pint
git add README.md tests
git commit -m "test: pin round-trip symmetry across locales and route shapes"
```

---

## Self-Review

**Spec coverage.** Gap 1 (no inbound matching) → Tasks 1, 2, 5. Gap 2 (twin uses the declared literal) → Task 3. Gap 3 (`lroute()` untranslated), found while writing Task 1's characterisation test → Task 2, and pinned against the other generator in Task 6. `default_locale` as a resolver, which the consuming app needs for its shop setting → Task 4. The spec's acceptance property — round-trip symmetry — is Task 1's second test and Task 5's whole subject.

**Task 1 ships no implementation on purpose.** The defect has existed since the package's first release precisely because nothing compared what it generates against what it matches. Committing that comparison as a failing test first means the property is pinned by something that has been *observed* to fail, not merely asserted to pass.

**The riskiest task is 2**, because it changes the route table's shape, which is public contract. `LocalizedRouteEmitTest` and `RegistrationTest` both inspect registered routes; if either needs editing, that is information about the contract rather than a chore, and the plan says so.

**Placeholder scan.** No TBDs. Task 2's naming scheme and Task 5's route-shape matrix are described rather than dictated, because both depend on what `LocaleRouteResolver` needs from `$route->uri()` — which the implementer must read first. Both tasks say so explicitly rather than pretending the answer is known.

**Type consistency.** `localized(array $translations): Route` keeps its signature. `SetLocale` is untouched — it already reads `$route->defaults[$parameter]`, which is how per-locale routes carry their locale, and that is the reason this approach was chosen over path rewriting.

**One risk carried forward.** Registering one route per locale multiplies the route count by the number of configured locales. For the consuming app that is roughly 23 public routes × 2 locales plus twins — fine, but an app with many locales and many routes will notice, and `route:list` output changes for everyone. That belongs in the README's release notes.
