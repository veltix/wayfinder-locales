# Adoption fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the three v2.4.0 bugs that only surfaced under real adoption, and stop requiring `{locale}` in the declared URI — which is what forces every consuming route to gain a leading parameter.

**Architecture:** v2.4.0 matches translated URLs by registering a concrete route per locale. Three bugs come from building those twins by copying the parent's action wholesale; the fourth item is a design correction — the parameterised route is no longer needed for *matching*, only for the resolver to find a placeholder, so requiring it in the URI is now an artificial constraint.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 5 + orchestra/testbench, laravel/wayfinder (`dev-next`).

**Spec:** `/Users/kristenlooke/Herd/pood/docs/superpowers/specs/2026-08-28-localized-routing-design.md`

## What real adoption found

Adoption was attempted in the consuming app and **reverted**. Its route declarations produced 64 test failures and required patching `vendor/` in place. Those patches, with the original diagnostic comments, are the input to this plan:

**`/Users/kristenlooke/Herd/pood/../../private/tmp/claude-501/-Users-kristenlooke-Herd-pood/f06e164f-32ec-4776-91a9-c86fc5051dfc/scratchpad/vendor-patches.diff`** — read it first. If that path is unreachable, the same three fixes are described below in enough detail to reconstruct.

**Why the existing suite missed all three:** `tests/RoundTripSymmetryTest.php` claims to cover "extra path segments" and "model-bound parameters", but its routes are declared flat and ungrouped. Every one of these bugs needs a route declared inside `Route::prefix()`/`group()` nesting, or with a `{param:column}` binding hint. The matrix is the right idea exercised on the wrong shapes.

## Global Constraints

- Run the suite with `vendor/bin/pest`. It is green at **48 tests, 189 assertions**. That is the floor; it may grow, never regress. `vendor/bin/phpunit` no longer works post-Pest-5.
- `vendor/bin/pint` before committing. `pint.json` includes `Pint/phpdoc_type_annotations_only` — **explanatory prose in docblocks gets stripped**, so where a fix needs a "why", put it in a normal `//` comment inside the method body, which that rule leaves alone. The vendor patches did exactly this and their comments are worth keeping.
- **This package is published.** `localized(array $translations): Route` keeps its signature. Task 4 makes a currently-throwing case work, which is additive.
- If the suite errors with `Target class [Laravel\Wayfinder\Converters\...] does not exist`, the vendor is stale — `composer update laravel/wayfinder laravel/ranger -W`. Not a defect.
- Do not change `require` constraints.

---

### Task 1: Reproduce all three bugs with realistic route shapes

**Files:**
- Create: `tests/AdoptionShapesTest.php`

**This task writes no implementation.** It characterises the three bugs first, so each fix has something observed-to-fail to turn green — the same discipline that made this package's original inbound gap undeniable.

Declare routes in the shapes real applications use, which the existing suite does not:

- a route inside `Route::prefix('cart')->group(...)` with `->localized([...])`
- a route with a model-bound parameter carrying a column hint, `/{page:slug}`
- both, with `hide_default_prefix` on and off

- [ ] **Step 1: Write one failing test per bug**

1. **Prefix duplication** — assert the twin's URI is not `cart/cart` or `{locale?}/cart`.
2. **Lost binding fields** — assert the twin's `bindingFields()` still carry `page => slug`; without it, binding silently falls back to the model's default route key, which is the kind of bug that resolves the wrong record rather than erroring.
3. **`wayfinder:generate` crash** — assert generation completes for a localized route. The concrete twin inherits the parent's translations map, so a later `resolveForRoute()` treats it as localized in its own right and throws, since its URI has no placeholder left.

- [ ] **Step 2: Run, record the verbatim failures, commit red**

Run: `vendor/bin/pest --filter AdoptionShapesTest`

**Paste the three failure messages into your report.** They are the evidence the rest of this plan rests on.

---

### Task 2: Build twins from a clean action

**Files:**
- Modify: `src/WayfinderLocalesServiceProvider.php`
- Test: `tests/AdoptionShapesTest.php` (turns green)

**There are two independent mechanisms, and the captured vendor patch only addresses one.** Task 1 established this by hand-simulating the patch inside an open `Route::prefix('cart')->group()` and watching the twin still come out wrong:

1. **The copied action.** `Route::__construct()` re-applies the parent's stale `'prefix'` key, and the inherited translations map makes a later `resolveForRoute()` on the concrete twin throw. Strip what belongs to the parent alone — its name, its resolved `prefix`, its translations map — and restore what `addRoute()` cannot infer, the binding fields. **This is what the vendor patch fixes.**

2. **The router's live group stack.** `Router::createRoute()` calls `$this->prefix($uri)` and applies the current group's name prefix, reading the router's *live* state — entirely independent of the action array. When `->localized()` is chained inline inside a still-open group closure, that state is still active, so a fully-resolved twin URI gets prefixed again. Result: `cart/cart/cart/items` (triple, not double) and a name of `shop.shop.items.default`, which is **unreachable under its intended name**.

Mechanism 2 is the one that matters for adoption, because the consuming app declares every shop route inside `Route::name('shop.')->group(...)`, so `->localized()` is always chained inline there. A misnamed twin also means `lroute()` cannot find it and silently falls back to the parent — emitting untranslated URLs, which is the very bug this package exists to fix.

Suspending and restoring the router's group stack around `localized()`'s `addRoute()` calls is the obvious candidate for mechanism 2, and may close both the URI and the name duplication at once. Task 1's report says whether it does.

Take the vendor patch's approach and reasoning for mechanism 1; write your own tests for both.

- [ ] **Step 1: Make Task 1's three tests pass**

- [ ] **Step 2: Keep the suite green and prove discrimination**

