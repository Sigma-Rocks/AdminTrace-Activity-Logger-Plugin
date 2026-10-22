# Testing notes

Environment: PHP 8.4.27, WordPress 7.1.3, site `http://wordpress.test`.

## Automated

From the plugin directory:

```bash
php tests/smoke.php
php tests/integration.php
find . -name '*.php' -exec php -l {} \;
```

`smoke.php` does not load WordPress. It checks IP masking, context redaction, the short text diff, CSV formula neutralization, webhook URL rules, settings sanitizing, and SQL order whitelisting.

`integration.php` loads this site’s `wp-load.php`. It creates or updates the `ah_events` table, inserts a sample event, records one failed login through the user logger (without firing the public `wp_login_failed` hook), renders the journal and settings screens as an administrator, then deletes the sample rows and restores settings.

### Latest run

- `php -l` on every plugin PHP file: no syntax errors.
- `tests/smoke.php`: all checks passed (IP mask, forwarded-header ignore, secret stripping, word diff, CSV formula quote, webhook URL rules, settings caps, order whitelist).
- `tests/integration.php`: all checks passed (table exists, insert and read-back, HTML stripped from the summary, password omitted, context IP masked, failed login stored without a password field, journal HTML contains the sample event and does not print raw markup, settings HTML renders).
- Activation on this site: succeeded. The journal received the activation notice and three notes from existing posts and pages. The daily retention task is scheduled. Sample rows from the integration test were removed.
- A second render of the live journal as an administrator included the timeline, filters, and activation event, with no PHP warnings in that output.

## Browser

Opening `http://wordpress.test/wp-admin/admin.php?page=advance-history` redirects to the WordPress login screen. The interactive admin session was not available without credentials. The journal and settings markup were rendered as an administrator in PHP instead, including the live activation events.

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
