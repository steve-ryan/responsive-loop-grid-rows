=== Responsive Loop Grid Rows ===
Contributors: Steve Wachira
Tags: elementor, elementor pro, loop grid, woocommerce, pagination
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Requires Plugins: elementor
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Set Elementor Pro Loop Grid rows per breakpoint. Items per page is calculated for you, with cache-safe server-side pagination.

== Description ==

Elementor Pro's Loop Grid widget lets you set responsive **Columns**, but only a single, non-responsive **Items Per Page** value. That means whenever your column count changes across desktop/tablet/mobile, your row count silently changes too - and you're stuck manually calculating "columns x rows" every time you tweak the layout.

**Responsive Loop Grid Rows** adds a **Responsive Rows** control to the Loop Grid widget:

* Desktop rows
* Tablet rows
* Mobile rows

It then automatically calculates the correct `posts_per_page` for each breakpoint (`columns x rows`) and applies it to the *real* WordPress/WooCommerce query - not a JavaScript hide/show trick. Pagination (Numbers, Prev/Next, Load More/AJAX) is recalculated correctly for every breakpoint: correct total pages, correct offsets, no duplicate or skipped products.

= Example =

With Desktop = 5 columns, Tablet = 3 columns, Mobile = 2 columns, and Responsive Rows set to 2/2/2:

* Desktop: 5 x 2 = **10** products per page
* Tablet: 3 x 2 = **6** products per page
* Mobile: 2 x 2 = **4** products per page

Change your columns later (say, Desktop to 4) and the item counts recalculate automatically - nothing to update by hand.

= Key features =

* Native Elementor responsive controls, added to the Loop Grid widget's own panel - no new widget, no template changes required.
* True server-side pagination: the actual `WP_Query`/WooCommerce query requests the right number of products; nothing is over-fetched and hidden with CSS/JS.
* Cache-safe by design (see "Caching strategy" below) - a shared page cache or CDN will never serve one visitor's device-specific product count to a different visitor.
* Works alongside your existing `Query ID` (e.g. a custom `sale_products` query built with `wc_get_product_ids_on_sale()`) without breaking it. Responsive Rows only ever touches `posts_per_page`; it never overwrites `orderby`, `order`, `meta_query`, `tax_query`, `post__in` or any other query argument.
* Multiple Loop Grids on the same page, each with independent Columns/Rows, work correctly and independently.
* Off by default per widget ("Enable Responsive Rows" switch) - existing sites are never changed unexpectedly just by installing/updating the plugin.
* No fatal errors if Elementor, Elementor Pro, or WooCommerce are missing/inactive - the plugin simply does nothing and shows an admin notice.

== Installation ==

1. In your WordPress admin, go to **Plugins -> Add New -> Upload Plugin**.
2. Choose `responsive-loop-grid-rows.zip` and click **Install Now**.
3. Click **Activate**.
4. (Optional) Go to **Settings -> Loop Grid Rows** to choose the fallback device used for cached/first-render page loads (default: Desktop). See "Caching strategy" below.

== Configuring your Loop Grid ==

1. Edit the page containing your Loop Grid with Elementor.
2. Select the Loop Grid widget and open its **Responsive Rows** section (new, added by this plugin, in the Content tab).
3. Toggle **Enable Responsive Rows** to Yes.
4. Set **Rows** for Desktop; switch to Tablet/Mobile preview in the top-left device switcher and set Rows for those breakpoints too (2/2/2 is a common starting point).
5. The "Calculated items per page" box beneath the control shows the resulting `posts_per_page` for each breakpoint, live-updating as you change Columns/Rows (see "Editor mode" below for the exact limits of this live preview).
6. You do **not** need to touch the widget's own "Items Per Page" field any more - it is ignored while Responsive Rows is enabled.
7. Save/update the page.

For the Loop Grid described in this plugin's design brief (5/3/2 columns, `sale_products` Query ID, Load More pagination), simply turn on Responsive Rows and set Rows to 2/2/2 to get 10/6/4 items per page - no other settings need to change.

== How the calculation works ==

For each breakpoint, `posts_per_page = columns x rows`, where:

