# WordPress Elementor MCP

Connect Codex (or another local stdio MCP client) to WordPress pages, Elementor layouts, and supported performance settings. The WordPress plugin provides a token-protected REST bridge; the MCP server runs on your computer. Mutations remain disabled by default.

## Requirements

- WordPress with Elementor active, reachable over HTTPS
- A WordPress account allowed to edit the pages you want to access; performance tools require `manage_options`
- Node.js 20 or newer
- Codex desktop or another MCP client that supports local stdio servers

## 1. Install the WordPress plugin

1. Download `elementor-mcp-bridge.zip` from this repository's Code page (or create it from the `wordpress-plugin` folder).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, upload the ZIP, then activate **Elementor MCP Bridge**.
3. Open **Settings → Elementor MCP**. Select the WordPress user to use and set a long random token. Save it somewhere private; the site stores only a hash, so it cannot show you the token again.

The token gives access as the selected WordPress user. Use a dedicated account with only the needed page permissions where possible. Never put a real token in GitHub, a screenshot, or a shared chat.

## 2. Install the local MCP server

Download/clone this repository to your computer, open a terminal in its folder, and run:

```sh
npm install
```

Copy `.env.example` to `.env`, then fill in your site's HTTPS address and the same token saved in WordPress. Keep `.env` private; it is ignored by Git.

## 3. Connect Codex

Add this server to your Codex MCP configuration (`config.toml`). Replace the working directory with the full path to the folder where you cloned the project:

```toml
[mcp_servers.wordpress_elementor]
command = "npm"
args = ["start"]
cwd = "C:/path/to/wordpress-elementor-mcp"
```

Save the configuration and fully restart Codex. The server should then appear in the MCP tools list. Ask it to list your WordPress pages to confirm the connection.

## Available tools

- `list_pages` — find editable pages by title or status.
- `get_elementor_page` — read page details and its Elementor layout.
- `update_elementor_page` — replace the page's full Elementor content array; disabled by default.
- `get_elementor_document` / `update_elementor_document` — inspect and edit Elementor Library templates such as headers, in addition to pages.
- `get_home_meta_description` / `update_home_meta_description` — manage a plain-text front-page description when a supported SEO plugin is not active.
- `get_elementor_local_fonts` / `update_elementor_local_fonts` — inspect or enable Elementor's local Google Fonts feature.
- `get_site_performance` — inspect WordPress/PHP versions, cache indicators, and detected performance plugins.
- `get_litespeed_settings` — read a limited set of LiteSpeed Cache optimization switches.
- `update_litespeed_settings` — change supported LiteSpeed Cache switches, including JavaScript loading mode and font display; a successful change purges LiteSpeed-managed caches.
- `purge_litespeed_cache` — explicitly purge LiteSpeed-managed caches.

To enable edits and performance changes, set `WP_ALLOW_WRITES=true` in `.env` and restart the MCP server. Elementor updates save a WordPress revision. Performance settings are restricted to a fixed allowlist; the bridge does not expose arbitrary WordPress options. The selected WordPress user must have `manage_options` for performance tools.

LiteSpeed settings are available only when LiteSpeed Cache is installed and active. CSS async loading and UCSS depend on QUIC.cloud services; service usage may incur charges. CSS/JS combination and other optimization switches can affect how the site renders, so change them in small steps and inspect key pages after each change. The bridge does not install or activate plugins, edit server configuration, or assume that an optimization plugin is compatible with the host.

## ChatGPT web connection

This repository currently provides a **local** MCP server for Codex and compatible desktop clients. ChatGPT web requires a publicly reachable remote MCP server and a supported sign-in flow; the local token-based bridge is not enough for a secure direct ChatGPT web connection. Do not expose this WordPress REST bridge without adding proper remote authentication.

## Security

- Use HTTPS and a narrowly permissioned WordPress account.
- Do not commit `.env`, tokens, or site-specific backups.
- Keep writes disabled until you deliberately need them.
- Revoke/replace the bridge token in WordPress if it is shared or exposed.

## License

GPL-2.0-or-later. See `LICENSE`.
