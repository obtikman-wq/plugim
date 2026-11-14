# Known Limitations

1. **Bengali text in posters** — the GD fallback renders text with the built-in bitmap font (ASCII only) unless you provide a Bengali-capable TTF (e.g. Noto Sans Bengali) via the Poster Designer tab (`poster_font_path` or `uploads/aifbp/poster.ttf`). Bengali will not render correctly without a TTF.
2. **Meta App Review** — Page posting, engagement, messaging, and user-content permissions require Advanced Access approval. In Development mode only app-role accounts can connect.
3. **Messenger 24-hour window** — replies are only possible within Meta's standard messaging window after a user message; this is a Meta platform rule, not a plugin bug.
4. **Insights availability** — Page post insights can lag or be restricted; the Best Time engine shows "insufficient data" until at least 5 posts have real engagement metrics. Nothing is fabricated.
5. **AI image generation** — only used when a provider supporting it is configured; otherwise the non-AI GD designer is used. AI-generated posters may not exactly reproduce the original product photo identity.
6. **Webhook delivery needs a public HTTPS site** — localhost/staging behind auth cannot receive Meta webhooks; use the polling fallback.
7. **Queue throughput** — WP-Cron depends on site traffic; without Action Scheduler or a real cron, posting times can drift.
8. **Direct mode plaintext** — Facebook captions are plain text; HTML in descriptions is stripped before posting.
9. **Single site scope** — no multisite-network admin; activate per site.
10. **Translation-ready but not translated** — all strings use the `aifbp` text domain; ship your own .po/.mo.
