=== AdminTrace Activity Logger ===
Contributors: sigmarocks
Tags: activity-log, audit-log, user-activity, security, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.paypal.com/paypalme/sigmarocks

WordPress activity log and audit trail. See who changed content, users, plugins, themes, settings, and WooCommerce orders.

== Description ==

AdminTrace Activity Logger is a WordPress activity log for sites that need a clear audit trail. It records who changed the site, what changed, and when, in short sentences instead of raw database dumps.

Activate it and recording starts at once. Search, filters, a timeline, a table, CSV and JSON export, email digests, and retention settings are included. There is no paid upgrade.

If you find an issue, or want another feature, please [open a support ticket](https://wordpress.org/support/plugin/admintrace-activity-logger/).

= Security and user activity =

* Logins, logouts, and failed logins. A failed login says whether the password was wrong or the username does not exist.
* Second-factor sign-in when the Two Factor plugin accepts a code, including the method name. Rejected second-factor checks are recorded without the code.
* Password resets, role changes, deleted accounts, and application passwords. The secret itself is never stored. Ending the other login sessions for an account is recorded.
* On a multisite network: sites created, deleted, or archived, and users added to or removed from a site.
* Denied admin screens, Site Health changes, and plugin or theme file edits after WordPress has written the file.
* Network addresses are masked by default. The plugin does not look up a city, a host, or a map.

= Content, media, and comments =

* Posts, pages, custom post types, and site-editor templates. An edit stores the field names and a short added-and-removed preview, not the full post.
* Media uploads, deletions, and image edits such as crop, rotate, flip, and scale. Enable Media Replace file replacements are recorded when that plugin is active. File contents are not stored.
* Categories, tags, comments, and the collaboration notes introduced in WordPress 6.9.
* Navigation menus, including the titles that were added or removed, and sidebar widgets.

= Plugins, themes, WordPress, and settings =

* Plugin install, update, activation, deactivation, deletion, and automatic-update toggles, with the plugin name and version.
* Theme install, update, switch, deletion, and automatic-update toggles.
* WordPress core updates, language packs, automatic updates, and a notice when the number of available updates changes.
* A curated set of settings, including the site title, permalinks, the homepage, comment rules, and the privacy policy page. Options that look like passwords or API keys are skipped.
* Yoast SEO and Rank Math on-page title, description, and social fields. Other private fields stay out.

= WooCommerce activity log =

When WooCommerce is active, the same journal records order status, coupon codes used on an order, refunds, stock, product price and SKU changes, and store settings such as currency. Customer email addresses are not stored.

= Other plugins =

These events are recorded when the other plugin is active and fires its public hook:

* Jetpack module activation and deactivation
* User Switching
* Advanced Custom Fields field groups
* Duplicate Post clones
* Beaver Builder layout saves
* Redirection saves and deletions

= Where to read the audit log =

* The activity journal, with search plus filters for category, severity, source, account, and date
* Timeline and table views
* A Dashboard widget and an admin-bar list
* On WordPress 6.9 and newer, the journal screens can be opened from the WordPress command palette, because core lists every admin menu page there. The plugin does not add commands of its own.
* A panel on the post editing screen for that post’s history
* A plain-text daily or weekly email digest, and an email when failed logins cross a threshold
* A private RSS feed that stays off until an administrator enables it
* CSV and JSON export of the current filters, up to 5,000 rows
* REST API and WP-CLI, including a way for other plugins to add events with `srah_log()`
* Separate screens for event ID switches, the file and database scan, and profile photos

= Files and database =

A scan can record files that were added, changed, or deleted. It covers PHP, JavaScript, CSS, and HTML files in plugins, themes, and must-use plugins, PHP drop-ins in wp-content, and wp-config.php, .htaccess, and .user.ini in the site root. Each plugin or theme is limited to 6,000 files and the whole scan to 20,000. Uploads are left out unless you turn them on under Files and database. Then PHP, JSON, XML, text, SVG, and HTML files in uploads are included. JavaScript, CSS, images, and archives in uploads are not scanned.

The scan runs when an administrator opens wp-admin (at most once a minute, and every time on the AdminTrace screens), after a plugin or theme is installed, activated, deleted, or switched, once a day from WP-Cron, and when you click Scan now. It can also record when a database table with this site’s prefix is created or dropped. The first scan only saves a baseline. File contents and SQL are not stored. WordPress core folders are not hashed.

= How long it is kept =

Events are kept for 90 days by default. Choose any period from 0 days, which keeps rows until you delete them, through 10 years. Administrators can tick events in the journal (timeline or table) and delete them after a confirmation, delete one event through the REST API, or clear the journal for the current site from Settings. Deleting a combined card removes every repeat it stands for.

Editors and shop managers can be allowed to see content activity. They do not see security events or network addresses. Shop managers can also see WooCommerce events.

Multisite keeps a separate journal on each site.

= Privacy =

The journal stays on this site. Profile marks are initials drawn by the plugin. Profile photos from Gravatar stay off until you turn them on under Display. Each event stores the acting user’s role and the time to the millisecond. It does not set a tracking cookie or call an analytics service. The email digest and the HTTPS webhook stay off until an administrator turns them on. Two emails are on by default and go to the site administration address, or to the alert address you set: one when failed logins cross the threshold (this can be turned off), and one for each critical event. Suggested wording is added under Settings → Privacy, and the personal-data exporter and eraser include rows tied to that account.

= What this activity log does not do =

* It does not hide itself from the Plugins screen.
* It does not scan core files against WordPress.org checksums.
* It does not forward events to syslog, Datadog, or Splunk. One HTTPS webhook is available.
* It does not record the body of outgoing HTTP requests. Outgoing email is stored in a separate table only after an administrator turns the email log on.
* It does not keep a separate log of WP Crontrol cron events.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New.
2. Activate AdminTrace Activity Logger.
3. Open AdminTrace Activity Logger in the admin menu. Recording starts immediately.

== Frequently Asked Questions ==

= Where is the journal stored? =

In a database table named `{prefix}srah_events` on each site. Multisite installs keep a separate table per site.

= How long is it kept? =

90 days by default. Change the retention period in the settings, including 0 to keep events until you delete them.

= Who can read it? =

Administrators can read everything. You can let editors and shop managers see content activity. They do not see security events or network addresses.

= Does uninstall remove the data? =

Yes, by default. There is a setting to keep the journal instead. Deactivation does not delete rows.

= Does it send data anywhere? =

The journal stays on this site. The email digest and the webhook are off until an administrator turns them on. Failed-login alerts email the site administrator until that alert is turned off. Critical events are also emailed to the alert address. The private feed is off until it is enabled. Gravatar photos stay off until Display is changed. The plugin does not look up addresses or call an analytics service.

= Does it load profile images from Gravatar? =

Not unless you turn that on under Display. The default is an initial drawn on the site, with no request to Gravatar.

= Does it record WooCommerce orders? =

Yes, when WooCommerce is active. Order status changes, coupon codes, refunds, stock changes, and product price or SKU edits are written to the same activity log. Customer email addresses are not stored.

= Does it log outgoing email? =

Only after an administrator opens Email log and turns it on. Saved emails go in `{prefix}srah_email_log`, a different table from the activity journal. The journal does not receive message bodies. The log stores the recipient, subject, headers, file names, and body, and it follows the same retention period as the journal. It is off by default because messages can include password-reset links.

= Can I export the activity log? =

Yes. CSV and JSON export follow the filters currently on the journal and stop at 5,000 rows. Cells that start with `=`, `+`, `-`, or `@` are prefixed so a spreadsheet does not treat them as formulas.

= Can I see what changed inside a post? =

Yes. A content event stores the fields that changed and a short list of words that were added or removed. The full post is not copied into the log. The same history is available in a panel on the post editing screen.

= Will the activity log slow down visitors? =

Recording runs when someone changes the site, mostly in wp-admin. A logging failure is dropped instead of interrupting WordPress. The public site does not load the journal script.

== Screenshots ==

1. The activity journal, with the page navigation, search, filters, and a post edit shown as added and removed lines.
2. Settings for how long events are kept, who can read them, and optional email or webhook alerts.
3. The same journal as a table, including the account and role for each change.
4. Individual event switches. A whole recorder can still be turned off from Settings.
5. File and database scan settings, with recent added, changed, and deleted files.

== Changelog ==

= Unreleased =
* Email log is a separate screen and table. It stays off until an administrator enables it, and it saves outgoing mail apart from the activity journal.
* A Donate link on the Plugins screen and on AdminTrace pages uses the same PayPal link as this readme.
* The description asks people to open a support ticket for an issue or a feature request.
* Administrators can again select events in the journal and delete them, after a confirmation. A combined card deletes all of its repeats.
* The daily retention task now deletes expired events. It previously kept everything.
* Pages for non-administrators no longer run out of memory when the capability check repeated itself.
* Webhook payloads include the event ID. Exports keep the search and “hide my events” filters.
* Theme and plugin uploads are recorded with their names. Saving a post no longer records an unchanged featured image, and edits are no longer labelled as new posts.
* Journal counts are cached separately for administrators and editors.
* Multisite records site creation, records site deletion in the main journal, and drops the deleted site’s table.
* Saving an unrelated settings section no longer moves the next digest.
* REST list responses include X-WP-TotalPages.
* Events recorded in the same second keep their order, so journal pages no longer repeat or skip them.
* The readme describes the file scan, alert emails, and command palette accurately.

= 1.0.6 =
* Ending another user’s login sessions is recorded.
* Multisite records site creation, deletion, archive or spam changes, and users added to or removed from a site.
* A scan records added, changed, and deleted files, and created or dropped database tables. It runs when wp-admin is open, about once a minute, and when a plugin or theme is installed, activated, or deleted. Scan now checks immediately. The first scan only saves a baseline.
* Yoast SEO and Rank Math on-page fields are recorded. Other private meta is still skipped.
* Events, Files and database, and Display are separate screens. Individual event IDs can be turned off.
* Each row stores the acting user’s role and the time to the millisecond.
* The journal table is upgraded again when those columns are missing, so new events are saved.
* Profile photos from Gravatar can be turned on. Initials stay the default.

= 1.0.5 =
* The directory description now lists the activity log, audit trail, and WooCommerce coverage in the words people search for.
* Plugin and theme automatic-update toggles are recorded.
* The journal notes when the number of available plugin, theme, or WordPress updates changes.
* Menu saves include the item titles that were added or removed.
* Two Factor sign-in records the method name, and a rejected second-factor check is logged without the code.
* Enable Media Replace file replacements are recorded without storing the file address.
* WooCommerce orders include the coupon codes used on that order.
* Image edits name the operation, such as crop or rotate.
* The privacy policy page is included with the other tracked settings.

= 1.0.4 =
* Directory banners, icons, and screenshots are kept out of the plugin package.
* The admin menu icon stylesheet is loaded with wp_enqueue_style().

= 1.0.3 =
* Display name is AdminTrace Activity Logger. The requested permalink is admintrace-activity-logger.
* The journal header and admin menu use the AdminTrace icon.

= 1.0.2 =
* Hooks, options, capabilities, scheduled tasks, and srah_log() use the srah_ prefix.
* Journal counts keep one fixed cache key per site.

= 1.0.1 =
* Profile marks are drawn on the site. The plugin does not request Gravatar.
* Failed-login throttling uses one bounded record instead of a new transient for every username.
* Directory banners, icons, and screenshots are not included in the plugin.
* WordPress loads translations. The plugin no longer calls load_plugin_textdomain().

= 1.0.0 =
* First release.
