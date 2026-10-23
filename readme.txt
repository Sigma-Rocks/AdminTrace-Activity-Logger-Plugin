=== Advance History ===
Contributors: sigmarocks
Tags: activity, audit, history, log, users
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A readable journal of who changed your WordPress site, and when.

== Description ==

Advance History records important changes on a WordPress site: content, accounts, plugins, themes, comments, settings, privacy tools, and WooCommerce orders when WooCommerce is active.

There is no paid tier. Search, filters, export, retention, email digests, and the WooCommerce recorder are all included.

The journal stores a short description of each change. A post edit can include a short line-by-line preview of what changed, not a copy of the whole post. It does not store passwords, application passwords, or API keys. Network addresses are masked unless you turn them off. Advance History does not look addresses up with an outside service and does not phone home.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New.
2. Activate Advance History.
3. Open Advance History in the admin menu. Recording starts immediately.

== Frequently Asked Questions ==

= Where is the journal stored? =

In a database table named `{prefix}ah_events` on each site. Multisite installs keep a separate table per site.

= How long is it kept? =

90 days by default. Change the retention period in the settings, including 0 to keep events until you delete them.

= Who can read it? =

Administrators can read everything. You can let editors and shop managers see content activity. They do not see security events or network addresses.

= Does uninstall remove the data? =

Yes, by default. There is a setting to keep the journal instead. Deactivation does not delete rows.

= Does it send data anywhere? =

Only if you save an email recipient or an HTTPS webhook. Both are off until you turn them on. The private RSS feed is also off until you enable it.

== Changelog ==

= 1.0.0 =
* First release.
