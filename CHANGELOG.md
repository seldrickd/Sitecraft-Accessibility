# Changelog

All notable changes to Sitecraft Accessibility are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

## [1.0.0] - 2026-08-29

### Added

- **Audit engine.** Fifteen rules over `DOMDocument`, each citing its WCAG success
  criterion, conformance level, severity and remediation guidance:
  `img-alt-missing`, `img-alt-filename`, `link-empty`, `link-generic-text`,
  `link-raw-url`, `heading-order-skip`, `heading-empty`, `table-no-header`,
  `table-layout`, `iframe-no-title`, `input-no-label`, `tabindex-positive`,
  `duplicate-id`, `media-autoplay` and `color-contrast-inline`.
- **Real contrast maths.** Channel linearisation, relative luminance and the
  `(L1 + 0.05) / (L2 + 0.05)` ratio, checked against 4.5:1 for normal text and
  3:1 for large text, with both thresholds configurable for AAA work.
- **Chunked auditing.** A WP-Cron batch processes a configurable number of posts
  per run behind a resumable cursor and a per-batch time budget, so a very large
  site completes over many runs instead of timing out in one.
- **Server-side remediation**, each option independently toggleable and each
  detecting before it acts: skip link at `wp_body_open`, missing landmark roles,
  restored `:focus-visible` ring with configurable colour, width and offset,
  enforced document language with an optional per-post override,
  `prefers-reduced-motion` support, WCAG 2.2 target sizing, and decorative-image
  `alt=""` handling that records rather than invents alt text.
- **Block editor sidebar** that sends the post being edited to
  `sitecraft/v1/a11y/analyse` and lists what the PHP rule engine returns, so the
  editor and the audit report cannot drift apart. Written against the `wp.*`
  globals with no build step, and debounced so typing does not produce a request
  per keystroke.
- **Optional reading preference panel**, off by default: a real `<button>` with
  `aria-expanded` and `aria-controls`, a non-modal `role="dialog"` region, `Esc`
  to close, native controls throughout, preferences held in a single
  `localStorage` key inside a `try/catch`, and styling driven entirely from
  `data-sc-a11y-*` attributes on `<html>`.
- **Accessibility statement generator** with organisation, contact route,
  standard referenced, conformance claimed, assessment method and date, known
  limitations, feedback process and enforcement contact, published through the
  `[sitecraft_accessibility_statement]` shortcode and a one-click page action.
- **Admin screens** under the shared Sitecraft menu: a dashboard with headline
  figures and findings grouped by rule, a `WP_List_Table` findings browser with
  severity and rule filters, search, sortable headers, a per-user rows-per-page
  screen option, bulk deletion behind a nonce and a capability, a per-row
  "Edit post" link and the offending markup in a native `<details>` disclosure,
  and a five-tab settings screen.
- **REST routes** `GET /sitecraft/v1/a11y/summary`, `POST /sitecraft/v1/a11y/scan`
  and `POST /sitecraft/v1/a11y/analyse`, each behind its own permission callback.
- **WP-CLI**: `wp sitecraft-a11y scan`, `report` and `clear`, with `report`
  supporting `table`, `json`, `csv`, `yaml` and `count` through
  `WP_CLI\Utils\format_items`, and a `--fields` allowlist checked before the
  first row is read.
- **Data lifecycle.** Versioned migrations that run on update as well as
  activation, a configurable retention window enforced by a daily job, and an
  `uninstall.php` that honours a "keep data" preference and cleans multisite
  networks site by site.
- **Host guard.** On PHP below 8.1 or WordPress below 6.5 the plugin deactivates
  itself with an explanatory notice instead of fatal-erroring.
- **Translation catalogue** at `languages/sitecraft-accessibility.pot`, covering
  every PHP and JavaScript string, including plural forms and the `translators:`
  comments that give a translator the context a placeholder cannot.

### Security

- Every write path verifies a nonce and a capability; the capability is filterable
  through `sitecraft_a11y_capability` for multisite and custom-role installs.
- Every SQL statement carrying a variable is bound through `$wpdb->prepare()`;
  the only interpolated fragment is a table name built from `$wpdb->prefix`, and
  sort columns and directions come from fixed allowlists rather than request
  input. All of it lives in one repository class, so the claim is checkable.
- Every output is escaped at the point of echo. Settings field descriptions,
  which are the only strings carrying markup, pass through `wp_kses()` with an
  explicit tag allowlist.

[Unreleased]: https://github.com/sitecraft-suite/sitecraft-accessibility/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sitecraft-suite/sitecraft-accessibility/releases/tag/v1.0.0