* `columns` is read live from the widget's *own* Columns / Columns Tablet / Columns Mobile controls - never hard-coded. Change your columns later and the totals update automatically.
* `rows` is read from this plugin's Responsive Rows control, with the same "falls back to the next larger breakpoint if unset" behaviour Elementor itself uses for every responsive control (e.g. if Mobile Rows is left empty, it uses the Tablet value; if that's also empty, it uses Desktop).

== Pagination behaviour ==

Because the plugin only ever changes `posts_per_page` on the real query - via Elementor's `elementor/query/query_args` filter and, when a custom Query ID is set, its `elementor/query/{$query_id}` action, which fires with the real `WP_Query` object just before it runs - WordPress itself recomputes `found_posts`, `max_num_pages`, and all offsets correctly. This means:

* **Numbers / Prev-Next pagination**: total page count and each page's products are correct per breakpoint.
* **Load More**: each click requests the next batch at the size appropriate for the visitor's breakpoint (see "Caching strategy" for how the visitor's breakpoint is determined on Load More's own AJAX requests).
* **Elementor's AJAX pagination**: also goes through the same query hook, so it stays correct.
* No duplicate or skipped products when navigating between pages within a single breakpoint.

Note: if a visitor actively resizes their browser across a breakpoint *while already viewing page 2+*, "page 2" now represents a different offset for the new breakpoint (this is inherent to any columns/rows-based responsive pagination system, not specific to this plugin). The plugin does not automatically reload content on every resize/orientation-change event, to avoid surprising jumps during ordinary window resizing; a fresh page load or pagination click is always authoritative for whatever breakpoint is active at that moment.

== Caching strategy ==

This is the part most "responsive pagination" implementations get wrong, so here is exactly how this plugin avoids it:

**The problem:** if the very first HTTP request to a page is what decides how many products get baked into the HTML, then with any full-page cache or CDN, whichever device happened to request the page *first* determines what *every other visitor* sees until the cache expires - a mobile visitor could get a cached page meant for desktop, or vice versa.

**The solution, in three parts:**

1. Every normal, cacheable page request renders the Loop Grid using a single, site-wide **fallback device** (Settings -> Loop Grid Rows -> "Fallback / cached-page device", Desktop by default). This output is byte-for-byte identical regardless of who requests it or what device they're on, so it is completely safe for page caches and CDNs to store and serve to everyone.
2. A small front-end script detects the visitor's *real* breakpoint using Elementor's own configured breakpoint values (via `matchMedia`, never user-agent sniffing). If it matches the fallback device, nothing else happens - zero extra requests.
3. If it *doesn't* match, the script makes one lightweight `admin-ajax.php` call that re-renders just that one Loop Grid widget (via Elementor's own single-element render API) for the visitor's real breakpoint, and swaps the result in. `admin-ajax.php` requests are never cached by page-cache plugins or CDNs by default, so this step always runs fresh, per visitor, regardless of any caching layer in front of the page itself.

In short: the cached HTML is always generic and safe; the corrected, device-specific content is always fetched out-of-band. Visitors on the configured fallback device (typically the majority, if you leave it on Desktop) get the correct content immediately, with no extra request at all.

