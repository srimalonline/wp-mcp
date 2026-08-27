# wp-mcp

MCP server that turns Claude Code into a full WordPress operator — content, media,
plugins, themes, and **direct theme-file editing** — on any site, including shared
hosting with no SSH/WP-CLI.

## How it works

Two halves:

- **`bridge/wp-mcp-connector.zip`** — a small WordPress plugin you upload once per
  site. It provides the token-based connection, file endpoints (scoped to
  `wp-content`), a plugin/theme installer, and a standalone `rescue.php` that can
  restore a backup even when a broken PHP edit has taken the site down.
- **`server.mjs`** — the MCP server registered in Claude Code. It talks to the
  plugin plus WordPress's core REST API.

### Connection lifecycle (the whole security model)

1. Upload `wp-mcp-connector.zip` on the site (Plugins → Add New → Upload) and activate it.
2. wp-admin → **Settings → WP MCP** → *Generate token* (pick expiry: 1h / 8h / 24h / 7d).
   The token is shown **once**. A new token replaces any previous one.
3. Tell Claude: `connect to https://example.com with token wpmcp_xxx, call it "clientx"`.
4. Work: edit theme files, install plugins, write posts, whatever.
5. Say `disconnect from clientx` — the token is revoked on the site and forgotten locally.
6. Next session: generate a fresh token, reconnect.

No standing credentials live anywhere. `sites.json` (gitignored, chmod 600) holds
only the currently connected sites.

## Setup (one time, on this Mac)

```sh
cd ~/mcp/wp-mcp && npm install
claude mcp add --scope user wordpress -- node ~/mcp/wp-mcp/server.mjs
```

## Tools

| tool | does |
|---|---|
| `wp_connect` / `wp_disconnect` / `wp_sites` | connection lifecycle |
| `wp_content_list/get/create/update/delete` | posts, pages, any custom post type |
| `wp_media_upload` | local file → media library |
| `wp_plugins` | list / install (wp.org slug or zip URL) / activate / deactivate / update / delete |
| `wp_themes` | list / install / activate / delete |
| `wp_file_list/read/write/delete` | direct files under `wp-content` (themes, plugins, uploads) |
| `wp_file_restore` | undo a file write from its automatic `.wpmcp-bak` backup — falls back to `rescue.php` if the site is fatally broken |
| `wp_api` | raw REST escape hatch (`/wp/v2/settings`, `/wc/v3/orders`, menus, users…) |

## Safety notes

- Every `wp_file_write` over an existing file first saves `<file>.wpmcp-bak`.
  If an edit to `functions.php` white-screens the site, `wp_file_restore` still
  works: `rescue.php` runs without loading WordPress at all.
- File access is limited to `wp-content`. To allow the whole WP root (e.g.
  `wp-config.php` edits), add `define('WPMCP_ALLOW_FULL', true);` to wp-config.php.
- Use HTTPS sites only — the token travels in a header.
- Some shared hosts strip the `Authorization` header; the server also sends
  `X-WPMCP-Token`, which survives everywhere tested.
- On multisite, plugin/theme installs need a super-admin's token.
