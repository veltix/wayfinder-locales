# Root routes and the model-bound shorthand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two open package gaps that force workarounds on consumers — issue #6 (the model-bound shorthand silently drops `locale`) and issue #5 (`localized()` cannot translate a root route).

**Architecture:** Both are fixed by extending what the package already overrides. `LocalizedRouteMethod extends RouteMethod` and post-processes `parent::url()` — `fillOptionalLocale()` and `stripUnusedParsedArgsForRouteWithNoRealParameters()` are existing examples of exactly this. `localized()` is our own macro, so root-route handling belongs there and in `LocaleRouteResolver`.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 5 + orchestra/testbench, laravel/wayfinder (`dev-next`).

## Why these two, now

A consuming application is mid-adoption and is carrying a workaround for each:

- **#6** makes `show.url({ slug, locale: 'et' })` type-check and return the **English** URL, because the shorthand branch rebuilds `args` and drops every sibling. The consumer must remember to write `{ page: slug, locale }` instead — a rule no type or test enforces.
- **#5** means a root route gets no per-locale twin, so the consumer hand-registers `Route::get('/et', ...)->name('home.locale.et')`, duplicating the package's own naming convention by hand. It also blocks **four files** from dropping the old runtime-string-transformation mechanism.

Both were found by real adoption, not by the package's own suite.

## Global Constraints

- The suite is green at **73 tests, 461 assertions**. Run it with `vendor/bin/pest` — **`vendor/bin/phpunit` does not work** post-Pest-5. That count is the floor.
- **`tests/TypeScriptCompileTest.php` runs the real `wayfinder:generate` and type-checks the output with `tsc`.** It needs `node_modules`; if absent, `bun install`. This test exists because the package shipped non-compiling TypeScript twice — do not skip it, and do not consider a change to the emitter done until it passes.
- `vendor/bin/pint` before committing. `pint.json` includes `Pint/phpdoc_type_annotations_only`, which strips **all** comment styles — reasoning goes in test names and reports.
- **Published package.** `localized(array $translations): Route` keeps its signature. Both changes are additive or bug fixes.
- Do not change `composer.json`'s `require`.
- If the suite errors with `Target class [Laravel\Wayfinder\Converters\...] does not exist`, the vendor is stale: `composer update laravel/wayfinder laravel/ranger -W`. Not a defect.

---

### Task 1: The model-bound shorthand must preserve siblings (#6)

**Files:**
- Modify: `src/Wayfinder/LocalizedRouteMethod.php`
- Test: `tests/AdoptionShapesTest.php` or a new file

Wayfinder core emits, for a route bound as `{page:slug}`:

```ts
if (typeof args === "object" && !Array.isArray(args) && "slug" in args) {
    args = { page: args.slug }
}
```

That rebuild drops every sibling property. Since this package adds `locale` to the args, the consequence is ours: `show.url({ slug, locale: 'et' })` loses the locale, then the next line defaults it to the default locale, and the call returns the wrong-language URL — type-legally and silently.

- [ ] **Step 1: Write the failing test first**

Register a route with a `{model:column}` binding, generate, and assert the emitted `url()` **preserves siblings** through the shorthand branch. Then assert the behaviour that matters: calling with `{ slug, locale }` yields the localized URL, not the default-locale one.

A string-shape assertion alone is not enough — this package has shipped emitted code that looked right and did not compile, and code that compiled and returned the wrong URL. Assert the resulting URL.

- [ ] **Step 2: Fix by post-processing**

`LocalizedRouteMethod::url()` already takes `parent::url()` and rewrites it. Add a step turning the rebuild into a spread that preserves siblings. Follow the existing post-processors' shape.

**Check the array-shorthand branch below it too** — it also rebuilds positionally. Decide whether it needs the same treatment and say why; a positional call has no sibling `locale` to preserve, so it may be correct as-is.

- [ ] **Step 3: Verify, prove discrimination, commit**

Run the full suite **including `TypeScriptCompileTest`**. Revert the post-processor and confirm your test fails on the returned URL, not merely on string shape.

```bash
vendor/bin/pint
git add src tests
git commit -m "fix: preserve sibling args through the model-bound shorthand"
```

---

### Task 2: `localized()` on a root route (#5)

**Files:**
- Modify: `src/WayfinderLocalesServiceProvider.php`, `src/Route/LocaleRouteResolver.php`
- Test: `tests/AdoptionShapesTest.php` or a new file

A route whose URI is `/` has no static segment to substitute, so `localized()` produces no per-locale twin and `/et` resolves to nothing. Consumers hand-register the twin, duplicating `{name}.locale.{locale}` by hand.

**The shape:** for a root route, the locale prefix *is* the localized path. `Route::get('/', ...)->localized(['en' => '', 'et' => ''])` — or whatever declaration form you judge cleanest — should yield `/` for the default locale under `hide_default_prefix`, and `/et` for Estonian.

**Decide the declaration form deliberately and say why.** An empty-string translation map reads oddly; a dedicated marker may be clearer. Whatever you choose must not make the common non-root case more verbose.

- [ ] **Step 1: Write the failing test first**

Assert both locales resolve, that `app()->getLocale()` is correct for each, that `lroute('home', [], 'et')` returns `/et`, and that the generated TypeScript compiles and returns `/et`.

- [ ] **Step 2: Implement, verify, prove discrimination**

Run the full suite including the widened `RoundTripSymmetryTest` — root routes are a new shape and the matrix should cover them, which is the whole reason that matrix exists.

- [ ] **Step 3: Add the root shape to the symmetry matrix**

Six of this package's bugs lived in shapes the matrix did not declare. Add this one so the next change cannot regress it silently.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint
git add src tests
git commit -m "feat: allow localized() on a root route"
```

---

### Task 3: Document and release

- [ ] **Step 1: README**

Document root-route support and note that sibling args survive the model-bound shorthand. Add the SSR caveat from issue #7 while you are here — `setUrlDefaults()` is safe on the client and unsafe under Inertia SSR, because `urlDefaults` is a module-level global and the SSR server has no per-request isolation. That is currently an open issue with no documentation, and it is the kind of trap a consumer finds the expensive way.

- [ ] **Step 2: Release**

A **minor** version. **Merge to `main` first, then tag, then push both** — verify with `git merge-base --is-ancestor <tag> main` and report the result. An earlier release tagged a feature branch and left `main` behind.

Close issues #5 and #6 with the released version. Leave #7 open unless the README note fully resolves it — say which you judged.

---

## Self-Review

**Spec coverage.** #6 → Task 1. #5 → Task 2, plus the matrix entry that stops it regressing. #7 → documented in Task 3, not fixed, because a request-scoped default is a design question rather than a patch.

**Both fixes extend what the package already overrides**, rather than reaching further into Wayfinder. `LocalizedRouteMethod` post-processing is an established pattern in that file, and `localized()` is our own macro.

**Placeholder scan.** No TBDs. Task 2's declaration form is deliberately left to the implementer, because the cleanest spelling depends on how `LocaleRouteResolver` detects segments — and this package has been bitten repeatedly by decisions made from expectation rather than from reading the code.

**One risk.** Task 1 changes emitted TypeScript for every `{model:column}` route, including consumers not passing `locale` at all. `TypeScriptCompileTest` is the guard; it must pass, and the discrimination proof must assert the returned URL rather than the emitted string.
