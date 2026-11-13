# Personal AI Assistant Bot — Phase 1

A single-owner Telegram assistant starter pack for a fresh Laravel 12 application.

## Phase 1 scope

- Telegram private-chat webhook with shared-secret verification.
- Telegram numeric user ID allowlist (fail closed when empty).
- Durable webhook update deduplication.
- MySQL conversation and message history.
- Redis-backed queued message processing.
- Cloud/local AI selection through one OpenAI-compatible Chat Completions client.
- Commands: `/start`, `/help`, `/whoami`, `/status`, `/model auto|cloud|local`, `/clear`.
- Text messages only. No shell execution, no arbitrary tools, no Gmail/Calendar/finance/network write access.

## 1. Install the application

Clone this repository and install dependencies with PHP 8.2+ and Composer:

```bash
git clone https://github.com/BENLyhor855/personalbot.git
cd personalbot
composer install
cp .env.example .env
php artisan key:generate
```

Install the PHP Redis extension and configure MySQL and Redis. The API route is registered at `/api/telegram/webhook`.

## 3. Configure environment

Generate an application key if you have not already done so:

```bash
php artisan key:generate
```

Set the actual Telegram bot token, webhook secret, public webhook URL, owner Telegram numeric user ID, MySQL credentials, Redis connection, and AI provider credentials. Never commit `.env` or send the bot token/API keys in chat.

### Find your Telegram numeric user ID before enabling the webhook

1. Create a bot with Telegram's `@BotFather` and copy its token privately.
2. Send `/start` to your new bot.
3. Before setting the webhook, inspect updates locally from the Ubuntu VM:

```bash
curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getUpdates"
```

4. Read `message.from.id` from the JSON and put that number in `TELEGRAM_ALLOWED_USER_IDS`.
5. Do not paste the URL or response into a public place; the URL contains your bot token.
6. After setting the webhook, Telegram's `getUpdates` long-polling method cannot be used until the webhook is removed.

If updates are empty, send another `/start` message and retry. Use a secret manager or a root-readable `.env` file for credentials.

## 4. Database, queue, and application setup

Recommended `.env` values:

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=info
DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
```

Then:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan queue:work redis --queue=telegram --sleep=1 --tries=3 --timeout=120
```

For production, run the queue worker under systemd using `deploy/personal-assistant-telegram-worker.service` (edit paths and user first). Do not keep the worker in an SSH terminal.

## 5. Configure public HTTPS

Telegram webhook delivery requires HTTPS. Use your existing reverse proxy with a valid certificate, or a Cloudflare Tunnel pointing to the Laravel/Nginx origin. The public webhook URL must not redirect. Telegram currently supports webhook ports 443, 80, 88, and 8443. Keep the Laravel app private behind the proxy/tunnel; do not expose MySQL, Redis, Ollama, or the Proxmox management interface.

Use `deploy/nginx.conf.example` as a starting point if Nginx serves the Laravel app. Adjust the PHP-FPM socket and certificate paths for your VM.

## 6. Register the Telegram webhook

Set `TELEGRAM_WEBHOOK_URL` in `.env`, then run:

```bash
php artisan assistant:telegram-webhook:register
```

Only after the owner ID is configured. The command registers only `message` updates and uses Telegram's `secret_token` feature. To intentionally discard Telegram's pending updates, add `--drop-pending`; do not use this casually.

## 7. Configure the hybrid AI router

The starter uses an OpenAI-compatible Chat Completions endpoint for both providers. This keeps the integration small and supports OpenAI-compatible cloud services and local Ollama.

Example cloud provider (OpenAI):

```dotenv
AI_CLOUD_BASE_URL=https://api.openai.com/v1
AI_CLOUD_API_KEY=replace_me
AI_CLOUD_MODEL=replace_with_a_model_available_to_your_API_project
```

Example local Ollama:

```dotenv
AI_LOCAL_BASE_URL=http://127.0.0.1:11434/v1
AI_LOCAL_API_KEY=ollama
AI_LOCAL_MODEL=qwen3:8b
```

The model name must match a model installed in your Ollama instance. Confirm local inference first:

```bash
ollama list
curl http://127.0.0.1:11434/api/tags
curl http://127.0.0.1:11434/v1/chat/completions \
  -H 'Content-Type: application/json' \
  -d '{"model":"qwen3:8b","messages":[{"role":"user","content":"Reply with OK"}],"stream":false}'
```

If Ollama runs on another VM, use its private LAN address and firewall it so only the assistant VM can reach TCP/11434. Do not publish port 11434 to the Internet. If the VM cannot run an 8B model comfortably, choose a smaller model in `.env`.

`/model auto` uses `AI_PRIMARY_DRIVER` and falls back to the other provider after a provider error. `/model cloud` and `/model local` pin the conversation to that provider without fallback.

## 8. Smoke test

1. Start Nginx/PHP-FPM and the systemd queue worker.
2. Register the webhook.
3. Send `/start` in Telegram.
4. Send `/status`, `/model local`, then a simple question.
5. Check `storage/logs/laravel.log`, the `telegram_updates` table, and the `telegram_messages` table if a reply is not delivered.
6. Test `/clear` and verify the conversation's messages are removed.

Useful checks:

```bash
php artisan route:list --path=telegram
php artisan assistant:telegram-webhook:register
sudo systemctl status personal-assistant-telegram-worker
sudo journalctl -u personal-assistant-telegram-worker -f
```

## Security and operations checklist

- [ ] `APP_DEBUG=false` and production `APP_KEY` is set.
- [ ] `.env` permissions are restricted (for example `chmod 600 .env`).
- [ ] `TELEGRAM_ALLOWED_USER_IDS` contains only your numeric Telegram user ID(s).
- [ ] `TELEGRAM_WEBHOOK_SECRET` is long, random, and only uses letters, numbers, `_`, `-`.
- [ ] Only HTTPS webhook traffic is public; MySQL/Redis/Ollama remain private.
- [ ] Queue worker runs as a non-root service account.
- [ ] Database backups are enabled and tested.
- [ ] Logs are rotated and do not contain bot tokens or API keys.
- [ ] Cloud and local provider costs/availability are monitored.

## Phase 1 limitations

- Text-only messages (voice, images, files, and inline buttons are future work).
- Single-owner authorization; no multi-user tenant model.
- No autonomous actions or tool calling. All messages are plain conversational AI requests.
- Message retention is not automatically expired. Define a retention policy and backup policy before storing sensitive conversations.

## Suggested next phases

- Phase 2: personal notes, reminders, and task CRUD with confirmation messages.
- Phase 3: Google Calendar and Gmail read-only tools, then explicit approval for create/send actions.
- Phase 4: finance ledger and monthly reports.
- Phase 5: read-only Meraki/UniFi/Palo Alto/Synology monitoring, followed by narrowly scoped approved actions.

## Delivery and retry limitations

Delivery retries reuse a saved response and do not repeat transcript inserts. Telegram may still deliver duplicate text if a request succeeds but its acknowledgement is lost, or if a multi-part response partially succeeds. A crash between saving an update and dispatching its job can leave an update pending; see the recovery command in the handover guide. End-to-end delivery requires your own Telegram token, HTTPS endpoint, Redis, MySQL, and AI provider.

## Handover

See [docs/HANDOVER.md](docs/HANDOVER.md) for deployment, operation, recovery, backups and troubleshooting.
