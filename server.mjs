#!/usr/bin/env node
/**
 * wp-mcp — MCP server for operating WordPress sites through the
 * WP MCP Connector plugin (bridge/wp-mcp-connector).
 *
 * Connection model: on the site, wp-admin → Settings → WP MCP generates a
 * revocable token. wp_connect saves it here; wp_disconnect revokes it on the
 * site and forgets it locally. sites.json holds the currently connected sites.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SITES_FILE = path.join(HERE, "sites.json");

/* ------------------------------------------------------------ site config */

function loadSites() {
  try {
    return JSON.parse(fs.readFileSync(SITES_FILE, "utf8"));
  } catch {
    return {};
  }
}

function saveSites(sites) {
  fs.writeFileSync(SITES_FILE, JSON.stringify(sites, null, 2));
  try { fs.chmodSync(SITES_FILE, 0o600); } catch {}
}

function getSite(name) {
  const sites = loadSites();
  const site = sites[name];
  if (!site) {
    const known = Object.keys(sites);
    throw new Error(
      `No connected site named "${name}". ` +
      (known.length ? `Connected sites: ${known.join(", ")}.` : "No sites connected — use wp_connect first.")
    );
  }
  return site;
}

/* ------------------------------------------------------------ HTTP helper */

async function api(siteName, method, restPath, { params, body, rawBody, headers = {} } = {}) {
  const site = typeof siteName === "object" ? siteName : getSite(siteName);
  const url = new URL(site.url.replace(/\/+$/, "") + "/wp-json" + restPath);
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
    }
  }
  const opts = {
    method,
    headers: {
      "X-WPMCP-Token": site.token,
      "Authorization": `Bearer ${site.token}`,
      ...headers,
    },
  };
  if (rawBody !== undefined) {
    opts.body = rawBody;
  } else if (body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }

  const res = await fetch(url, opts);
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = text; }

  if (!res.ok) {
    const msg = data && typeof data === "object" && data.message
      ? `${data.code || res.status}: ${data.message}`
      : `HTTP ${res.status}: ${typeof data === "string" ? data.replace(/<[^>]+>/g, " ").trim().slice(0, 300) : res.statusText}`;
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return data;
}

const bridge = (site, method, p, o) => api(site, method, "/wp-mcp/v1" + p, o);

/* ------------------------------------------------------------ formatting */

const ok = (data) => ({
  content: [{ type: "text", text: typeof data === "string" ? data : JSON.stringify(data, null, 2) }],
});
const fail = (e) => ({ content: [{ type: "text", text: `Error: ${e.message || e}` }], isError: true });
const run = (fn) => async (args) => {
  try { return ok(await fn(args)); } catch (e) { return fail(e); }
};

const restBase = (type) => {
  const map = { post: "posts", page: "pages", media: "media" };
  return map[type] || type;
};

const briefContent = (item) => ({
  id: item.id,
  type: item.type,
  status: item.status,
  title: item.title?.rendered ?? item.title?.raw ?? item.title,
  slug: item.slug,
  modified: item.modified,
  link: item.link,
});

const MIME = {
  jpg: "image/jpeg", jpeg: "image/jpeg", png: "image/png", gif: "image/gif",
  webp: "image/webp", svg: "image/svg+xml", ico: "image/x-icon",
  pdf: "application/pdf", zip: "application/zip",
  mp4: "video/mp4", mov: "video/quicktime", mp3: "audio/mpeg", wav: "audio/wav",
  woff: "font/woff", woff2: "font/woff2", ttf: "font/ttf",
};

/* ------------------------------------------------------------------ tools */

const server = new McpServer({ name: "wp-mcp", version: "1.0.0" });

server.tool(
  "wp_connect",
  "Connect to a WordPress site using a token generated in its wp-admin → Settings → WP MCP page (WP MCP Connector plugin must be installed there). Saves the connection under a short name used by every other tool.",
  {
    name: z.string().describe("Short name for this site, e.g. 'flh' or 'clientx'"),
    url: z.string().describe("Site URL, e.g. https://example.com"),
    token: z.string().describe("Connection token from the WP MCP settings page (starts with wpmcp_)"),
  },
  run(async ({ name, url, token }) => {
    const site = { url: url.replace(/\/+$/, ""), token };
    const info = await api(site, "GET", "/wp-mcp/v1/info");
    const sites = loadSites();
    sites[name] = {
      ...site,
      rescueToken: info.rescue_token || null,
      siteName: info.site_name,
      connectedAt: new Date().toISOString(),
    };
    saveSites(sites);
    delete info.rescue_token;
    return { connected: name, ...info };
  })
);

