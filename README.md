# Sitecraft Accessibility

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)

A WordPress accessibility plugin that audits content against WCAG 2.1 AA and
WCAG 2.2, fixes a specific set of markup problems on the server, and generates a
publishable accessibility statement.

It is part of the [Sitecraft suite](../../README.md), five plugins built to one
engineering contract.

---

## Why this is not an overlay

This is the most important thing to understand about the plugin, so it goes first.

An accessibility overlay is a JavaScript bundle that promises conformance by
rewriting a page in the browser. The promise does not hold up:

- The European Commission does not treat overlay-based approaches as a route to
  EN 301 549 conformance. Conformance is a property of the delivered content.
- In April 2025 the US Federal Trade Commission approved a final order against
  accessiBe concerning its accessibility claims.
- Screen-reader users report, consistently and publicly, that overlays interfere
  with the assistive technology they already run. An overlay that fights a user's
  own software has made the site less usable, not more.

So this plugin does not paint over the page. It does three defensible things:

| | What it does | Why it is defensible |
|---|---|---|
| **Audit** | Parses content with `DOMDocument` and reports failures with the WCAG criterion cited | It tells a human what to fix rather than claiming to have fixed it |
| **Remediate** | Emits real markup and CSS server-side: skip link, landmarks, `lang`, focus ring, reduced motion, target size | The HTML the browser receives is genuinely better |
| **Document** | Generates the accessibility statement the EAA expects, including known limitations | Honesty about limitations is a defensible legal position; an unsupported claim is not |

There *is* an optional reading preference panel. It is **off by default**, it is
described to visitors as a convenience, and it carries a footnote saying so. It is
not a conformance mechanism and the plugin never presents it as one.

**Automated testing finds a minority of WCAG failures.** No scanner knows whether
your alt text is accurate or whether a keyboard user can finish your checkout. A
clean report means the rules found nothing, not that the site is accessible.

---

## Features

### Audit engine

Fifteen rules, each a class implementing a `Rule` interface with `id()`,
`title()`, `criterion()`, `level()`, `severity()`, `how_to_fix()` and
`evaluate( DOMXPath $xpath, DOMDocument $doc ): array`.

| Rule id | Criterion | Catches |
|---|---|---|
| `img-alt-missing` | 1.1.1 A | `<img>` with no `alt` attribute at all |
| `img-alt-filename` | 1.1.1 A | Alt text that is only a filename, e.g. `IMG_2043.jpg` |
| `link-empty` | 2.4.4 A | `<a>` with no accessible name |
| `link-generic-text` | 2.4.4 A | "click here", "read more", "more", "link" |
| `link-raw-url` | 2.4.4 A | Link text that is a bare URL |
| `heading-order-skip` | 1.3.1 A | Heading level jumping by more than one |
| `heading-empty` | 1.3.1 A | Heading element with no text content |
| `table-no-header` | 1.3.1 A | Data table with no `<th>` and no `scope` |
| `table-layout` | 1.3.1 A | Multi-column table with no headers and no presentation role |
| `iframe-no-title` | 4.1.2 A | `<iframe>` without a `title` |
| `input-no-label` | 3.3.2 A | Control with no label, `aria-label` or `aria-labelledby` |
| `tabindex-positive` | 2.4.3 A | `tabindex` greater than zero |
| `duplicate-id` | 4.1.1 A | Repeated `id` attributes in one document |
| `media-autoplay` | 1.4.2 A | `<video>` / `<audio>` with `autoplay` and no `muted` |
| `color-contrast-inline` | 1.4.3 AA | Inline `color` / `background-color` pairs below threshold |

The contrast rule implements the WCAG maths rather than approximating it:
each channel is linearised with `c <= 0.03928 ? c / 12.92 : pow( ( c + 0.055 ) / 1.055, 2.4 )`,
relative luminance is `0.2126R + 0.7152G + 0.0722B`, and the ratio is
`(L1 + 0.05) / (L2 + 0.05)`, checked against 4.5:1 for normal text and 3:1 for
large text (24px, or 18.66px bold).

### Server-side remediation

Independently toggleable, each detecting before it acts:

- **Skip link** injected at `wp_body_open`, visually hidden until focused, with a
  configurable target. Not emitted when the target is absent from the page.
