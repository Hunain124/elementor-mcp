=== Elementor MCP Bridge ===
Contributors: hunain
Tags: elementor, mcp, rest-api
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a token-protected REST bridge for a local WordPress MCP server. Includes Elementor page access and restricted LiteSpeed Cache performance controls.

== Description ==

This plugin lets a locally running MCP server list WordPress pages and read or update Elementor layouts using the permissions of a selected WordPress user. Updates are disabled by default in the local MCP server.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate Elementor MCP Bridge and ensure Elementor is active.
3. Go to Settings > Elementor MCP and save a long random token and the WordPress user to use.
4. Configure the local MCP server using the project README.

== Performance Controls ==

The performance overview and LiteSpeed Cache endpoints require the selected user to have the `manage_options` capability. Supported LiteSpeed settings are allowlisted; arbitrary WordPress options are not exposed. LiteSpeed Cache must be installed and active. CSS asynchronous loading and UCSS may rely on QUIC.cloud services.

Elementor document tools support pages and Elementor Library templates. The homepage description endpoint writes one plain-text description (up to 160 characters) and emits it only on the front page when a supported SEO plugin is not active.
The Elementor local Google Fonts endpoint exposes only the documented on/off preference and clears generated Elementor/LiteSpeed caches after changes.

== Security ==

Use HTTPS and a dedicated user with only the necessary page permissions. Keep the token private. This plugin is intended for a local MCP connection; it does not implement OAuth for ChatGPT web.

== Changelog ==

= 0.3.4 =
* Add a bounded Elementor local Google Fonts setting.

= 0.3.3 =
* Add bounded homepage description metadata controls.
* Add allowlisted Elementor page and Theme Builder document routes.
* Add LiteSpeed font-display control.

= 0.3.2 =
* Add a bounded LiteSpeed JavaScript loading mode setting: off, defer, or delay.

= 0.3.1 =
* Use LiteSpeed Cache's current configuration-save hook, retaining compatibility with the batch-update hook.

= 0.3.0 =
* Add performance diagnostics and allowlisted LiteSpeed Cache settings and purge routes.

= 0.2.0 =
* Initial token-authenticated bridge release.
