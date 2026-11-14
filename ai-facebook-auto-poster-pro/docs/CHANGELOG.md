# Changelog

## 3.1.2 — Critical fixes (external review)

- **Fix (critical):** PHP fatal error in `includes/Admin/AdminUI.php` — the `$redirect` closure declared `$tab` both as a parameter and in the `use` clause ("Cannot use lexical variable as a parameter name"). The `use ($tab)` clause was removed; the parameter is used. The plugin now loads without fatal errors.
- **Fix:** OAuth scopes now include the permissions the features actually need: `pages_manage_engagement` (comment replies), `pages_read_user_content` (reading comments), `pages_messaging` (Messenger inbox) and `pages_manage_metadata` (webhook subscriptions). Previously only `pages_show_list, pages_manage_posts, pages_read_engagement, business_management` were requested, which would have blocked the assistants after authorization.
- **Change:** Graph API bumped from **v21.0 → v23.0** (supported until October 8, 2027). v24.0 was deliberately skipped because it removes the `conversation` object from message-status webhooks, which would break the Inbox Assistant.
- Re-scanned every closure in the codebase for the same parameter/`use` conflict: no other occurrences.

## 3.1.1 — Admin footer: WhatsApp support + credit

- **WhatsApp Support button** in the plugin admin dashboard footer — official-style green gradient button with WhatsApp icon, opens `https://wa.me/8801618576564` in a new tab, fully responsive (mobile/desktop).
- **"Made by Ta Organization" footer credit** displayed on every plugin admin tab.
- Both render only inside the plugin's own admin dashboard (capability-checked, escaped, external link via `esc_url` with `rel="noopener noreferrer"`) — never on the frontend, and they do not interfere with other admin UI or plugins.

## 3.1.0 — Multi-Provider Free-Tier AI + Centralized Facebook Authentication

### New: multi-provider AI system
- Provider priority: **Gemini → Groq → OpenRouter → Custom → OpenAI** (free-tier providers first).
- New **Groq** and **OpenRouter** providers (OpenAI-compatible endpoints, free-tier models).
- Per-provider enable/disable, model selection, encrypted keys, connection **Test** button, connection status with last-tested time, daily/monthly usage per provider.
- Automatic 429 rate-limit handling with `Retry-After` cooldowns (60–3600 s), retry limits, configurable timeout.
- Automatic fallback between **enabled + configured** providers only — never silently switches to a model you did not select, never enables a paid provider without approval.
- Usage & cost protection: request counters, token tracking, daily/monthly limits that **pause AI** until the admin raises them, optional alert emails, error log panel.
- **AI image generation is a separate provider setting** (`off` by default, clearly marked PAID) — text providers are never assumed to generate images.

### New: centralized Facebook authentication
- One-time OAuth login on the **Facebook Connection** tab powers every module (AI posting, Direct posting, scheduler, calendar, poster publishing, comment assistant, inbox assistant, analytics, best time/product, queue, history) — no per-module logins or manual tokens.
- Default page setting, token validity display, expiry monitoring.
- Automatic detection of revoked/expired authorization (Graph codes 190/102/10): affected operations pause safely, the queue stops posting, an admin notice appears, and reconnecting clears the state.
- Granted vs missing permissions panel with a permission → feature mapping table and App Review guidance.

### Unchanged guarantees
- Direct WooCommerce auto posting works with **zero AI configuration**.
- Facebook authentication and AI API authentication are completely separate.
- All V3.0 features preserved; data-migrating upgrade (no data loss).

## 3.0.0 — Ultimate Master Upgrade

### New: two independent posting engines
- **Direct Posting Engine** (`includes/Direct/Engine.php`) — builds Facebook captions from live WooCommerce data with 6 content formats + custom template variables. Works with zero AI configuration.
- **AI Posting Engine** — OpenAI / Gemini / custom OpenAI-compatible providers, Bangla + English, variations, rewriting.
- Three posting modes (AI / Direct / Both), per-schedule mode, and AI→Direct fallback so posting continues when AI fails.

### New: Facebook auto connection
- OAuth login, automatic page discovery with profile images, multi-select pages, token expiry monitoring, refresh page list, reconnect/disconnect, connection status.

### New: advanced scheduler
- Multiple times per day, weekly days, random time windows, per-schedule pages/categories/selection/mode/template, bulk scheduling, pause/resume, queue locking, retry with backoff, cron health dashboard.

### New: product rotation
- 7 selection methods, per-page/schedule repeat prevention, out-of-stock skipping, sale preference, rotation history table.

### New: AI Comment & Inbox assistants
- Webhooks with `X-Hub-Signature-256` verification + polling fallback, intent detection (price/stock/purchase/complaint), product matching, auto/manual replies, human handoff, moderation-friendly prompts (never claims orders/payments confirmed).

### New: insights & recommendations
- Best posting hour/day from real Meta insights (with explicit insufficient-data state), best-product scoring from WooCommerce sales + Facebook engagement.

### New: product poster designer
- 1080x1080 / 1080x1350 / 1080x1920, 3 templates, brand colors, discount badge, CTA, non-AI GD rendering, optional AI image generation, save to media library, auto-attach to posts.

### New: marketing calendar
- AI/direct calendar generation (9 content types), month/week/day views, pause/resume/delete/publish per item.

### New: content management & analytics
- Queue dashboard with edit caption, change image/product, duplicate, reschedule, publish now, mode pills everywhere.
- Analytics with mode statistics, assistant activity, daily/weekly/monthly reports, CSV export.

### Platform
- DB schema v3 (queue mode/content_type, schedules v3 fields, rotation, comments, conversations, analytics mode) with safe dbDelta migration preserving V2 data.
- REST API additions: `/calendar`, `/poster`, direct-mode `/preview`, mode params on `/schedules` and `/bulk`.
- 17-item admin navigation, modern responsive UI.
- Security: webhook signature verification, queue locking, rate-limit handling, data retention, encrypted secrets, nonces/capability checks everywhere.

## 2.0.0
- Modular OOP rewrite (AIFB namespace), WooCommerce integration, queue + schedules + analytics, 8-tab admin UI, REST API, encrypted secrets.

## 1.0.0
- Initial release: Facebook OAuth, AI captions, daily posting, queue, history.
