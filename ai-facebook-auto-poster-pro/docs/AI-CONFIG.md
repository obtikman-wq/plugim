# AI API Configuration Guide (V3.1 — Multi-Provider)

## Provider priority

The plugin uses a fixed fallback priority:

1. **Google Gemini** — primary (generous free tier)
2. **Groq** — secondary (free tier available)
3. **OpenRouter** — optional (free models available, e.g. `meta-llama/llama-3.3-70b-instruct:free`)
4. **Custom OpenAI-compatible API** — optional
5. **OpenAI** — optional (PAID)

Configure everything on the **AI Settings** tab. Keys are stored encrypted (AES-256-GCM) and never echoed back in full.

| Provider | Key setting | Model setting | Free tier |
|---|---|---|---|
| Gemini | `gemini_key` | `gemini_model` (default `gemini-1.5-flash`) | Yes |
| Groq | `groq_key` | `groq_model` (default `llama-3.3-70b-versatile`) | Yes |
| OpenRouter | `openrouter_key` | `openrouter_model` (default `meta-llama/llama-3.3-70b-instruct:free`) | Free models available |
| Custom | `custom_key` + `custom_base` | `custom_model` | Depends on your endpoint |
| OpenAI | `openai_key` | `openai_model` (default `gpt-4o-mini`) | No — PAID |

## How fallback works

- Every AI operation goes through one entry point (`AI\Manager::call()`).
- A provider is **eligible** only when: enabled in settings, its API key is configured, and it has no active rate-limit cooldown.
- On a provider error or 429 (with `Retry-After` respected), the next **enabled + configured** provider is tried — only when **fallback** is enabled.
- The plugin **never silently switches to a model you did not configure** and never enables a paid provider you did not enable.
- If no eligible provider remains, AI generation pauses with a clear error and the admin is notified (email optional). **Direct WooCommerce posting is completely AI-independent and keeps working.**

## Cost & usage protection

- Per-provider request counters (daily/monthly) and total token tracking.
- Configurable daily/monthly AI request limits (`ai_daily_limit`, `ai_monthly_limit`, 0 = unlimited). When a limit is reached, all AI requests stop until you raise the limit in AI Settings.
- Request timeout (`ai_timeout`, seconds) and retry limit (`ai_retry_limit`).
- Optional usage alert emails (`usage_alerts`).
- Rate-limit cooldowns: a 429 response sets a temporary cooldown for that provider (60–3600 s based on `Retry-After`).

## Connection testing

Each provider row has a **Test** button that sends a tiny request, measures latency, and stores the connection status (shown as ✓/✖ with the tested time).

## AI image generation (separate)

Text providers do not provide free image generation. Image generation is a **separate setting** (`image_provider`):

- `off` (default) — no image API calls ever; the Poster Designer uses its GD template fallback.
- `openai` — uses OpenAI image models (`image_model`, default `gpt-image-1`). **Clearly marked PAID per image** in the UI.

## What AI is used for

All of these use the configured provider priority automatically:

- Bangla + English captions, short/long descriptions, benefits, hashtags, CTA, variations, rewriting
- Product analysis, best product & best time recommendations
- AI marketing calendar content
- Comment assistant & Messenger inbox assistant replies
- Campaign content generation
- Poster design concepts (image rendering itself uses the separate image provider or the GD fallback)

## Direct Mode needs no AI

The Direct Posting Engine builds captions from live WooCommerce data (name, short/long description, regular/sale price, discount %, URL, stock, SKU, category) using 6 content formats or your custom template — zero AI calls. If AI fails and "AI fallback to Direct" is enabled, posts still go out.

## Usage tracking

AI (per provider) and Facebook API call counts are tracked per day/month (Dashboard + AI Settings). Facebook rate limits (codes 4/17/32/613) are detected and retried with backoff.
