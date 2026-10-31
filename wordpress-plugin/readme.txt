=== Elementor MCP Bridge ===
Contributors: hunain
Tags: elementor, mcp, rest-api
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a token-protected REST bridge for a local Elementor MCP server.

== Description ==

This plugin lets a locally running MCP server list WordPress pages and read or update Elementor layouts using the permissions of a selected WordPress user. Updates are disabled by default in the local MCP server.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate Elementor MCP Bridge and ensure Elementor is active.
3. Go to Settings > Elementor MCP and save a long random token and the WordPress user to use.
4. Configure the local MCP server using the project README.

== Security ==

Use HTTPS and a dedicated user with only the necessary page permissions. Keep the token private. This plugin is intended for a local MCP connection; it does not implement OAuth for ChatGPT web.

== Changelog ==

= 0.2.0 =
* Initial token-authenticated bridge release.
