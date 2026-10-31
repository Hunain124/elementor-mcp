# WordPress Elementor MCP

Connect Codex (or another local stdio MCP client) to WordPress pages and Elementor layouts. The WordPress plugin provides a small, token-protected REST bridge; the MCP server runs on your computer. The default setup is read-only.

## Requirements

- WordPress with Elementor active, reachable over HTTPS
- A WordPress account allowed to edit the pages you want to access
- Node.js 20 or newer
- Codex desktop or another MCP client that supports local stdio servers

## 1. Install the WordPress plugin

1. Download `elementor-mcp-bridge.zip` from the GitHub release (or create it from the `wordpress-plugin` folder).
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

To enable edits, set `WP_ALLOW_WRITES=true` in `.env` and restart the MCP server. Every update saves a WordPress revision first. Review the page in Elementor after an update.

## ChatGPT web connection

This repository currently provides a **local** MCP server for Codex and compatible desktop clients. ChatGPT web requires a publicly reachable remote MCP server and a supported sign-in flow; the local token-based bridge is not enough for a secure direct ChatGPT web connection. Do not expose this WordPress REST bridge without adding proper remote authentication.

## Security

- Use HTTPS and a narrowly permissioned WordPress account.
- Do not commit `.env`, tokens, or site-specific backups.
- Keep writes disabled until you deliberately need them.
- Revoke/replace the bridge token in WordPress if it is shared or exposed.

## License

GPL-2.0-or-later. See `LICENSE`.
