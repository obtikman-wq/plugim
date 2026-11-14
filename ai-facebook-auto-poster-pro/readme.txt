=== AI Facebook Product Auto Poster Pro ===
Contributors: aifbp
Tags: facebook, woocommerce, auto post, ai, scheduler, marketing
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 3.1.1
License: GPL-2.0-or-later

AI-powered Facebook marketing automation platform for WooCommerce: scheduled posting with AI or completely without AI (Direct Mode), product rotation, comment and Messenger assistants, poster designer, marketing calendar, best-time recommendations, and analytics.

== Description ==

* Two independent posting engines: **AI Mode** (OpenAI / Gemini / custom OpenAI-compatible API) and **Direct Mode** built purely from live WooCommerce product data — no AI API required. Both Mode runs both engines with separate schedules; if AI fails, Direct keeps posting.
* Direct content formats: short description, long description, short+long, name+description+price, name+image+price+link, or a custom template with variables ({product_name}, {regular_price}, {sale_price}, {discount_percent}, {product_url}, {stock_status}, {sku}, {category}, {hashtags}, {cta}, …).
* Facebook OAuth (official Graph API v21.0): automatic page discovery with profile images, multi-select pages, encrypted page tokens (AES-256-GCM), token expiry monitoring, refresh/reconnect/disconnect.
* Advanced scheduler: fixed times, multiple times per day, weekly days, random time windows, campaigns, bulk scheduling, per-schedule pages/categories/selection/mode/template, pause/resume, queue locking, retry with backoff, cron health display.
* Product rotation: random, latest, best selling, sale first, featured, category based, manual — with repeat prevention, out-of-stock skipping, and per-page history.
* AI Comment Assistant + Messenger Inbox Assistant: webhooks (X-Hub-Signature-256 verified) or polling, intent detection, WooCommerce product matching, auto or approval-based replies, human handoff. Never claims an order or payment is confirmed.
* Best Time & Best Product recommendations from real Meta insights and WooCommerce sales — with a clear "insufficient data" state; nothing is fabricated.
* Product Poster Designer: 1080x1080, 1080x1350, 1080x1920; templates, brand colors, discount badge, CTA; non-AI GD rendering, optional AI image generation, save to media library.
* Marketing Calendar: AI or direct generation of campaigns (9 content types), month/week/day views, pause/resume/delete/publish.
* Content management: edit caption, change image, change product, duplicate, reschedule, publish now, mode labels on every post.
* Analytics: totals, AI vs Direct statistics, assistant activity, daily/weekly/monthly reports, CSV export.
* 17-item admin navigation, modern responsive UI, REST API (aifbp/v1), Action Scheduler + WP-Cron fallback, data retention, uninstall cleanup, translation-ready.

== Installation ==

1. Upload the ZIP via Plugins > Add New > Upload Plugin and activate.
2. Open "FB Auto Poster Pro" in the admin menu.
3. See the docs/ folder (SETUP.md, META-PERMISSIONS.md, AI-CONFIG.md, WEBHOOKS.md, TESTING.md, LIMITATIONS.md, CHANGELOG.md).

== Facebook setup ==

1. Create a Business app at https://developers.facebook.com/ (add Facebook Login for Business; add Webhooks for the assistants).
2. Add the redirect URI shown on the Connection tab as a Valid OAuth Redirect URI.
3. Save App ID + App Secret, click "Connect Facebook account", select your pages.
4. Permissions: pages_show_list, pages_manage_posts, pages_read_engagement, pages_manage_engagement, pages_read_user_content, pages_messaging. See docs/META-PERMISSIONS.md for App Review guidance.

== AI setup ==

* OpenAI / Gemini / custom OpenAI-compatible endpoint keys on the AI Settings tab (stored encrypted).
* Direct Mode works with no AI keys at all.

== Requirements ==

* PHP 8.1+ with OpenSSL; GD for the poster designer; WooCommerce for product posting.
* Action Scheduler (bundled with WooCommerce) or a real cron job for reliable scheduling.

== Changelog ==

= 3.1.1 =
* New: WhatsApp Support button in the admin dashboard footer (opens wa.me chat, official green style, responsive).
* New: "Made by Ta Organization" footer credit shown on every plugin admin tab (plugin dashboard only, never on the frontend).


= 3.1.0 =
* New: Multi-provider AI system with free-tier priority — Gemini → Groq → OpenRouter → Custom → OpenAI.
* New: Per-provider enable/disable, model selection, connection test, status, daily/monthly usage.
* New: Automatic rate-limit handling (Retry-After), retry + fallback between enabled providers only — never silently switches to a paid model.
* New: Usage & cost protection — request counters, token tracking, daily/monthly limits with pause + admin alerts, timeout and retry limits.
* New: Separate AI image generation provider setting (off by default, clearly marked PAID).
* New: Centralized Facebook authentication — one-time login powers every module; no per-module tokens.
* New: Default page setting, token validity display, reconnect banner, granted/missing permissions with feature mapping.
* Fix: Queue pauses safely when Facebook authorization is invalid; Direct WooCommerce posting remains fully AI-independent.


= 3.0.0 =
* Ultimate upgrade: Direct (no-AI) posting engine + AI engine with three posting modes and AI→Direct fallback.
* Facebook auto connection with page images and multi-select; token monitoring.
* Advanced scheduler (multi-times, windows, campaigns, bulk), product rotation, comment + inbox assistants with webhooks, poster designer, marketing calendar, best time/product insights, content management, advanced analytics with CSV export, 17-tab admin UI, DB schema v3 migration.

= 2.0.0 =
* Initial Pro release.

= 1.0.0 =
* Initial release.