server.tool(
  "wp_disconnect",
  "Disconnect from a site: revokes the token on the WordPress side (unless revoke=false) and removes it locally. Reconnect later with a freshly generated token.",
  {
    site: z.string().describe("Site name used at wp_connect"),
    revoke: z.boolean().optional().describe("Also revoke the token on the site (default true)"),
  },
  run(async ({ site, revoke = true }) => {
    let remote = "not revoked (revoke=false)";
    if (revoke) {
      try {
        await bridge(site, "POST", "/disconnect");
        remote = "token revoked on site";
      } catch (e) {
        remote = `revoke failed (${e.message}) — token removed locally anyway; it will die at its expiry`;
      }
    }
    const sites = loadSites();
    delete sites[site];
    saveSites(sites);
    return { disconnected: site, remote };
  })
);

server.tool(
  "wp_sites",
  "List connected sites, optionally checking each connection is still alive.",
  { check: z.boolean().optional().describe("Ping each site's /info endpoint (default false)") },
  run(async ({ check = false }) => {
    const sites = loadSites();
    const out = [];
    for (const [name, site] of Object.entries(sites)) {
      const row = { name, url: site.url, siteName: site.siteName, connectedAt: site.connectedAt };
      if (check) {
        try {
          const info = await api(site, "GET", "/wp-mcp/v1/info");
          row.status = "connected";
          row.wp = info.wp_version;
          row.theme = info.active_theme?.stylesheet;
          if (info.rescue_token && info.rescue_token !== site.rescueToken) {
            sites[name].rescueToken = info.rescue_token;
            saveSites(sites);
          }
        } catch (e) {
          row.status = `unreachable: ${e.message}`;
        }
      }
      out.push(row);
    }
    return out.length ? out : "No sites connected. Generate a token on the site (Settings → WP MCP) and use wp_connect.";
  })
);

/* ---- content ---- */

server.tool(
  "wp_content_list",
  "List posts, pages or any custom post type. Returns compact rows (id, title, status, slug, modified).",
  {
    site: z.string(),
    type: z.string().optional().describe("posts (default), pages, or a custom type's REST base"),
    search: z.string().optional(),
    status: z.string().optional().describe("publish, draft, future, pending, private, any"),
    per_page: z.number().optional().describe("Default 20, max 100"),
    page: z.number().optional(),
  },
  run(async ({ site, type = "posts", search, status, per_page = 20, page }) => {
    const data = await api(site, "GET", `/wp/v2/${restBase(type)}`, {
      params: { search, status, per_page, page, context: "edit" },
    });
    return Array.isArray(data) ? data.map(briefContent) : data;
  })
);

server.tool(
  "wp_content_get",
  "Get one post/page with full raw content (context=edit), for reading or before editing.",
  {
    site: z.string(),
    type: z.string().optional().describe("posts (default), pages, or a custom type's REST base"),
    id: z.number(),
  },
  run(async ({ site, type = "posts", id }) => {
    const d = await api(site, "GET", `/wp/v2/${restBase(type)}/${id}`, { params: { context: "edit" } });
    return {
      ...briefContent(d),
      content: d.content?.raw ?? d.content?.rendered,
      excerpt: d.excerpt?.raw,
      template: d.template,
      parent: d.parent,
      categories: d.categories,
      tags: d.tags,
      featured_media: d.featured_media,
    };
  })
);

server.tool(
  "wp_content_create",
  "Create a post, page or custom-type item. content is HTML (Gutenberg block markup also works).",
  {
    site: z.string(),
    type: z.string().optional().describe("posts (default), pages, or a custom type's REST base"),
    title: z.string(),
    content: z.string().optional(),
    status: z.string().optional().describe("draft (default), publish, future, private"),
    slug: z.string().optional(),
    excerpt: z.string().optional(),
    extra: z.string().optional().describe("JSON object of extra REST fields: categories, tags, meta, parent, template, date…"),
  },
  run(async ({ site, type = "posts", title, content, status = "draft", slug, excerpt, extra }) => {
    const body = { title, content, status, slug, excerpt, ...(extra ? JSON.parse(extra) : {}) };
    for (const k of Object.keys(body)) if (body[k] === undefined) delete body[k];
    const d = await api(site, "POST", `/wp/v2/${restBase(type)}`, { body });
    return briefContent(d);
  })
);

server.tool(
  "wp_content_update",
  "Update fields on an existing post/page. Only the fields you pass are changed.",
  {
    site: z.string(),
    type: z.string().optional(),
    id: z.number(),
    title: z.string().optional(),
    content: z.string().optional(),
    status: z.string().optional(),
    slug: z.string().optional(),
    excerpt: z.string().optional(),
    extra: z.string().optional().describe("JSON object of extra REST fields"),
  },
  run(async ({ site, type = "posts", id, extra, ...fields }) => {
    const body = { ...fields, ...(extra ? JSON.parse(extra) : {}) };
    for (const k of Object.keys(body)) if (body[k] === undefined) delete body[k];
    if (!Object.keys(body).length) throw new Error("Nothing to update — pass at least one field.");
    const d = await api(site, "POST", `/wp/v2/${restBase(type)}/${id}`, { body });
    return briefContent(d);
  })
);

