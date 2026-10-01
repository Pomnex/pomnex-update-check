=== Pomnex Update Check ===
Contributors: pomnex
Tags: updates, monitoring, maintenance, site health, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Checks your important pages after every update and emails you if something broke, so you know before your customers tell you.

== Description ==

**Updates are the most common reason WordPress sites break.** The worst breakages are the silent ones. The site still loads, but the checkout button is gone or the contact form stopped rendering, and you only find out when a customer tells you.

**Pomnex Update Check** watches the pages that matter to you. Pick up to 5 pages (checkout, contact, login) and tell it what must appear on each one, like "Place order". After every core, plugin or theme update, manual or automatic, it checks that:

* the page loads without errors
* the important element is still there
* no broken shortcodes or PHP warnings are showing to visitors
* stylesheets and scripts still load
* the page hasn't become much slower

If something fails, you get an email naming the page, what changed, and which update came right before it.

**Update log.** Every update is recorded: what changed, from which version to which, who ran it, when, and what the check found.

**Private by design.** Everything runs on your own site. No account, no external service, no data sent anywhere, including to us.

**What it does not do.** It doesn't fix problems, roll back updates or take backups. It doesn't take screenshots, so it won't catch small visual changes. It doesn't click buttons or test that orders and emails go through.

Built by [Pomnex](https://pomnex.com), a WordPress maintenance and custom plugin development team. Source code: https://github.com/Pomnex/pomnex-update-check

= How the check works =

1. For each important page, the plugin keeps a baseline: the last state in which the page worked (HTTP status, size, response time, whether the marker was found, the site's own stylesheets and scripts, and the shortcodes registered at the time).
2. When an update finishes, it records what changed and schedules a check about a minute later, when the update is done and the site is out of maintenance mode.
3. The check loads each page from the site itself, as a logged-out visitor, and compares it with the baseline.
4. The result is Pass, Warning, Fail or Could not check. On Fail you get an email. Everything is recorded in the update log.

WordPress (6.6 and later) rolls back a plugin auto-update only when it causes a PHP fatal error on the home page. Update Check also looks at your other important pages, manual updates, theme and core updates, and problems that are not fatal errors.

= License =

GPLv3 or later, with one additional term under GPLv3 section 7(b): the original author attribution ("Developed by Pomnex") must be preserved in the source code headers and on the plugin's About screen. See the LICENSE file.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `pomnex-update-check` folder to `/wp-content/plugins/`.
2. Activate it. Your home page is added as the first important page.
3. Go to Tools → Update Check → Pages and add up to 4 more pages, such as checkout, contact or login. For each page you can set a marker: text or HTML that must always be on the page.
4. Check the notification email address and save. A baseline is captured for each page.

That's all. After the next update, the Status tab and the Update log show the result.

== Frequently Asked Questions ==

= Does it send my data anywhere? =

No. The only requests it makes go to your own site, to load your own pages. There is no account, no external service, no tracking and no calls to pomnex.com. Results are stored in your site's database and emailed with your site's own mail setup.

= Why does it say "Could not check"? =

To check a page, the plugin asks your site to load its own page (a loopback request). Some hosts and security plugins block these requests. When that happens the result is "Could not check", never "Pass". Go to Tools → Site Health, which runs its own loopback test, to see whether that is the cause. Your host can usually allow loopback requests.

= Does it catch visual changes? =

No. It reads the HTML your server sends and does not take screenshots or run a browser, so it won't notice a button that changed colour or a layout that shifted a few pixels. It does notice when a marker disappears, a stylesheet or script fails to load or vanishes, a shortcode stops rendering, a PHP error shows up, or a page shrinks a lot. Content that JavaScript adds after the page loads is not visible to it either, so choose a marker that appears in the page source.

= Does it work with caching plugins? =

Yes. Each request adds a random query argument and sends no-cache headers, so page caches serve a fresh page. The request is made without cookies, so it sees what a logged-out visitor sees.

= What if WP-Cron is disabled? =

The check after an update runs through WP-Cron, about a minute after the update. If WP-Cron is disabled or slow and a check is more than 10 minutes late, administrators see a notice on the Dashboard, Plugins and Updates screens with a "Run check now" button. You can also run a check at any time from Tools → Update Check. If you run WP-Cron from a real server cron job, everything works as normal.

= Does it work on Multisite? =

Multisite is not specially supported in this version. On a multisite network it works per site: each site has its own pages, baselines, log and emails, with no network-wide settings.

= What do Pass, Warning, Fail and Could not check mean? =

* **Fail**: something is clearly broken: an error status, the WordPress critical-error page, a visible PHP fatal error, a missing marker, an unrendered shortcode, or a stylesheet or script that fails to load.
* **Warning**: something changed that may be harmless: a visible PHP warning or notice, a stylesheet or script that is no longer on the page, a much slower response, or a page that shrank by more than half.
* **Could not check**: the pages could not be loaded at all.
* **Pass**: nothing wrong found.

= Can I check pages that need a login, like My Account? =

The check sees pages as a logged-out visitor. For a login-protected page, it checks the page a visitor sees, such as the login form. Pick a marker that appears there.

= I changed a page on purpose and now get a warning. What do I do? =

On the Status tab, click "Save current state as baseline". Baselines also refresh by themselves after every check a page passes, and once a day.

== Screenshots ==

1. Status tab: last check result and per-page details.
2. Pages tab: up to 5 important pages with markers.
3. Update log: every update with old and new versions and the check result.
4. The email you get when a check fails.

== Changelog ==

= 0.1.0 =
* First release: important pages with markers, automatic check after every update, and an update log.

== Upgrade Notice ==

= 0.1.0 =
First release.
