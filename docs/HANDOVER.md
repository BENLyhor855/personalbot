# Personal bot handover

## Scope and ownership

Phase 1 is a single-owner, text-only Telegram assistant. Laravel accepts a secret-protected webhook, records accepted updates in MySQL and sends work to a Redis queue. A worker routes conversational requests to an OpenAI-compatible cloud service or a private Ollama endpoint. It has no shell or external-action tools. The owner maintains the VM, credentials, Telegram bot and backups.

## Prerequisites

Provision PHP 8.2 or later with extensions required by Laravel, including mbstring, PDO MySQL and Redis; Composer; MySQL; Redis; Nginx and PHP-FPM. Obtain a domain with valid HTTPS and a Telegram bot token. Ollama and a cloud provider are optional individually, but at least one configured provider must be reachable. The local model must already be installed in Ollama. Keep MySQL, Redis, Ollama and Proxmox management private.

## Installation

Follow the repository README for clone, Composer install, `.env`, migrations, HTTPS, Telegram registration and the worker service. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, and `DB_CONNECTION=mysql`. Put secrets only in `.env` or your secret manager, restrict access to `.env`, and do not commit it. Set `APP_DEBUG=false` in production. After changing environment settings run `php artisan optimize:clear` and restart the worker; cached configuration and running workers do not automatically reload.

For the example systemd unit, replace `/var/www/personalbot`, the PHP executable and service user with your actual paths and user. The service user needs read access to the application and configuration and write access to Laravel's `storage` and `bootstrap/cache`. Install and enable the unit with your system's service-management procedure. Point the Nginx root to the application's `public` directory and set the real certificate, host and PHP-FPM socket in the example configuration.

## Verification and operations

1. Check `php artisan route:list --path=telegram` and `php artisan migrate:status`.
2. Check `systemctl status personal-assistant-telegram-worker` and `journalctl -u personal-assistant-telegram-worker -f`.
3. Register the webhook with `php artisan assistant:telegram-webhook:register` after setting the allowlist and secret. Test `/start`, `/status`, `/model local` or `/model cloud`, a question, and `/clear` in a private chat.
4. Run `php artisan queue:failed` to inspect exhausted jobs. Once the underlying issue is fixed, `php artisan queue:retry <job-id>` retries a specific failed job. Review the consequences before retrying.
5. A crash between recording an update and dispatching the queue job can leave it pending. After confirming workers and Redis are healthy, run `php artisan assistant:telegram-updates:recover` to requeue pending updates older than 10 minutes. `--minutes=N` changes the threshold; the minimum is three minutes. Monitor the command and avoid running concurrent recovery commands.

Updates and raw payloads remain in `telegram_updates`, even after `/clear` deletes the conversation's message history. Define a retention policy and regularly back up the database with encryption and test restores. A backup must also preserve the application key and configuration securely; do not store credentials with public backups. Monitor failed jobs, provider errors, disk usage, webhook delivery and backup success.

## Delivery guarantees

An update ID is stored once and concurrent jobs for that update are locked. The generated answer and transcript are saved together, so a delivery retry reuses the answer. Telegram's send API has no idempotency key: if a request succeeds but the acknowledgement is lost, or a multi-part reply fails partway through, retrying may deliver duplicate text. Multiple different updates can be processed concurrently; strict per-conversation ordering is not guaranteed. Recovery is an operator action, not an automatic scheduler.

## Troubleshooting

- No webhook delivery: verify public HTTPS, the registered URL, no redirect, the webhook secret and Laravel/Nginx logs. Do not share the token-bearing Telegram API URL.
- Request succeeds but no reply: check the owner ID and private chat, Redis, the `telegram` queue worker, `telegram_updates` state and failed jobs.
- AI fails: check selected mode, provider URL, credentials, model availability, VM network access and Ollama's private endpoint. `auto` tries the primary then the other provider; pinned modes do not fall back.
- No local model: inspect `ollama list` on the model host and match `AI_LOCAL_MODEL` exactly.
- Deployment changes do not appear: clear Laravel caches and restart the queue worker.

## Updating or handing over

Pull the reviewed version, install the locked Composer dependencies, back up and test restoration, run migrations, clear caches and restart the worker. Review release changes and verify the smoke tests above. Transfer access to the VM, DNS, bot ownership, cloud provider and encrypted backups through your organization's approved channels. Never send tokens or keys in chat.