- **Landmark roles** added only where the theme supplies neither the native
  element nor an existing role.
- **Focus visibility** restored via `:focus-visible`, with configurable colour,
  width and offset.
- **Document language** enforced on `<html>`, with an optional per-post override
  for mixed-language sites.
- **Reduced motion** honoured through a `prefers-reduced-motion` block.
- **Target size** (WCAG 2.2 SC 2.5.8) as opt-in CSS, off by default because it
  disturbs dense layouts.
- **Image alt handling** that records images rendered without alt and applies
  `alt=""` to attachments a human marked decorative. It never invents alt text.

### Editor, panel and statement

- A block editor sidebar that posts the serialised post content to
  `sitecraft/v1/a11y/analyse` and renders what the PHP rule engine returns, so the
  editor and the audit report can never disagree about the same post. Written
  against the `wp.*` globals with no build step, and debounced so typing does not
  produce a request per keystroke.
- An optional, off-by-default reading preference panel: real `<button>` with
  `aria-expanded` / `aria-controls`, a non-modal `role="dialog"` region, `Esc` to
  close, native controls throughout, preferences in one `localStorage` key inside
  a `try/catch`, and all styling driven from `data-sc-a11y-*` attributes on
  `<html>` rather than per-element inline styles.
- An accessibility statement generator with a one-click "create page" action and
  the `[sitecraft_accessibility_statement]` shortcode.
- A findings browser built on `WP_List_Table`, so it inherits the sortable
  headers, screen options, bulk actions and accessible table markup core
  maintains: severity and rule filters, search, per-user rows-per-page, a per-row
  "Edit post" link and the offending markup in a native `<details>` disclosure.

---

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/sitecraft-suite/sitecraft-accessibility.git
wp plugin activate sitecraft-accessibility
```

Or upload the ZIP through **Plugins → Add New → Upload Plugin**. There is no build
step and no runtime Composer dependency: the plugin ships without `vendor/`.

Requirements: **PHP 8.1+**, **WordPress 6.5+**. On an unsupported host the plugin
deactivates itself with an explanatory notice rather than fatal-erroring.

---

## Architecture

The suite shares one small set of abstractions. Read them once and all five
plugins are legible.

```
sitecraft-accessibility.php     Header, constants, host guard, bootstrap
uninstall.php                   Honours the "keep data" setting
src/
├── Core/                       Shared across the suite, identical in all five plugins
│   ├── Autoloader.php          PSR-4 mapping, no Composer required
│   ├── Container.php           Lazy service locator
│   ├── Module.php              Feature contract: id() + register()
│   ├── Plugin.php              Owns the container, boots modules
│   ├── Admin/Notices.php       Notices that survive a redirect
│   ├── Admin/Screen.php        Capability + nonce guard rails, shared chrome
│   ├── Settings/Registry.php   Schema: defaults, sanitizing, metadata
│   ├── Settings/Renderer.php   Escaped form rendering, one file to review
│   └── Support/{Logger,Migrations}.php
├── Admin/                      DashboardScreen, IssuesScreen, IssuesTable,
│                               FindingPresenter, SettingsScreen
├── Modules/                    AdminMenu, Scanner, Remediation, Editor,
│   │                           PreferencePanel, Statement
│   ├── Scanner/                Analyzer, BatchRunner, Repository, Contrast,
│   │   └── Rules/              Document, Issue, RuleRegistry — one class per rule
│   └── Remediation/            One class per fix, plus the shared Stylesheet
├── Rest/Routes.php             Three routes under `sitecraft/v1`, each with a
│                               real permission callback
├── Support/                    Installer, Settings factory, field groups
└── Cli/                        Commands and Reporter, guarded by
                                `defined( 'WP_CLI' ) && WP_CLI`
