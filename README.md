# AdminTrace Activity Logger

Activity journal for WordPress, by [Sigma Rocks](https://sigmarocks.com).

AdminTrace Activity Logger records who changed the site and when: content, accounts, plugins, themes, comments, settings, privacy requests, and WooCommerce orders when WooCommerce is active. Every feature in this plugin is available without a license.

## Install

1. Copy the `admintrace-activity-logger` folder into `wp-content/plugins/`, or upload `admintrace-activity-logger.zip` from Plugins → Add New.
2. Activate **AdminTrace Activity Logger**.
3. Open **AdminTrace Activity Logger** in the admin menu.

Activation creates a database table named `{prefix}srah_events` and notes up to ten existing posts and pages so the journal is not empty. Those rows are labeled as notes taken at activation, not as live edits.

Requires WordPress 6.2 or newer and PHP 7.4 or newer. Tested on WordPress 7.1.

## What is recorded

- Logins, logouts, failed logins, password resets, application passwords, profile changes, role changes, and deleted accounts.
- Posts, pages, custom post types, site-editor templates, terms, comments, and media. Content changes store field names and a short word summary, not the full post.
- Plugin and theme installs, updates, activation, switching, and deletion.
- WordPress core updates, language packs, and a notice when the available-update list changes.
- A curated set of site settings. Options whose names look like credentials are skipped even if a filter tries to add them.
- Navigation menus, sidebar widgets, privacy exports and erasures, and Tools → Export.
- WooCommerce order status, refunds, stock, and product price or SKU changes.
- Public hooks from Jetpack, User Switching, Advanced Custom Fields, Yoast Duplicate Post, Beaver Builder, and Redirection, when those plugins fire them.
- Denied admin screens, Site Health count changes, and plugin or theme file edits after WordPress has written the file.

Passwords, reset keys, application password secrets, API keys, and privacy-export download URLs are not stored. IP addresses are masked by default (the last IPv4 octet is removed; IPv6 keeps the first half) and can be turned off. AdminTrace Activity Logger does not send addresses to a geolocation service.

Logging is wrapped so a failure is dropped instead of interrupting WordPress.

## Settings

- Retention from 0 (keep until deleted) through 3650 days. A daily task deletes older rows in batches.
- Which recorders are on.
- Whether editors and shop managers can see content activity. They do not see security, account, plugin, or setting events, and they do not see network addresses. Shop managers can also see commerce events.
- Roles or user IDs whose routine info events are skipped. Notices, warnings, and security events are still stored.
- Dashboard widget, admin-bar list, menu location, and timeline or table view.
- Plain-text daily or weekly digest, a failed-login threshold email, and an optional HTTPS webhook.
- A private RSS feed that is off until you enable it. The address is a secret.
- Delete the journal on uninstall, on by default.

The webhook is the only place event data leaves the site, and only after an administrator saves an `https://` URL. Private and loopback addresses are rejected. Webhook payloads omit network addresses.

## Read the journal

The journal supports search, category, severity, source, account, and date filters, plus “hide my events”. Timeline and table views are both included. Each event can be opened for the stored field changes. CSV and JSON export follow the current filters and stop at 5,000 rows. Cells that start with `=`, `+`, `-`, or `@` are prefixed so a spreadsheet does not treat them as formulas.

Administrators can delete selected rows, run retention immediately, or delete the whole journal for the current site.

## Multisite

Each site has its own table, using that site’s table prefix. Network activation installs the table on every existing site and on sites created later. The network admin screen does not merge journals.

## Remove the plugin

Deactivating leaves the journal in place and clears the scheduled tasks.

Deleting the plugin from the Plugins screen runs `uninstall.php`. By default it drops the event table, removes settings, capabilities, and scheduled tasks. Uncheck **Delete the journal and settings when the plugin is uninstalled** first if the rows should be kept.

## Developers

```php
srah_log(
    array(
        'summary'     => __( 'Imported the nightly catalog.', 'my-plugin' ),
        'category'    => 'system',
        'action'      => 'import.catalog',
        'severity'    => 'info',
        'object_type' => 'import',
        'object_title'=> 'Nightly catalog',
        'context'     => array(
            'count' => 20,
        ),
    )
);
```

The function returns the event ID, or `0` when the event was not stored. Context keys that look like passwords, tokens, or API keys are removed. Do not put secrets in the summary.

Filters:

- `srah_should_log` — return false to skip an event.
- `srah_event` — adjust the event array before it is validated again.
- `srah_loggers` — add a class name that extends `SigmaRocks\AdvanceHistory\Loggers\Logger`.
- `srah_tracked_options` — add option names. The credential denylist still wins.
- `srah_skip_post_type` — return true to ignore a post type.
- `srah_logged` — fires after a row is stored.
- `srah_pruned` — fires after retention deletes rows.

Do not call `srah_log()` from `srah_should_log` or `srah_logged`. Those callbacks run while a write is already in progress, and the nested call is ignored.

REST routes, for users who can view or manage the journal:

- `GET /wp-json/admintrace-activity-logger/v1/events`
- `GET /wp-json/admintrace-activity-logger/v1/events/{id}`
- `POST /wp-json/admintrace-activity-logger/v1/events`
- `DELETE /wp-json/admintrace-activity-logger/v1/events/{id}`
- `GET /wp-json/admintrace-activity-logger/v1/stats`

POST always attributes the event to the authenticated user.

WP-CLI:

```bash
wp admintrace-activity-logger list --category=content --limit=20
wp admintrace-activity-logger prune --days=90
wp admintrace-activity-logger note "Noted the launch checklist."
```

A shell with no `--user` can read the journal because it already has database access. `wp --user=editor` is limited to that account’s capabilities.

## Privacy

Suggested policy text is added under Settings → Privacy. The personal-data exporter and eraser include or delete rows tied to that account, including failed logins that used the same username.

## Not included

These are left out on purpose:

- Hiding the plugin from the plugin list.
- IP geolocation or other calls to outside lookup services.
- Forwarding to syslog, Datadog, or Splunk. Use the webhook if you need a copy elsewhere.
- A monitor of every outgoing HTTP request or email, which would risk storing secrets.
- WordPress.org checksum scans. Core updates are recorded without an extra remote check.
- Item-level logs for WP Crontrol. The cron array is large and noisy.

Third-party hooks are best-effort. If a plugin renames a hook, that one event stops being recorded and the rest of the journal continues.

Historical sentences stay in the language that was active when they were written.

## Tests

From the plugin directory:

```bash
php tests/smoke.php
php tests/integration.php
```

`integration.php` uses the WordPress site in this project, writes sample rows, renders the admin screens, and deletes those rows. See `docs/TESTING.md` and `docs/FEATURE-COMPARISON.md`.
