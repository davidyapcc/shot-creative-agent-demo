# Shot Creative Agent Demo

A brief is planned into shots, rendered, and judged. `docker compose up` runs that pipeline on Replay and Mock providers, with no API keys.

## MCP

A local stdio server exposes the same brief pipeline to an MCP client. The tools return as soon as the work is queued, so the client never waits on a render. The server makes no provider calls and needs no API keys.

Start the stack, then point the client at:

```bash
docker compose exec -T app php artisan mcp:start briefs
```

`-T` keeps the session on stdio. Claude Desktop and Cursor launch the process themselves; set the project directory so Compose can find this repo:

```json
{
  "mcpServers": {
    "shot-creative-agent": {
      "command": "docker",
      "args": [
        "compose",
        "--project-directory",
        "/absolute/path/to/shot-creative-agent-demo",
        "exec",
        "-T",
        "app",
        "php",
        "artisan",
        "mcp:start",
        "briefs"
      ]
    }
  }
}
```

Tools:

- `submit_brief` — `prompt` (3–1000 characters). Creates a pending brief, enqueues planning, and returns the brief id and state.
- `get_brief_status` — `brief_id`. Returns the brief state, its final video path, and each shot's sequence, state, attempts, and latest verdict.
