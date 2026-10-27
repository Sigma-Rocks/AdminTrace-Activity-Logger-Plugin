# Feature comparison

Sources: the public `readme.txt` shipped with Simple History 5.36.0 (tested up to WordPress 7.1) and the Premium, WooCommerce, and Debug and Monitor descriptions in that file. This is a checklist of user-facing needs, not a claim that AdminTrace Activity Logger reproduces another product.

Simple History’s FAQ says the log can be exported, while the Premium section markets filtered CSV/JSON export. Both statements are public. AdminTrace Activity Logger includes filtered CSV and JSON export in the free plugin.

| Need | Simple History free (public readme) | Documented paid add-on | AdminTrace Activity Logger 1.0.0 |
| --- | --- | --- | --- |
| Content, users, comments, media, menus, widgets | Yes | — | Yes |
| Plugin, theme, and core updates | Yes | — | Yes |
| Failed logins, with masked addresses | Yes | — | Yes. Full addresses are not stored, and there is no geolocation lookup |
| File editor changes | Yes, when the edit is accepted | — | Yes, only after the file hash changes |
| Site Health and denied admin screens | Yes | — | Yes |
| Third-party plugin hooks | Many named plugins | — | Jetpack, User Switching, ACF, Duplicate Post, Beaver Builder, Redirection, Two Factor login flag |
| WooCommerce orders, refunds, stock, prices | — | WooCommerce Logger add-on | Included when WooCommerce is active |
| Dashboard widget and admin bar | Yes | — | Yes |
| Search, filters, timeline and compact/table views | Yes. Table view is described as Premium | Table view | Timeline and table, both included |
| Field-level before/after | Yes | — | Yes, plus a short added/removed word list. Full post text is not stored |
| Configurable retention in the UI | Default 30 or 60 days | Premium GUI, including keep forever | 0–3650 days in settings, 0 keeps events |
| Filtered CSV/JSON export | Docs disagree | Premium | Included, 5,000 rows per download |
| Email digest | Weekly email is documented in the free readme | Instant alerts are Premium | Daily or weekly plain-text digest, plus email for critical events |
| Webhook / forwarding | — | Log Forwarding add-on (files, syslog, Datadog, Splunk, webhook) | One administrator-set HTTPS webhook. Syslog and vendor log drains are not included |
| Outgoing HTTP and email monitor | — | Debug and Monitor add-on | Not included, to avoid storing secrets |
| REST API and WP-CLI | Yes | — | Yes |
| RSS feed | Yes, secret feed | — | Off until enabled. The address is a secret |
| Custom events | Logging API | GUI for manual entries is Premium | `srah_log()` and a journal note form |
| Logger on/off switches | — | Premium | Settings checkboxes |
| Stealth mode | Code filter; GUI is Premium | Premium GUI | Not included. Hiding the plugin is intentionally omitted |
| Post editor activity | — | Premium block-editor panel | Side meta box on the post editor |
| Multisite | Per site | — | Per-site tables, including new sites after network activation |
| Privacy policy text, export, and erase | Yes | — | Yes |
| Uninstall cleanup | Documented by that plugin | — | On by default, with a setting to keep rows |

AdminTrace Activity Logger does not reuse Simple History’s code, database tables, hooks, or interface.