server.tool(
  "wp_content_delete",
  "Delete a post/page. Goes to trash by default; force=true deletes permanently.",
  {
    site: z.string(),
    type: z.string().optional(),
    id: z.number(),
    force: z.boolean().optional(),
  },
  run(async ({ site, type = "posts", id, force = false }) => {
    const d = await api(site, "DELETE", `/wp/v2/${restBase(type)}/${id}`, { params: { force } });
    return { deleted: id, trashed: !force, title: d.title?.rendered ?? d.previous?.title?.rendered };
  })
);

server.tool(
  "wp_media_upload",
  "Upload a local file to the site's media library.",
  {
    site: z.string(),
    file_path: z.string().describe("Absolute path of the local file to upload"),
    title: z.string().optional(),
    alt_text: z.string().optional(),
  },
  run(async ({ site, file_path, title, alt_text }) => {
    const buf = fs.readFileSync(file_path);
    const filename = path.basename(file_path);
    const ext = filename.split(".").pop().toLowerCase();
    const d = await api(site, "POST", "/wp/v2/media", {
      rawBody: buf,
      headers: {
        "Content-Type": MIME[ext] || "application/octet-stream",
        "Content-Disposition": `attachment; filename="${filename.replace(/"/g, "")}"`,
      },
    });
    if (title || alt_text) {
      await api(site, "POST", `/wp/v2/media/${d.id}`, { body: { title, alt_text } });
    }
    return { id: d.id, url: d.source_url, link: d.link };
  })
);

/* ---- plugins & themes ---- */

server.tool(
  "wp_plugins",
  "Manage plugins: list | install (wp.org slug or zip_url) | activate | deactivate | update | delete. 'plugin' is the identifier from list, e.g. 'akismet/akismet'.",
  {
    site: z.string(),
    action: z.enum(["list", "install", "activate", "deactivate", "update", "delete"]),
    plugin: z.string().optional().describe("Plugin identifier for activate/deactivate/delete, e.g. 'akismet/akismet'"),
    slug: z.string().optional().describe("wp.org slug for install/update, e.g. 'wordpress-seo'"),
    zip_url: z.string().optional().describe("Direct zip URL for install (non-wp.org plugins)"),
    activate: z.boolean().optional().describe("Activate right after install (default true)"),
  },
  run(async ({ site, action, plugin, slug, zip_url, activate = true }) => {
    switch (action) {
      case "list": {
        const d = await api(site, "GET", "/wp/v2/plugins");
        return d.map((p) => ({ plugin: p.plugin, name: p.name, status: p.status, version: p.version }));
      }
      case "install":
      case "update": {
        if (zip_url || action === "update") {
          if (!slug && !zip_url) throw new Error("Provide slug or zip_url.");
          return bridge(site, "POST", "/install", { body: { type: "plugin", slug, zip_url, activate: action === "install" ? activate : false } });
        }
        const d = await api(site, "POST", "/wp/v2/plugins", { body: { slug, status: activate ? "active" : "inactive" } });
        return { installed: d.plugin, name: d.name, status: d.status, version: d.version };
      }
      case "activate":
      case "deactivate": {
        if (!plugin) throw new Error("Provide plugin (e.g. 'akismet/akismet').");
        const d = await api(site, "PUT", `/wp/v2/plugins/${plugin}`, { body: { status: action === "activate" ? "active" : "inactive" } });
        return { plugin: d.plugin, status: d.status };
      }
      case "delete": {
        if (!plugin) throw new Error("Provide plugin (e.g. 'akismet/akismet').");
        try { await api(site, "PUT", `/wp/v2/plugins/${plugin}`, { body: { status: "inactive" } }); } catch {}
        await api(site, "DELETE", `/wp/v2/plugins/${plugin}`);
        return { deleted: plugin };
      }
    }
  })
);

server.tool(
  "wp_themes",
  "Manage themes: list | install (wp.org slug or zip_url) | activate | delete. 'stylesheet' is the theme's folder name.",
  {
    site: z.string(),
    action: z.enum(["list", "install", "activate", "delete"]),
    stylesheet: z.string().optional().describe("Theme folder name for activate/delete"),
    slug: z.string().optional().describe("wp.org slug for install"),
    zip_url: z.string().optional().describe("Direct zip URL for install"),
    activate: z.boolean().optional().describe("Activate right after install (default false)"),
  },
  run(async ({ site, action, stylesheet, slug, zip_url, activate = false }) => {
    switch (action) {
      case "list": {
        const d = await api(site, "GET", "/wp/v2/themes");
        return d.map((t) => ({
          stylesheet: t.stylesheet,
          name: t.name?.rendered ?? t.name,
          status: t.status,
          version: t.version,
        }));
      }
      case "install":
        if (!slug && !zip_url) throw new Error("Provide slug or zip_url.");
        return bridge(site, "POST", "/install", { body: { type: "theme", slug, zip_url, activate } });
      case "activate":
      case "delete":
        if (!stylesheet) throw new Error("Provide stylesheet (theme folder name).");
        return bridge(site, "POST", "/theme", { body: { action, stylesheet } });
    }
  })
);

