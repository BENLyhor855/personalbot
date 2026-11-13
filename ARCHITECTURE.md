# Architecture and production decisions

```text
Telegram private chat
  -> HTTPS webhook (valid TLS + Telegram secret header)
  -> Laravel API route /api/telegram/webhook
  -> allowlist + update-id dedupe (MySQL)
  -> Redis queue (telegram)
  -> ProcessTelegramUpdate job
      -> command handler (/help, /status, /model, /clear, /whoami)
      -> conversation + message persistence (MySQL)
      -> AssistantService model router
          -> Cloud OpenAI-compatible endpoint
          -> Local Ollama OpenAI-compatible endpoint
      -> Telegram sendMessage API
```

## Service placement on the Proxmox VM

- Nginx + PHP-FPM: public HTTPS application entrypoint (or behind an existing reverse proxy / Cloudflare Tunnel).
- Laravel application: webhook validation, owner authorization, commands, AI router, persistence.
- MySQL: conversation and update storage; use least-privilege database credentials.
- Redis: queue and Laravel cache; bind to loopback/private interface and require authentication where appropriate.
- Ollama: same VM at `127.0.0.1:11434` or another private VM; never expose publicly.
- systemd: keeps the Laravel queue worker alive and restarts it after crashes.

## Phase 1 trust boundaries

1. Only private chats from IDs in `TELEGRAM_ALLOWED_USER_IDS` are accepted.
2. Every webhook request must carry the configured Telegram secret header.
3. Duplicate Telegram update IDs are stored once; queued jobs are unique by update ID.
4. Provider credentials exist only in server environment configuration.
5. LLM output is sent as text only. There are no agent tools or shell execution handlers.
6. Future integrations should be implemented as server-side allowlisted tools with schema validation, per-tool permissions, audit events, and confirmation gates for writes.

## Suggested data model

- `telegram_updates`: unique Telegram update ID, raw JSON payload, processing state, processing timestamp, last error.
- `telegram_conversations`: owner Telegram ID, destination chat ID, selected AI mode, last activity.
- `telegram_messages`: ordered user/assistant transcript, provider and model metadata.

For a longer-running deployment, add a retention job, encrypted backups, monitoring for failed queue jobs, and alerting on repeated provider failures.