A short-lived, non-sensitive cookie (`rlg_device`, 24 hours) records the visitor's device, but only when it differs from the fallback device. Elementor's own native pagination (Numbers / Load More, which this plugin does not intercept) requests page 2 onward as an ordinary request, and the server reads this cookie **only on those paginated requests** (URLs carrying Elementor's `e-page-` argument) so that page 2+ uses the same items-per-page as the corrected page 1 - otherwise products would be skipped or repeated. A plain page load never reads the cookie, so the cacheable page render can never vary per visitor. When a paginated response does depend on the cookie, the plugin sends no-cache headers (and sets `DONOTCACHEPAGE`) so a page cache or CDN cannot store it and serve it to someone else.

The AJAX endpoint deliberately uses no nonce: it is public and read-only (it returns markup already visible on the page), and a nonce baked into a cached page would expire long before the cached copy does, silently disabling the correction. It only ever renders published, publicly viewable content (or content the current user can edit), and only for Loop Grids that have Responsive Rows enabled.

If you'd rather trade perfect per-visitor accuracy for zero extra requests, set **AJAX correction** to "Off" in the settings page - every visitor will then see the fallback device's item count.

== WooCommerce / sale products compatibility ==

Responsive Rows is completely independent of your product query. If your Loop Grid uses a custom `Query ID` (for example `sale_products`, filtered elsewhere in your theme/functions.php or another plugin using `wc_get_product_ids_on_sale()` via the `elementor/query/sale_products` action), this plugin's own callback on that same action runs alongside yours and only ever calls `$query->set( 'posts_per_page', ... )`. It never touches `post__in`, `meta_query`, `tax_query`, `orderby`, `order`, or anything else your existing code sets. Sorting (Date, Title, Price, Modified, Menu Order, Random) is entirely unaffected.

== Filters compatibility ==

Category/tag/attribute/brand filters, price filters, search, and WooCommerce tax/meta queries all continue to work, for the same reason as above: this plugin is additive and only ever sets `posts_per_page`. The one thing to be aware of: if a third-party filtering plugin (e.g. a AJAX product filter) renders its own copy of the Loop Grid outside of Elementor's own query/render pipeline entirely, it will not pick up Responsive Rows, since there is no supported way to hook into rendering that never goes through Elementor's APIs. In practice this affects a small minority of "headless" filter integrations; most WooCommerce/Elementor filter plugins (including FacetWP-style integrations that respect Elementor's Query ID mechanism) work normally.

== Editor mode ==

The Elementor editor always renders the live preview canvas using the Desktop configuration, regardless of which responsive preview mode (Desktop/Tablet/Mobile) you have selected in the panel. Elementor's device switcher changes CSS/layout preview, not which server-side query result is loaded, and simulating a true separate query per device inside the editor's single preview iframe is not practical without much deeper integration. To verify your Tablet/Mobile numbers, use the **Calculated items per page** box under the Responsive Rows control - it live-updates as you change Columns/Rows (best-effort; see the code comments in `assets/js/editor.js` for the exact mechanism and its limits). The real, correct per-breakpoint behaviour applies on the actual front end.

== Debugging ==

Add this to `wp-config.php` to enable debug logging:

`define( 'RLG_DEBUG', true );`

When enabled: safe, non-sensitive data (widget ID, resolved breakpoint, columns, rows, calculated posts-per-page, query ID) is written to your PHP error log, and - only for logged-in users who can `manage_options` - as an HTML comment near the end of the page source. Nothing is logged or shown to regular visitors, and no personal/request data is ever included.

== Known limitations ==

* The Elementor editor's live preview canvas always simulates the Desktop breakpoint (see "Editor mode" above).
* A visitor who resizes their browser across a breakpoint while already deep in pagination will see the new breakpoint's item count apply starting from a fresh page load or pagination click, not instantly mid-scroll (see "Pagination behaviour" above).
* Filtering plugins that bypass Elementor's own query/render pipeline entirely are not affected by Responsive Rows (see "Filters compatibility").
* Loop Grids whose Query source is **Current Query** (archive/category templates) always use the fallback device's item count. Their query depends on the page's main query, which cannot be reproduced inside `admin-ajax.php`, so they are intentionally not corrected (showing the wrong products would be worse than showing the fallback count).
* The device model is Desktop / Tablet / Mobile. If you enable Elementor's additional breakpoints (Laptop, Tablet Extra, Mobile Extra, Widescreen), Responsive Rows only distinguishes the Mobile and Tablet breakpoints; the other breakpoints use the nearest of those three.
* This plugin relies on `Elementor\Core\Base\Document::render_element()`, a documented-in-practice but not formally versioned Elementor Pro/Core method used internally for single-widget AJAX re-rendering. The plugin checks for its existence at runtime and fails gracefully (falling back to the site-wide fallback device for all visitors, with a debug log entry) if a future Elementor update removes or renames it.

== How to disable the feature ==

Per widget: open the Loop Grid, go to **Responsive Rows**, and switch **Enable Responsive Rows** to No. The widget immediately reverts to native Elementor behaviour (manual Items Per Page).

Site-wide: deactivate the plugin from **Plugins -> Installed Plugins**. Every Loop Grid reverts to native behaviour; no content or settings are lost, since your Responsive Rows values remain stored in the page/template content and will be used again if you reactivate.

== How to uninstall ==

Deleting the plugin (Plugins -> Installed Plugins -> Delete, after deactivating) removes only its two settings options (`rlg_default_device`, `rlg_ajax_correction_mode`). It never deletes or modifies any Elementor page/template content - your Loop Grid's Columns/Rows/Responsive-Rows values live inside Elementor's own post content, exactly like any other Elementor control, and are left completely untouched.

== Frequently Asked Questions ==

= Does this replace or redesign my Loop Item template? =

No. The plugin only adds a new control section to the existing Loop Grid widget and changes how `posts_per_page` is calculated. Your Loop Item template is untouched.

= Will this break my existing sites when I install/update the plugin? =

No. Responsive Rows is off by default on every Loop Grid until you explicitly enable it on that widget.

= Does this work with the Elementor "Posts" widget or Portfolio widget, not just Loop Grid? =

This version targets the Loop Grid widget specifically (widget name `loop-grid`), matching the brief this plugin was built against. The calculation logic in `RLG\Responsive_Query` is generic and could be pointed at other Query-Control-based widgets in a future version.

== Privacy ==

The plugin sets one functional cookie, `rlg_device` (value: `mobile`, `tablet` or `desktop`; 24 hours), solely so that paginated Loop Grid requests return the right number of items for the visitor's screen size. It contains no personal data and is not used for tracking. Suggested wording is added to Settings -> Privacy -> Policy Guide. No data is sent to any third party.

== Changelog ==

= 1.0.2 =
* Fix: the AJAX correction returned an empty response because `Document::render_element()` returns its markup instead of printing it; the returned value is now used (printed output is kept only as a fallback).
* Fix: the per-page nonce was embedded in cached pages and expired after 12-24 hours, silently disabling the correction on cached sites. The endpoint is public and read-only, so the nonce has been removed; access is enforced with capability/visibility checks instead.
* Fix: the `rlg_device` cookie was written by the script but never read by PHP, so Load More / Numbers pages 2+ used the fallback item count on tablet/mobile. It is now honoured on paginated requests only, with no-cache headers, and only for grids that are also corrected on page 1.
* Fix: the row count is now applied through Elementor's query-args filter as well as the per-Query-ID action, so Responsive Rows no longer depends on a custom Query ID being set, and several grids sharing one Query ID no longer override each other.
* Fix: access check on the AJAX endpoint failed open if a document method was missing; it now fails closed (published/public, published Elementor template, or `edit_post`; password-protected content refused).
* Fix: grids using "Current Query" are no longer swapped via AJAX (the page context cannot be reproduced there); the singular post context is now restored for grids inside Theme Builder templates.
* Fix: the correction script no longer runs inside the Elementor editor/preview, and re-initialises Elementor handlers correctly (jQuery-wrapped, including nested widgets) after swapping markup.
* Standards: front-end data is passed with `wp_add_inline_script()` instead of `wp_localize_script()`; removed needless `flush_rewrite_rules()` calls; removed double escaping of Settings API titles and Elementor render attributes; environment checks no longer translate strings before `init`; boot moved to `plugins_loaded` priority 20; added `Requires Plugins`, Elementor Pro version check, Settings link on the Plugins screen, privacy policy content, and `get_sites()` in `uninstall.php`; license header uses the SPDX identifier.

= 1.0.1 =
* Fix: fatal `TypeError` in `Elementor\Controls_Stack::sanitize_settings()` that could occur on every admin page load. It was caused by the Responsive Rows helper text control reading `get_settings_for_display()` during Elementor's *control registration* phase, before any widget settings exist (this path is hit whenever Elementor Pro builds its internal blank widget instance for editor localisation, not just when actually editing a Loop Grid). The "Calculated items per page" box is now built as a static placeholder at registration time and filled in entirely client-side by the existing editor.js live-update logic once a real widget instance is open in the panel - no functional change to what you see once editing an actual Loop Grid.

= 1.0.0 =
* Initial release.
