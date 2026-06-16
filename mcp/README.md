# Titan MCP

Run your whole **Titan** health account from Claude (or any [MCP](https://modelcontextprotocol.io) client).
Read your recovery, sleep, fitness, steps and metabolic health; log workouts, weight and sleep; pair your
wearable — all in natural language, no website needed.

The server is **catalog-driven**: it fetches the tool list from your Titan instance at startup
(`GET /api/tools`) and exposes each as an MCP tool, so new server-side tools appear automatically.

## Setup

1. **Generate a token.** In Titan, open **Connect** (`/connect`), create a token, copy it (shown once).
2. **Build the server:**
   ```bash
   cd mcp
   npm install
   npm run build
   ```
3. **Wire it into Claude** (`claude_desktop_config.json`, or Claude Code's MCP config):
   ```json
   {
     "mcpServers": {
       "titan": {
         "command": "node",
         "args": ["/absolute/path/to/fitness-ai/mcp/dist/index.js"],
         "env": {
           "TITAN_API": "https://your-titan-host/api",
           "TITAN_TOKEN": "titan_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
         }
       }
     }
   }
   ```
4. Restart Claude. You'll see Titan tools available — try *"what's my readiness today?"* or
   *"log last night: 7h20m, bed 11:15pm, woke 6:35am."*

## Environment

| Var | Required | Example |
|---|---|---|
| `TITAN_API` | yes | `https://app.titan.health/api` |
| `TITAN_TOKEN` | yes | a personal API token from `/connect` |

## Tools (current catalog)

**Read** — `get_today`, `get_recovery`, `get_sleep`, `get_fitness`, `get_activity`, `get_workouts`,
`get_biomarkers`, `get_meals`, `get_profile`, `get_devices`.

**Write** — `log_sleep`, `log_steps`, `log_recovery`, `log_weight`, `log_workout`, `set_goal`,
`pair_device`.

A **read-only** token (chosen at creation) can call the reads but is blocked from the writes with a 403.

## Direct HTTP (no MCP)

Any agent can skip MCP and call the API directly:

```bash
curl https://your-titan-host/api/tool \
  -H "Authorization: Bearer $TITAN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"tool":"log_steps","args":{"steps":9200}}'
```

## Security

The token is a bearer credential — treat it like a password. Revoke any token anytime from
**Connect** in Titan; the agent loses access immediately.