/* ---- files ---- */

server.tool(
  "wp_file_list",
  "List a directory on the site. Paths are relative to wp-content (e.g. 'themes/twentytwentyfour'). Empty path = wp-content itself.",
  { site: z.string(), path: z.string().optional() },
  run(({ site, path: p = "" }) => bridge(site, "GET", "/fs/list", { params: { path: p } }))
);

server.tool(
  "wp_file_read",
  "Read a file from the site (relative to wp-content, e.g. 'themes/mytheme/functions.php'). Returns the text content.",
  { site: z.string(), path: z.string() },
  run(async ({ site, path: p }) => {
    const d = await bridge(site, "GET", "/fs/read", { params: { path: p } });
    const buf = Buffer.from(d.content_b64, "base64");
    const isText = !buf.subarray(0, 8000).includes(0);
    return isText
      ? `# ${d.path} (${d.size} bytes, modified ${d.modified}, backup: ${d.has_backup})\n\n${buf.toString("utf8")}`
      : { ...d, content_b64: `<binary, ${d.size} bytes — use wp_api to fetch raw if needed>` };
  })
);

server.tool(
  "wp_file_write",
  "Write a file on the site (relative to wp-content). The previous version is automatically saved as <path>.wpmcp-bak — if a PHP edit breaks the site, wp_file_restore can bring it back even when WordPress is down.",
  {
    site: z.string(),
    path: z.string(),
    content: z.string().describe("Full new file content"),
    no_backup: z.boolean().optional().describe("Skip the automatic .wpmcp-bak backup"),
  },
  run(({ site, path: p, content, no_backup }) =>
    bridge(site, "POST", "/fs/write", {
      body: { path: p, content_b64: Buffer.from(content, "utf8").toString("base64"), no_backup },
    })
  )
);

server.tool(
  "wp_file_delete",
  "Delete a file (or a directory with recursive=true) on the site, relative to wp-content.",
  { site: z.string(), path: z.string(), recursive: z.boolean().optional() },
  run(({ site, path: p, recursive }) => bridge(site, "POST", "/fs/delete", { body: { path: p, recursive } }))
);

server.tool(
  "wp_file_restore",
  "Restore a file from its automatic .wpmcp-bak backup. If the site's REST API is down (e.g. a fatal PHP error from the last edit), this falls back to the standalone rescue endpoint, which works even when WordPress cannot boot.",
  { site: z.string(), path: z.string() },
  run(async ({ site, path: p }) => {
    try {
      return await bridge(site, "POST", "/fs/restore", { body: { path: p } });
    } catch (e) {
      if (e.status && e.status < 500) throw e; // real API answer (404 no backup etc.)
      const s = getSite(site);
      if (!s.rescueToken) throw new Error(`Bridge unreachable (${e.message}) and no rescue token cached — restore via hosting file manager.`);
      const res = await fetch(`${s.url}/wp-content/plugins/wp-mcp-connector/rescue.php`, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ token: s.rescueToken, path: p }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) throw new Error(`Rescue failed: ${data?.error || res.status}`);
      return { ...data, via: "rescue endpoint (WordPress was unreachable)" };
    }
  })
);

/* ---- escape hatch ---- */

server.tool(
  "wp_api",
  "Raw WordPress REST API request for anything not covered by the other tools (users, menus, settings, comments, WooCommerce, custom endpoints…). Path starts after /wp-json, e.g. '/wp/v2/settings' or '/wc/v3/orders'.",
  {
    site: z.string(),
    method: z.enum(["GET", "POST", "PUT", "PATCH", "DELETE"]),
    path: z.string().describe("REST path after /wp-json, starting with /"),
    body: z.string().optional().describe("JSON body for POST/PUT/PATCH"),
    params: z.string().optional().describe("JSON object of query parameters"),
  },
  run(async ({ site, method, path: p, body, params }) => {
    const data = await api(site, method, p.startsWith("/") ? p : "/" + p, {
      body: body ? JSON.parse(body) : undefined,
      params: params ? JSON.parse(params) : undefined,
    });
    const text = JSON.stringify(data, null, 2);
    return text.length > 60000 ? text.slice(0, 60000) + "\n… (truncated)" : data;
  })
);

/* ------------------------------------------------------------------ start */

const transport = new StdioServerTransport();
await server.connect(transport);
