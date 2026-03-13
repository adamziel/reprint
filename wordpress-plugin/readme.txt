=== Site Export ===
Contributors: flavor
Tags: export, migration, sync, backup, database
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes a site export API for resumable, cursor-based synchronization of database and files over HTTP.

== Description ==

Site Export turns your WordPress site into a streaming export server. An external import tool connects to the API, authenticates via HMAC, and downloads your database and files in resumable chunks.

The plugin is designed for resource-constrained shared hosting environments. It carefully manages memory and execution time, pausing and resuming via cursors so it never hits host limits.

**Features:**

* Cursor-based resumable exports — never loses progress
* HMAC-authenticated API — no passwords transmitted
* Streaming multipart transport — handles files of any size
* Memory and execution time budgeting — works on shared hosts
* Full database export with batched INSERT statements
* File tree synchronization with symlink support
* Delta sync — only transfers what changed

**How it works:**

1. Install and activate the plugin
2. Your import tool generates a connection token
3. Paste the token into the plugin settings (Tools > Site Export)
4. The import tool connects and downloads everything over HTTP

== Installation ==

1. Upload the `site-export` directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the Site Export page in the admin menu
4. Paste the connection token from your import tool

== Frequently Asked Questions ==

= Does this plugin export my site automatically? =

No. The plugin only exposes an API endpoint. An external import tool must connect to it and initiate the transfer.

= Is the API secure? =

Yes. All requests are authenticated using HMAC signatures with a shared secret, nonce, and timestamp. Requests expire after 5 minutes to prevent replay attacks.

= Will this slow down my site? =

No. The export API only runs when explicitly called with the `?site-export-api` query parameter. Normal page loads are not affected.

= Does it work on shared hosting? =

Yes. The plugin is specifically designed for shared hosting. It tracks memory usage and execution time, gracefully pausing before hitting host limits and resuming where it left off.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