Run: `vendor/bin/pest` — 48 plus your new tests, no failures. Then revert each of the three changes independently and confirm the matching test fails alone. Report all three.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint
git add src tests
git commit -m "fix: build locale twins from a clean action"
```

---

### Task 3: Widen the symmetry matrix to the shapes that broke

**Files:**
- Modify: `tests/RoundTripSymmetryTest.php`

The matrix claimed to cover model-bound parameters and extra segments, and missed three bugs in both. Add the grouped/prefixed and binding-hint shapes to the matrix itself, so the property is asserted for them and not only in the regression tests from Task 1.

- [ ] **Step 1: Extend, run, commit**

Run: `vendor/bin/pest`. Report the new test and assertion counts.

---

### Task 4: Stop requiring `{locale}` in the declared URI

**Files:**
- Modify: `src/Route/LocaleRouteResolver.php`, `src/WayfinderLocalesServiceProvider.php`
- Test: `tests/AdoptionShapesTest.php` or a new file

**This is the change that makes adoption tractable.** `LocaleRouteResolver.php:70` throws unless the declared URI contains `{locale}` or `{locale?}`. That made sense when one parameterised route did the matching. It no longer does: v2.4.0 matches through **concrete per-locale twins**, and the placeholder now serves only to tell the resolver where to substitute.

The cost of keeping it is paid by every consumer. In the consuming app, adding `{locale?}` as the leading parameter broke **86 positional `route('shop.x', $model)` call sites** — Laravel maps positional arguments by position — plus 9 in production code. That is a large, purely mechanical migration forced on every adopter by a constraint the package no longer needs.

**The change:** `localized()` accepts a route whose URI has no locale placeholder. The locale prefix is prepended when building each twin, exactly as it already is for the unprefixed default. A declared `/product/{slug}` with `['en' => 'product', 'et' => 'toode']` should yield `/product/{slug}` for the default locale and `/et/toode/{slug}` for Estonian, with **no `{locale}` parameter on any route**.

Keep the placeholder form working — consumers use it today, and `SetLocaleMiddlewareTest` covers it.

- [ ] **Step 1: Write the failing test**

Declare a route with **no** placeholder, assert both locales resolve, that `app()->getLocale()` is right for each, that `lroute()` returns the translated URL, and — the assertion that matters for adopters — **that positional `route('name', $model)` still works**, because that is the breakage this removes.

- [ ] **Step 2: Implement, verify, prove discrimination**

Run the full suite. Both forms must work. Revert and confirm the new tests fail.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint
git add src tests
git commit -m "feat: allow localized() without a locale placeholder in the URI"
```

---

### Task 5: Make the emitted TypeScript compile, and prove it in the suite

**Files:**
- Modify: `src/Wayfinder/LocalizedRouteMethod.php`
- Modify: `composer.json` / add a JS toolchain for the test harness
- Create: a test that compiles generated output

Verification against a real `tsc` found the emitted TypeScript **does not compile** — 8 errors across 3 files, for both declaration forms. Two root causes:

1. **Missing `applyUrlDefaults` import** for a placeholder-free route with no other parameters.
2. **A TypeScript narrowing failure:** `args?.locale`, used to index the per-locale template table, still types as `"en"|"de"|undefined` after the `fillOptionalLocale()` guard. Confirmed by a 12-line isolated repro.

**Cause 2 also breaks the pre-existing `{locale?}` form** whenever `default_locale` is configured — the common case. This is not a regression from this branch; the package has been shipping non-compiling TypeScript.

**Why the suite never saw it:** every emit test asserts string content with `toContain` and never compiles the output. That is nominal coverage again — the third instance in this repair, after the flat-route matrix and the hand-traced emitter.

- [ ] **Step 1: Fix both causes, and write the isolated repro as a test first**

- [ ] **Step 2: Make the suite compile what it emits**

This is the structural fix and the point of the task. Reading generated TypeScript has failed three times here; only compiling it closes the loop. The mechanism is already proven — generate via testbench, then run `tsc --noEmit` over the output.

Add whatever dev tooling that needs. A PHP package taking a node dev-dependency is unusual, but this package's primary consumer-facing artifact **is** TypeScript, and nothing has ever type-checked it.

If the toolchain proves genuinely impractical here, say so with what you tried — and then the check has to live in the consuming app's gates instead, which is worse but honest.

- [ ] **Step 3: Verify both forms compile, then run the full suite**

---

### Task 6: Document and release

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Document both declaration forms**

The README shows only the `{locale}` form. Show both, and say plainly which to prefer: **the placeholder-free form**, since it leaves a consumer's existing `route()` calls untouched.

- [ ] **Step 2: Release**

A **minor** version — three bug fixes plus an additive capability, no breaking change. Tag it, **merge to `main` first**, then push the tag. Report the version.

The previous release tagged a feature branch and left `main` behind; do not repeat that.

---

## Self-Review

**Spec coverage.** The three adoption bugs → Tasks 1-2, with the matrix widened in Task 3 so they cannot recur silently. The `{locale}` requirement → Task 4. Release → Task 5.

**Task 4 is the one that matters most**, and it is not a bug fix — it is a design correction that only became visible because adoption was attempted and reverted. Keeping the requirement would impose an 86-call-site migration on every adopter to satisfy a constraint the package outgrew in its own previous release.

**Placeholder scan.** No TBDs. Task 2 points at the captured vendor patch rather than restating its diff, because that patch carries the original diagnostic comments and is more precise than a paraphrase.

**One risk.** Task 4 introduces a second declaration form, so every code path handling locale substitution must handle both. Task 3's widened matrix is the net: it asserts the round-trip property across shapes, and Task 4 must run it.
