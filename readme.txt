=== Sitecraft Accessibility ===
Contributors: sitecraft
Tags: accessibility, wcag, a11y, audit, eaa
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audits your content against WCAG 2.1 AA and WCAG 2.2, fixes real markup problems server-side, and generates your accessibility statement. Not an overlay.

== Description ==

Sitecraft Accessibility does three things, and refuses to pretend it does a fourth.

**1. It audits.** A rule engine parses your published content with DOMDocument and
reports what a person has to fix, citing the WCAG success criterion for each
finding. Fifteen rules ship in the box, covering missing alt text, unnamed links,
broken heading order, headerless data tables, untitled iframes, unlabelled form
controls, positive tabindex, duplicate IDs, unmuted autoplay and inline colour
contrast computed with the real WCAG relative-luminance formula.

**2. It remediates at source.** Skip link, landmark roles, a restored focus ring,
a correct `lang` attribute, reduced-motion support and WCAG 2.2 target sizing are
emitted as server-side markup and CSS. They change the HTML your visitors receive.
Each one detects what your theme already does and stays out of the way if the
theme has it covered.

**3. It documents.** The European Accessibility Act expects a published
accessibility statement with a working feedback route and an honest list of known
limitations. The plugin generates one from a form and publishes it through the
`[sitecraft_accessibility_statement]` shortcode.

= What it is not =

It is not an accessibility overlay. Overlays promise conformance from a JavaScript
snippet, and that promise does not survive contact with the standards bodies or the
regulators: the European Commission does not accept overlay-based approaches as a
route to EN 301 549 conformance, and in April 2025 the US Federal Trade Commission
approved a final order against accessiBe over its accessibility claims. People who
use screen readers report, consistently, that overlays interfere with the assistive
technology they already run.

There is an optional reading preference panel in this plugin. It is off by default,
it is described to visitors as a convenience, and it is never presented as a
conformance mechanism, because it is not one.

= Honest limits =

Automated testing detects a minority of WCAG failures - roughly a third, on the
most generous published estimates. No scanner can tell you whether your alt text is
*accurate*, whether your headings describe the content beneath them, or whether a
keyboard user can complete your checkout. A clean report from this plugin means the
automated rules found nothing, not that your site is accessible.

== Installation ==

1. Upload the `sitecraft-accessibility` directory to `/wp-content/plugins/`, or
   install the ZIP through Plugins > Add New > Upload Plugin.
2. Activate the plugin. Activation creates the findings table and schedules the
   batched audit.
3. Open **Sitecraft > Accessibility** and press **Audit the site now**.
4. Review **Sitecraft > A11y settings** before enabling remediation on a live
   theme, and read the trade-off note under each option.

No Composer install and no build step are required. The plugin ships without a
`vendor/` directory and runs on any host that meets the PHP and WordPress minimums.

== Frequently Asked Questions ==

= Will this make my site legally compliant? =

No plugin can do that, and any that says it can is selling you a risk. This one
finds machine-detectable barriers, fixes a specific set of markup problems at
source, and helps you publish an honest statement. The remaining work - meaningful
alt text, sensible heading structure, keyboard-operable custom widgets, accessible
PDFs - is human work.

= Why is the preference panel off by default? =

Because it is a reader convenience, not an accessibility fix, and because visitors
who need assistive technology already run their own. Turning it on does not change
your conformance position. If you enable it, leave the footnote in place so nobody
is misled about what it does.

= Will the server-side fixes break my theme? =

Each fix detects before it acts: landmark roles are only added where the theme has
neither the native element nor an existing role, and the skip link is not emitted
if its target is missing from the page. Target sizing is the one option that
routinely disturbs dense layouts, which is why it ships off. Enable remediation on
a staging copy first.

= How big a site can it audit? =

Any size. Auditing is chunked: a cron job processes a batch of posts per run and
stores a cursor, so a 50,000-post site completes over many runs rather than timing
out in one. Batch size and a per-batch time budget are both configurable.

= Does the preference panel set cookies or send data anywhere? =

No. Preferences are stored in the visitor's browser under a single `localStorage`
key, wrapped in try/catch because private browsing modes throw. Nothing is sent to
the server, so the panel needs no consent banner.

= What happens to my data if I delete the plugin? =

By default, everything the plugin created is removed: the findings table, its
options, its post and user meta, and its scheduled events. Turn on **Keep data when
the plugin is deleted** in Advanced settings if you would rather a reinstall picked
up where you left off.

== Changelog ==

= 1.0.0 =
* Initial release.
* Rule engine covering fifteen WCAG 2.1 / 2.2 success criteria, including inline
  colour contrast computed from relative luminance.
* Chunked WP-Cron auditing with a resumable cursor, configurable batch size and a
  per-batch time budget.
* Server-side remediation: skip link, landmark roles, focus visibility, document
  language, reduced motion, WCAG 2.2 target size and decorative-image alt handling.
* Block editor sidebar running the same rules against the post being edited.
* Optional, off-by-default reading preference panel built as a model accessible
  widget.
* Accessibility statement generator with a one-click page creation action.
* REST routes under `sitecraft/v1/a11y` and a `wp sitecraft-a11y` WP-CLI command.

== Upgrade Notice ==

= 1.0.0 =
First public release. Review the Remediation tab before enabling server-side fixes
on a production theme.
