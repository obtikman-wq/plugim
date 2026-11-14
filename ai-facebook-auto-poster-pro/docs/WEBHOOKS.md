# Webhook Configuration Guide

## Endpoint

```
https://your-site.com/wp-json/aifbp/v1/webhook
```

Shown on the Comment Assistant, Inbox Assistant, Tools, and System Status tabs.

## Verification (GET)

Meta sends `hub.mode=subscribe`, `hub.verify_token`, `hub.challenge`. The plugin responds with the challenge **only** when `hub.verify_token` matches the **Webhook verify token** saved in the plugin (compared with `hash_equals`). Set any random string in both places.

## Event verification (POST)

Every POST is verified against the `X-Hub-Signature-256` header:

- Signature must be `sha256=<hex>` of the raw request body, HMAC-SHA256 keyed with your **App Secret**.
- Mismatching or missing signatures are rejected with `401`.
- If no App Secret is configured, payloads are rejected (never processed unsigned).

## Subscribing

| Object | Fields | Feeds into |
|---|---|---|
| Page | `feed` | Comment Assistant (`includes/Assistants/Comments.php`) |
| Messenger | `messages`, `messaging_postbacks` | Inbox Assistant (`includes/Assistants/Inbox.php`) |

## Fallback polling

If webhooks are not yet approved, enable the Comment Assistant and it polls recent posts' comments every 5 minutes (WP-Cron/Action Scheduler). Duplicate comments are prevented by the unique `comment_id` column.

## Privacy & safety rules baked into the assistants

- Human handoff: switching a conversation to "Take over" stops all AI replies until you resume.
- The assistant never states that an order or payment is confirmed unless verified in WooCommerce.
- With the privacy option enabled, only product titles/prices are sent to the AI provider — never customer names or message text.