```

**Plugin → Container → Module.** `Plugin` decides *what* runs; it never decides
how a feature behaves. Modules bind themselves to hooks in `register()` and are
individually filterable, so any feature can be switched off without a fork:

```php
add_filter( 'sitecraft_a11y_module_enabled', function ( bool $enabled, string $id ) {
	return 'preference-panel' === $id ? false : $enabled;
}, 10, 2 );
```

**Settings are data.** Every field is declared once in `src/Support/Fields/*.php`
and the `Registry` derives the defaults, the type-aware sanitizer and the renderer
metadata from that declaration. Adding a setting is one array entry, not edits
across three layers.

```php
array(
	'id'          => 'focus_outline_width',
	'section'     => 'remediation',
	'type'        => 'number',
	'label'       => __( 'Focus ring width (px)', 'sitecraft-accessibility' ),
	'description' => __( 'WCAG 2.2 SC 2.4.13 treats a 2px perimeter as the minimum…', 'sitecraft-accessibility' ),
	'default'     => 3,
	'min'         => 1,
	'max'         => 8,
	'depends_on'  => array( 'focus_visible_enabled' => true ),
),
```

**Schema changes go through migrations, not the activation hook**, because
activation hooks do not fire on plugin update. `Support\Installer` owns every
piece of DDL, and `Core\Support\Migrations` replays numbered steps on admin
requests until the stored version matches the target.

### Storage

`{$wpdb->prefix}sitecraft_a11y_issues`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` | Primary key |
| `object_id` | `bigint(20) unsigned` | Indexed |
| `object_type` | `varchar(32)` | `post`, or a future object domain |
| `rule_id` | `varchar(64)` | Indexed |
| `severity` | `varchar(16)` | Indexed: critical / serious / moderate / minor |
| `criterion` | `varchar(16)` | e.g. `1.1.1` |
| `level` | `varchar(4)` | `A` / `AA` / `AAA` |
| `context` | `text` | Offending markup, truncated to the configured length |
| `selector_hint` | `varchar(255)` | Where in the document to look |
| `detected_at` | `datetime` | Indexed; no zero-date default, for MySQL strict mode |

Auditing is chunked. A cron batch processes N posts and stores a cursor in
`sitecraft_a11y_scan_state`, so a full pass over a very large site spans many runs
and never attempts a single unbounded query.

---

## WP-CLI

```bash
# Audit content, resuming from the stored cursor
wp sitecraft-a11y scan --post-type=post,page --limit=500

# Start a fresh pass from the beginning
wp sitecraft-a11y scan --reset

# Report findings in any format WP-CLI supports
wp sitecraft-a11y report --format=table --severity=critical
wp sitecraft-a11y report --format=csv > findings.csv

# Empty the findings table
wp sitecraft-a11y clear
```

| Command | Options | Purpose |
|---|---|---|
| `scan` | `--post-type=<types>`, `--limit=<n>`, `--reset` | Runs batches synchronously instead of waiting for cron. Shares the cron cursor, so an interrupted run resumes rather than restarts |
| `report` | `--format=table\|json\|csv\|yaml\|count`, `--severity=<level>`, `--rule=<id>`, `--post=<id>`, `--search=<text>`, `--limit=<n>`, `--fields=<fields>` | Reads stored findings, rendered through `WP_CLI\Utils\format_items`. Results are streamed in chunks so `--limit=0` on a large backlog does not load the table into memory |
| `clear` | `--yes` | Deletes every recorded finding and rewinds the cursor |

An unregistered post type, an unknown severity, rule, field or format stops the
command with an error listing the valid values, rather than quietly returning
nothing.

## REST API

All routes live under `sitecraft/v1` and every one has a permission callback.

| Method | Route | Capability | Purpose |
|---|---|---|---|
| `GET` | `/sitecraft/v1/a11y/summary` | `edit_posts` | Finding counts by severity |
| `POST` | `/sitecraft/v1/a11y/scan` | `manage_options` | Queue an audit |
| `POST` | `/sitecraft/v1/a11y/analyse` | `edit_posts` | Analyse a `content` string; used by the editor sidebar |

```bash
curl --user admin:app-password \
     https://example.com/wp-json/sitecraft/v1/a11y/summary
```

## Hooks

### Filters

| Filter | Arguments | Purpose |
|---|---|---|
| `sitecraft_a11y_module_enabled` | `bool $enabled`, `string $id` | Switch an individual module off |
| `sitecraft_a11y_capability` | `string $capability` | Capability guarding screens and write paths |
| `sitecraft_a11y_rule_choices` | `array $rules` | Register or relabel rules in the admin and the sanitizer |
| `sitecraft_a11y_rules` | `Rule[] $rules`, `Registry $settings` | Add a rule instance to the engine; it then obeys the level filter and the per-site opt-out like a built-in |
| `sitecraft_a11y_generic_link_phrases` | `string[] $phrases` | Lowercase phrases `link-generic-text` treats as uninformative; replace them on a non-English site |
| `sitecraft_a11y_post_content` | `string $content`, `WP_Post $post` | Markup audited for a post, for output your templates add outside `the_content` |
| `sitecraft_a11y_max_issues_per_object` | `int $cap` | Findings recorded for one document before the analyser stops (default 200) |
| `sitecraft_a11y_scan_schedule` | `string $schedule` | Cron recurrence for the batched audit |
| `sitecraft_a11y_fixes` | `Fix[] $fixes`, `Registry $settings` | Add or remove server-side remediations |
| `sitecraft_a11y_fix_enabled` | `bool $enabled`, `string $id` | Suppress one remediation on one request without turning the feature off site-wide |
| `sitecraft_a11y_remediation_css` | `string $css`, `array $blocks` | The inline remediation stylesheet before it is printed |
| `sitecraft_a11y_buffer_output` | `bool $enabled` | Whether the landmark fix buffers this response |
| `sitecraft_a11y_panel_active` | `bool $active` | Whether the preference panel renders on this request |
| `sitecraft_a11y_issues_per_page` | `int $per_page` | Rows on the findings screen |
| `sitecraft_a11y_uninstall_keep_data` | `bool $keep` | Final say over data removal on uninstall |

### Actions

| Action | Arguments | Fires |
|---|---|---|
| `sitecraft_a11y_registered` | `Plugin $plugin` | Once every module has bound its hooks |
| `sitecraft_a11y_settings_registry` | `Registry $registry` | While the schema is being built; append your own sections and fields |
| `sitecraft_a11y_settings_after_section` | `string $tab` | After a settings tab is rendered, outside the settings form, for actions that belong beside a setting but are not one |
| `sitecraft_a11y_post_scanned` | `int $post_id`, `Issue[] $issues` | After one object has been audited and its findings stored |

### Scheduled events

| Hook | Cadence | Work |
|---|---|---|
| `sitecraft_a11y_scan_batch` | Configurable: hourly, twice daily, daily or weekly | Audits one batch and advances the cursor; re-queues itself while the pass is unfinished |
| `sitecraft_a11y_prune_issues` | Daily, while a retention window is set | Deletes findings older than the configured number of days |

```php
add_action( 'sitecraft_a11y_settings_registry', function ( $registry ) {
	$registry->add_field( array(
		'id'      => 'my_custom_toggle',
		'section' => 'advanced',
		'type'    => 'toggle',
		'label'   => __( 'My integration', 'my-plugin' ),
		'default' => false,
	) );
} );
```

### Stored metadata

| Key | Object | Written by |
|---|---|---|
| `_sitecraft_a11y_lang` | Post | Per-post language override |
| `_sitecraft_a11y_decorative` | Attachment | Decorative-image flag |
| `_sitecraft_a11y_issue_count` | Post | Cached finding count |
| `_sitecraft_a11y_scanned_at` | Post | Timestamp of the last audit |

All four are removed on uninstall unless "keep data" is enabled.

---

## Development

```bash
composer install          # dev tooling only; the plugin never loads vendor/
composer run lint         # php -l over every file
composer run phpcs        # WordPress Coding Standards
composer run phpcbf       # fix what can be fixed automatically
```

On Windows, the syntax check is:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

### Standards this code is held to

- `declare( strict_types=1 );` and an `ABSPATH` guard in every file.
- Every write path checks a nonce **and** a capability. A nonce is not
  authorisation.
- Every SQL statement with a variable goes through `$wpdb->prepare()`. Table names
  are only ever `$wpdb->prefix` plus a literal, never user input.
- Every superglobal read is `wp_unslash()`-ed and then sanitized, in that order.
- Every echo is escaped at the point of output.
- The text domain is the literal string `sitecraft-accessibility`; the string
  extractor cannot resolve a constant.
- Files stay under roughly 500 lines. A class that outgrows it gets split.

### Contributing

Issues and pull requests are welcome. A change that adds a rule should include the
success criterion it maps to and the `how_to_fix()` text a site owner will act on.
A change that adds a remediation must degrade safely when the theme already does
the right thing.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
