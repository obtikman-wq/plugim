# Testing Report

Honest scope: this project was developed in a sandbox **without a WordPress/PHP runtime and without live Meta credentials**. Below is exactly what was and was not verified.

## 1. Automated checks (run in sandbox) ✅

- **PHP structural validation** — all 37 PHP files pass balanced-token validation (braces/brackets/parens, comment/string-aware).
- **JavaScript** — `assets/js/admin.js` passes `node --check`.
- **Cross-checks** — every admin form action/nonce name matches a handler in `AdminUI::handle_actions()`; every `Settings` key referenced in the UI exists in the defaults list; REST routes reference existing class methods with matching signatures.

## 2. Sandbox tests NOT possible ❌

No PHP runtime, no WordPress, no WooCommerce, no network Meta access in this environment. Therefore these were **not executed** and must be verified on a staging site:

- Plugin activation/deactivation and `dbDelta` migration on a real database
- Live Facebook OAuth login and page token exchange
- Actual posting to a Facebook Page (photos/feed)
- Live AI provider calls (OpenAI/Gemini/custom)
- Webhook delivery from Meta and signature verification with a real App Secret
- Messenger send API within a real messaging window

**No fake API responses were used or generated as proof.** All Facebook/AI integrations call the official endpoints only.

## 3. Staging test checklist (24 scenarios)

1. Fresh install on WP + WooCommerce → tables created, no PHP errors in debug.log
2. Upgrade V2 → V3 → settings/pages/queue/history preserved
3. OAuth connect with a Meta app in Development mode
4. Multi-page selection saved and honored by schedules
5. AI posting with OpenAI (and Gemini) — caption language Bangla + English
6. Direct posting with AI keys removed entirely
7. Direct format = short description only
8. Direct format = long description only
9. Fixed-time schedule fires at the configured time
10. Multiple times per day (e.g. 10:00, 14:00, 20:00)
11. Rotation avoids recently posted products (repeat interval respected)
12. `sale_first` selection promotes sale products
13. Out-of-stock products excluded when the option is on
14. Poster generation (all 3 sizes; Bengali text only with a TTF font installed)
15. Marketing calendar generation (AI + direct) and month navigation
16. Comment assistant: poll → intent detection → suggested reply → approve & send
17. Inbox assistant: webhook message → auto reply → human handoff stops AI
18. Queue retry on simulated failure (backoff, max attempts)
19. Duplicate prevention (same product/page while an active queue row exists)
20. DB migration: schema version bump on admin load
21. Security: webhooks unsigned → 401; nonces on every admin form; capability check for non-admins
22. Mobile admin UI at 375px width
23. CSV exports (history + analytics)
24. Cron health page with `DISABLE_WP_CRON` set

## 4. Live Meta integration tests (after App Review)

- Publish a post as Page → verify permalink in Post History
- Verify `/insights` returns metrics and the Analytics tab populates
- Comment webhook → assistant reply as Page
- Messenger webhook → assistant reply inside 24h window
