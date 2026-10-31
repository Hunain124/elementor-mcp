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

const server = new McpServer({ name: "wordpress-elementor", version: "0.1.0" }, {
  instructions: "Use list_pages or get_elementor_page before editing. Elementor layouts are recursive JSON. Updates are disabled unless WP_ALLOW_WRITES=true; keep existing elements unless the user explicitly asks to replace the layout.",
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

await server.connect(new StdioServerTransport());
