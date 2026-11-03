# Testing notes

Environment: PHP 8.4.27, WordPress 7.1.4, WooCommerce 11.2.1, MySQL on `127.0.0.1`, site `http://wordpress.test`.

## Tooling

`composer install` in the plugin directory installs PHPCS with WordPress Coding Standards and PHPCompatibilityWP, PHPStan with the WordPress extension, PHPUnit 9.6, the PHPUnit polyfills, and the WordPress test library (`wp-phpunit/wp-phpunit`). `vendor/` and the config files are left out of the release zip by `.distignore`. Keep `vendor/` out of a live site's plugin folder, because the file scan hashes every file there.

```bash
composer lint            # php -l on every PHP file
composer phpcs           # phpcs.xml.dist: WordPress, PHPCompatibilityWP 7.4+, minimum WordPress 6.2
composer phpstan         # phpstan.neon.dist: level 5, WordPress and WooCommerce stubs
composer test            # PHPUnit, single site
composer test:multisite  # PHPUnit, multisite
composer test:legacy     # smoke.php, integration.php, regression.php
```

### PHPUnit

The suite in `tests/phpunit` runs on the WordPress test library. It needs:

- A WordPress core checkout without a `wp-config.php`, given as `SRAH_TESTS_WP_CORE_DIR` (with a trailing slash). Default: `/tmp/wordpress/`.
- An empty database. Default: `srah_phpunit` as `root` with no password on `127.0.0.1`. Override with `SRAH_TESTS_DB_NAME`, `SRAH_TESTS_DB_USER`, `SRAH_TESTS_DB_PASSWORD`, and `SRAH_TESTS_DB_HOST`. **The test library drops and recreates every table in this database on each run. Never use a site's database.**
- Optionally WooCommerce. The bootstrap loads `../woocommerce/woocommerce.php` next to this plugin when it exists. Set `SRAH_TESTS_WC_FILE` to another path, or to `0` to run without it. Without WooCommerce, the four WooCommerce tests are skipped and say why.

```bash
mysql -uroot -e 'CREATE DATABASE srah_phpunit'
SRAH_TESTS_WP_CORE_DIR=/path/to/wordpress/ vendor/bin/phpunit
SRAH_TESTS_WP_CORE_DIR=/path/to/wordpress/ vendor/bin/phpunit -c phpunit-multisite.xml.dist
```

Each test runs in a database transaction that is rolled back. Mail goes to the test library's mock mailer, and webhook requests are caught by a `pre_http_request` filter, so nothing leaves the machine.

| File | Covers |
| --- | --- |
| `test-activation.php` | Table schema and indexes, activation tasks and capabilities, one-time backfill, deactivation, schema upgrades, legacy table rename guard, multisite table drop filter |
| `test-capabilities.php` | Administrator, editor, author, subscriber, editor view option, recursion guard, subsite administrators |
| `test-recorder.php` | Stored fields, sanitizing, secret removal, IP masking, disabled actions and loggers, excluded roles, repeat merging, filters and hooks, install mode, post create and update labels, unchanged featured image, failed login, stable order for events in the same second |
| `test-deletion.php` | `delete_ids`, journal delete form for administrators only, combined cards, nonce and capability checks, empty selection, Clear journal confirmation |
| `test-retention.php` | Pruning by age, keep forever, the scheduled hook, `srah_pruned`, daily schedule |
| `test-rest.php` | Routes, 401 and 403, totals headers, editor scope and hidden addresses, create, show, delete, other sites |
| `test-export.php` | JSON body, CSV formula neutralizing, editor scope, handler nonce and capability |
| `test-feed.php` | Token rules, feed URL, RSS output without addresses or markup |
| `test-notifications.php` | Digest off and on, recipient fallback, critical alerts, failed-login alerts, webhook payload and threshold, digest schedule kept when another section is saved |
| `test-privacy.php` | Exporter and eraser registration, export paging, erasing by user and by failed-login name, privacy policy text |
| `test-woocommerce.php` | Order status, refund, stock, price, shop manager access |
| `test-multisite.php` | New site table and schedule, separate journals, network events on the main site, table drop, network activation, super admin capabilities |

`test-multisite.php` and the subsite test in `test-capabilities.php` are in the `ms-required` group and run only with `phpunit-multisite.xml.dist`.

### Legacy scripts

From the plugin directory:

```bash
php tests/smoke.php
php tests/integration.php
php tests/regression.php
```

`regression.php` loads `wp-load.php` from the site this plugin sits in, or from the path in `SRAH_WP_LOAD`. It runs as temporary administrator and editor accounts, and covers: capability checks for non-administrators, scheduled retention, the webhook payload, export links, theme and plugin install records, featured-image changes, stats scopes, REST permissions and headers, every admin-post form, recorder edge cases, the privacy exporter and eraser, and, on multisite, site creation and deletion. It removes every row, post, and account it created and restores settings, including after a fatal error. With WooCommerce active it needs about 120 MB of PHP memory.

To run it against a multisite network, point it at that install:

```bash
SRAH_WP_LOAD=/path/to/multisite/wp-load.php php tests/regression.php
```

`smoke.php` does not load WordPress. It checks IP masking, context redaction, the short text diff, CSV formula neutralization, webhook URL rules, settings sanitizing, and SQL order whitelisting.

