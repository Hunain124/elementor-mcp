import "dotenv/config";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const baseUrl = process.env.WP_BASE_URL?.replace(/\/$/, "");
const apiToken = process.env.WP_MCP_TOKEN;
const allowWrites = process.env.WP_ALLOW_WRITES === "true";
if (!baseUrl || !apiToken) throw new Error("Set WP_BASE_URL and WP_MCP_TOKEN.");
if (new URL(baseUrl).protocol !== "https:" && !["localhost", "127.0.0.1"].includes(new URL(baseUrl).hostname)) {
  throw new Error("WordPress credentials require HTTPS (localhost is allowed for development).");
}

async function wp<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${baseUrl}/wp-json${path}`, {
    ...init,
    headers: { Authorization: `Bearer ${apiToken}`, "Content-Type": "application/json", ...init.headers },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`WordPress API ${response.status}: ${(body as any).message ?? response.statusText}`);
  return body as T;
}

const server = new McpServer({ name: "wordpress-elementor", version: "0.3.0" }, {
  instructions: "Use list_pages or get_elementor_page before editing. Elementor layouts are recursive JSON. Performance tools inspect WordPress and manage only documented LiteSpeed Cache settings. Mutations are disabled unless WP_ALLOW_WRITES=true. CSS/JS optimization can affect rendering or site behavior; change targeted settings and verify the public site after changes.",
});

server.registerTool("list_pages", {
  title: "List WordPress pages",
  description: "Find WordPress pages by title or status. Use before opening a page for Elementor work.",
  inputSchema: { search: z.string().optional(), status: z.enum(["publish", "draft", "private", "pending"]).optional(), perPage: z.number().int().min(1).max(100).default(20) },
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async ({ search, status, perPage }) => {
  const query = new URLSearchParams({ per_page: String(perPage), ...(search ? { search } : {}), ...(status ? { status } : {}) });
  const pages = await wp<Array<{ id: number; title: string; link: string; status: string }>>(`/elementor-mcp/v1/pages?${query}`);
  return { content: [{ type: "text", text: JSON.stringify(pages, null, 2) }] };
});

server.registerTool("get_elementor_page", {
  title: "Read Elementor page layout",
  description: "Read the Elementor JSON layout and page metadata by WordPress page ID. Call before changing the layout.",
  inputSchema: { pageId: z.number().int().positive() },
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async ({ pageId }) => {
  const result = await wp<{ id: number; title: string; link: string; status: string; layout: unknown }>(`/elementor-mcp/v1/pages/${pageId}`);
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("update_elementor_page", {
  title: "Update Elementor page layout",
  description: "Replace a page's complete Elementor content array. First read the page and preserve all elements the user did not ask to change. Requires WP_ALLOW_WRITES=true and an editor account.",
  inputSchema: { pageId: z.number().int().positive(), content: z.array(z.record(z.unknown())), confirmReplace: z.literal(true) },
  annotations: { readOnlyHint: false, destructiveHint: true, openWorldHint: false },
}, async ({ pageId, content }) => {
  if (!allowWrites) throw new Error("Writes are disabled. Set WP_ALLOW_WRITES=true to enable this tool.");
  const result = await wp<{ id: number; title: string; link: string; status: string }>(`/elementor-mcp/v1/pages/${pageId}`, { method: "PUT", body: JSON.stringify({ content }) });
  return { content: [{ type: "text", text: JSON.stringify({ ...result, updated: true }, null, 2) }] };
});

server.registerTool("get_elementor_document", {
  title: "Read an Elementor page or template",
  description: "Read an Elementor page or Theme Builder template by document ID. Use before changing the layout.",
  inputSchema: { documentId: z.number().int().positive() },
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async ({ documentId }) => {
  const result = await wp<unknown>(`/elementor-mcp/v1/documents/${documentId}`);
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("update_elementor_document", {
  title: "Update an Elementor page or template",
  description: "Replace an Elementor page or Theme Builder template content array. Read it first and preserve all elements the user did not ask to change. Requires WP_ALLOW_WRITES=true.",
  inputSchema: { documentId: z.number().int().positive(), content: z.array(z.record(z.unknown())), confirmReplace: z.literal(true) },
  annotations: { readOnlyHint: false, destructiveHint: true, openWorldHint: false },
}, async ({ documentId, content }) => {
  if (!allowWrites) throw new Error("Writes are disabled. Set WP_ALLOW_WRITES=true to enable this tool.");
  const result = await wp<unknown>(`/elementor-mcp/v1/documents/${documentId}`, { method: "PUT", body: JSON.stringify({ content }) });
  return { content: [{ type: "text", text: JSON.stringify({ result, updated: true }, null, 2) }] };
});

server.registerTool("get_home_meta_description", {
  title: "Read homepage meta description",
  description: "Read the bridge-managed homepage search description. Another SEO plugin may manage this tag instead.",
  inputSchema: {},
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async () => {
  const result = await wp<unknown>("/elementor-mcp/v1/seo/home-description");
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("update_home_meta_description", {
  title: "Set homepage meta description",
  description: "Set or clear the homepage's search description (up to 160 characters). The bridge outputs it only when no supported SEO plugin is active and purges LiteSpeed cache.",
  inputSchema: { description: z.string().max(160) },
  annotations: { readOnlyHint: false, destructiveHint: false, openWorldHint: false },
}, async ({ description }) => {
  if (!allowWrites) throw new Error("Writes are disabled. Set WP_ALLOW_WRITES=true to enable site metadata changes.");
  const result = await wp<unknown>("/elementor-mcp/v1/seo/home-description", { method: "PUT", body: JSON.stringify({ description }) });
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("get_elementor_local_fonts", {
  title: "Read Elementor local Google Fonts setting",
  description: "Read whether Elementor serves Google Fonts from the site itself, plus its feature state.",
  inputSchema: {},
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async () => {
  const result = await wp<unknown>("/elementor-mcp/v1/elementor/performance/local-fonts");
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("update_elementor_local_fonts", {
  title: "Set Elementor local Google Fonts",
  description: "Enable or disable Elementor's local Google Fonts feature. Enabling it downloads fonts to the site's uploads folder, clears Elementor generated CSS, and purges LiteSpeed cache. Requires WP_ALLOW_WRITES=true.",
  inputSchema: { enabled: z.boolean() },
  annotations: { readOnlyHint: false, destructiveHint: false, openWorldHint: false },
}, async ({ enabled }) => {
  if (!allowWrites) throw new Error("Writes are disabled. Set WP_ALLOW_WRITES=true to enable Elementor performance changes.");
  const result = await wp<unknown>("/elementor-mcp/v1/elementor/performance/local-fonts", { method: "PUT", body: JSON.stringify({ enabled }) });
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("get_site_performance", {
  title: "Inspect WordPress performance setup",
  description: "Read WordPress/PHP cache indicators and detect installed performance plugins. Does not expose secrets or change the site.",
  inputSchema: {},
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async () => {
  const result = await wp<unknown>("/elementor-mcp/v1/performance");
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("get_litespeed_settings", {
  title: "Read LiteSpeed optimization settings",
  description: "Read the supported LiteSpeed Cache CSS, JavaScript, HTML, and media optimization settings. Requires LiteSpeed Cache to be active and a WordPress user with manage_options.",
  inputSchema: {},
  annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
}, async () => {
  const result = await wp<unknown>("/elementor-mcp/v1/performance/litespeed");
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

const litespeedSettingsSchema = z.object({
  cssMinify: z.boolean().optional(),
  cssCombine: z.boolean().optional(),
  ucss: z.boolean().optional(),
  ucssInline: z.boolean().optional(),
  cssAsync: z.boolean().optional(),
  jsMinify: z.boolean().optional(),
  jsCombine: z.boolean().optional(),
  htmlMinify: z.boolean().optional(),
  lazyImages: z.boolean().optional(),
  lazyIframes: z.boolean().optional(),
  removeEmoji: z.boolean().optional(),
  googleFontsAsync: z.boolean().optional(),
  removeQueryStrings: z.boolean().optional(),
  jsLoading: z.enum(["off", "defer", "delay"]).optional(),
  fontDisplay: z.enum(["auto", "block", "swap", "fallback", "optional"]).optional(),
}).strict().refine((settings) => Object.keys(settings).length > 0, "Provide at least one setting.");

server.registerTool("update_litespeed_settings", {
  title: "Update LiteSpeed optimization settings",
  description: "Update only the listed LiteSpeed Cache optimization settings. jsLoading supports off, defer, or delay; fontDisplay supports auto, block, swap, fallback, or optional. Requires WP_ALLOW_WRITES=true, LiteSpeed Cache active, and a WordPress user with manage_options. CSS async and UCSS require QUIC.cloud; combining or delaying assets can break site behavior. Changed settings trigger a LiteSpeed cache purge.",
  inputSchema: { settings: litespeedSettingsSchema },
  annotations: { readOnlyHint: false, destructiveHint: true, openWorldHint: false },
}, async ({ settings }) => {
  if (!allowWrites) throw new Error("Writes are disabled. Set WP_ALLOW_WRITES=true to enable performance changes.");
  const result = await wp<unknown>("/elementor-mcp/v1/performance/litespeed", {
    method: "PUT",
    body: JSON.stringify({ settings }),
  });
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

server.registerTool("purge_litespeed_cache", {
  title: "Purge LiteSpeed caches",
  description: "Clear caches managed by LiteSpeed Cache, including page and generated CSS/JavaScript caches. Requires WP_ALLOW_WRITES=true and a WordPress user with manage_options.",
  inputSchema: { confirm: z.literal(true) },
  annotations: { readOnlyHint: false, destructiveHint: true, openWorldHint: false },
}, async () => {
  if (!allowWrites) throw new Error("Writes are disabled. Set WP_ALLOW_WRITES=true to enable cache purges.");
  const result = await wp<unknown>("/elementor-mcp/v1/performance/litespeed/purge", {
    method: "POST",
    body: JSON.stringify({ confirm: true }),
  });
  return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
});

await server.connect(new StdioServerTransport());
