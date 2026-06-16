#!/usr/bin/env node
/**
 * Titan MCP server.
 *
 * Exposes a user's whole Titan health account to an MCP client (e.g. Claude) as native tools.
 * It does NOT hard-code the tool list — on startup it fetches the catalog from `GET /api/tools`
 * and turns each entry into an MCP tool that calls `POST /api/tool`. Add a tool server-side in
 * AssistantTools and it appears here automatically; no MCP changes needed.
 *
 * Auth: a personal API token the user generates at /connect on their Titan instance.
 *
 * Env:
 *   TITAN_API    base API url, e.g. https://app.example.com/api   (required)
 *   TITAN_TOKEN  the user's personal API token (Bearer)           (required)
 */
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  type Tool,
} from "@modelcontextprotocol/sdk/types.js";

const API = (process.env.TITAN_API || "").replace(/\/$/, "");
const TOKEN = process.env.TITAN_TOKEN || "";

if (!API || !TOKEN) {
  console.error("titan-mcp: set TITAN_API and TITAN_TOKEN environment variables.");
  process.exit(1);
}

const headers = {
  Authorization: `Bearer ${TOKEN}`,
  "Content-Type": "application/json",
  Accept: "application/json",
};

type Catalog = { name: string; description: string; args: Record<string, string> | unknown[]; write?: boolean };

/** Turn the API's loose arg descriptions into a JSON-Schema `inputSchema` for MCP. */
function inputSchema(args: Catalog["args"]): Tool["inputSchema"] {
  const properties: Record<string, object> = {};
  if (args && !Array.isArray(args)) {
    for (const [key, desc] of Object.entries(args)) {
      const d = String(desc).toLowerCase();
      const type = /\bint\b|\bnumber\b|\bnumeric\b/.test(d) ? "number" : "string";
      properties[key] = { type, description: String(desc) };
    }
  }
  return { type: "object", properties, additionalProperties: true };
}

async function fetchCatalog(): Promise<Catalog[]> {
  const res = await fetch(`${API}/tools`, { headers });
  if (!res.ok) {
    throw new Error(`GET /tools failed (${res.status}). Check TITAN_API and TITAN_TOKEN.`);
  }
  const body = (await res.json()) as { tools: Catalog[] };
  return body.tools ?? [];
}

async function callTool(tool: string, args: Record<string, unknown>): Promise<unknown> {
  const res = await fetch(`${API}/tool`, {
    method: "POST",
    headers,
    body: JSON.stringify({ tool, args }),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    const b = body as { error?: string; message?: string };
    throw new Error(b.error || b.message || `tool ${tool} failed (${res.status})`);
  }
  return (body as { result?: unknown }).result ?? body;
}

async function main() {
  const catalog = await fetchCatalog();

  const server = new Server(
    { name: "titan", version: "0.1.0" },
    { capabilities: { tools: {} } }
  );

  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: catalog.map((t): Tool => ({
      name: t.name,
      description: t.write ? `${t.description} (writes data)` : t.description,
      inputSchema: inputSchema(t.args),
    })),
  }));

  server.setRequestHandler(CallToolRequestSchema, async (req) => {
    const { name, arguments: args } = req.params;
    try {
      const result = await callTool(name, (args as Record<string, unknown>) ?? {});
      return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Error: ${(err as Error).message}` }],
        isError: true,
      };
    }
  });

  await server.connect(new StdioServerTransport());
  console.error(`titan-mcp: connected · ${catalog.length} tools from ${API}`);
}

main().catch((err) => {
  console.error("titan-mcp failed to start:", (err as Error).message);
  process.exit(1);
});