`integration.php` loads this site’s `wp-load.php`. It creates or updates the `srah_events` table, inserts a sample event, records one failed login through the user logger (without firing the public `wp_login_failed` hook), renders the journal and settings screens as an administrator, then deletes the sample rows and restores settings.

### Latest run (3 November 2026)

- `php -l` on every plugin PHP file: no syntax errors.
- PHPCS on the whole plugin: 0 errors, 0 warnings.
- PHPStan level 5: no errors.
- PHPUnit, single site: 72 tests, 263 assertions, all passed (WooCommerce 11.2.1 loaded).
- PHPUnit, multisite: 79 tests, 282 assertions, all passed.
- PHPUnit without WooCommerce: 72 tests, 4 skipped (the WooCommerce tests), the rest passed.
- `smoke.php`: 34 checks passed. `integration.php`: 19 passed on the single site and 19 on a multisite network. `regression.php`: 79 passed on the single site and 80 on the network.
- Journal delete form in a browser, on a throwaway multisite network as a super admin: Select all ticked every event. Delete selected showed “Delete the selected events? This cannot be undone.” Cancel sent nothing and the events stayed. OK deleted them and showed “Deleted 2 events.”
- Over HTTP on the same network: one checkbox carried both IDs of a combined card, and deleting it removed both rows. An editor was refused with 403 even with a valid nonce, and a request without a nonce was refused with 403.

## Staging checklist for external delivery

The automated tests never send real mail or HTTP requests. Check these on a staging copy with addresses and endpoints you own. Do not point them at real users or third-party services without their owner’s permission.

Before you start:

1. Use a staging site, not the live site. Make sure its user table has no real customer or staff email addresses, or that outgoing mail is caught (for example by Mailpit, MailHog, or an SMTP plugin in test mode).
2. Set **Settings → General → Administration Email Address**, or the plugin’s own alert and digest addresses, to a mailbox you control.

Email digest:

1. In AdminTrace **Settings**, set the digest to Daily and enter your test address. Save.
2. Click **Send test email**. One message should arrive. Its subject is `[Site name] Activity digest`, and its body lists recent events and a link to the journal.
3. Check that `srah_digest` appears in WP-Cron (for example `wp cron event list`). Run it once with `wp cron event run srah_digest` and confirm a second message.
4. Save the Display or Events screen. The next `srah_digest` time should not change.
5. Set the digest back to Off. The `srah_digest` event should disappear.

Critical and failed-login alerts (on by default):

1. Enter your test address as the alert address.
2. Add a journal note with severity Critical. One “Critical activity” email should arrive.
3. Fail a login more times than the alert threshold within 15 minutes. One “Repeated failed logins” email should arrive, and no password appears in the journal.
4. Turn the failed-login alert off and repeat the step above. No email should arrive.

Webhook:

1. Create a receiving endpoint you own on a public HTTPS address. The setting rejects `http`, `localhost`, `.local`, and private or reserved IP addresses, so a local listener cannot be used.
2. Enter it as the webhook URL with the minimum severity set to Warning. Save. If the field comes back empty, the URL was rejected.
3. Add an Info note. Nothing should be sent.
4. Add a Warning note. One JSON POST should arrive with `id`, `created`, `summary`, `category`, `action`, `severity`, `user_login`, `object`, and `site`. It must not contain an IP address.
5. Clear the URL and confirm nothing more is sent.

Clipboard copy:

1. Open the journal over HTTPS (or `localhost`) in current Chrome, Firefox, and Safari.
2. Click **Copy summary** on a card and paste it into a text field. The pasted text is the plain summary, and the button reads “Copied” for about two seconds.
3. Open the journal over plain HTTP on a non-local hostname, where the async Clipboard API is not available. Copy goes through the `execCommand('copy')` fallback. Where that is also blocked, the button label does not change and nothing is copied; this is expected, and the summary can still be selected by hand.
4. With a screen reader, note whether the “Copied” label change is read out. The plugin does not add a separate live-region announcement.

## Manual checks still worth doing on a staging site

- Deactivate and delete the plugin with the uninstall checkbox on and off.
- Log in and out, and try a wrong password.
- Edit a post, upload an image, and switch a theme.
- Open the journal as an editor with “Let editors and shop managers view content activity” on and off.
- Export CSV and open it in a spreadsheet.
- Confirm a logging failure (for example a full disk) does not block saving a post. The recorder catches throwables and returns 0.

## Known limits

- File edits are detected by comparing a hash before and after the request. The file contents are not stored.
- WooCommerce events depend on WooCommerce being loaded. Customer email addresses are not copied into the journal.
- Editors do not see security, account, plugin, or setting events.
- The private feed URL can appear in server access logs.
- Behind a reverse proxy, masked addresses stay on `REMOTE_ADDR` unless “trusted proxy” is enabled. That header can be spoofed if the proxy does not overwrite it.
- SQLite and unusual database drop-ins were not certified separately. Queries go through `$wpdb`.
- Wordfence, Kadence Security, Limit Login Attempts, and WP Crontrol do not have dedicated parsers. Core login hooks still record failed passwords.
- Historical sentences stay in the language that was active when they were written.
- This plugin has not been submitted to the WordPress Plugin Directory, so directory review is still ahead.
