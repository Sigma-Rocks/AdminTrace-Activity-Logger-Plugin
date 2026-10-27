=== AdminTrace Activity Logger ===
Contributors: sigmarocks
Tags: activity, audit, history, log, users
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A readable journal of who changed your WordPress site, and when.

== Description ==

AdminTrace Activity Logger records important changes on a WordPress site: content, accounts, plugins, themes, comments, settings, privacy tools, and WooCommerce orders when WooCommerce is active.

It is a journal with fixed limits. Profile marks are initials drawn on the site. A content edit stores a short added-and-removed preview, not the post. Network addresses stay masked, and the plugin does not look them up. Email and webhooks stay off until an administrator turns them on. Search, filters, export, and retention are included. There is no paid tier.

Each row is a short description. It does not store passwords, application passwords, or API keys. Masked network addresses can also be turned off. The plugin does not call Gravatar, an address lookup, or an analytics service.

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

The journal stays on this site. The email digest and the webhook are off until an administrator turns them on. Failed-login alerts email the site administrator until that alert is turned off. The private feed is off until it is enabled. The plugin does not contact Gravatar, an address lookup, or an analytics service.

= Does it load profile images from Gravatar? =

No. Each person is shown as an initial drawn by the plugin. It does not contact Gravatar or any other avatar service.

== Screenshots ==

1. The activity journal, with search, filters, and a post edit shown as added and removed lines.
2. Settings for how long events are kept, who can read them, and optional email or webhook alerts.
3. The same journal as a table, including the account that made each change.

== Changelog ==

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
