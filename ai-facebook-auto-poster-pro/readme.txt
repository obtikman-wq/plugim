=== AI Facebook Product Auto Poster Pro ===
Contributors: aifbp
Tags: facebook, woocommerce, auto post, ai, scheduler
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPL-2.0-or-later

Automatically posts WooCommerce products to your Facebook Pages with AI-generated Bangla and English captions, hashtags, CTA, scheduling, queue, retries and analytics.

== Description ==

* Facebook OAuth login (official Graph API, v21.0) and automatic Page selection with secure encrypted Page Access Tokens.
* Multiple Facebook Pages support.
* Random product + random product image selection from WooCommerce (featured or gallery images).
* AI captions in Bangla, English or both: short caption, long description, product-specific hashtags and call-to-action.
* Accurate regular price and sale price in every post.
* AI providers: OpenAI, Google Gemini, and any custom OpenAI-compatible endpoint.
* AI image understanding (vision), AI promotional image generation (OpenAI), automatic price badge overlay (GD).
* Multiple caption variations and AI caption rewriting.
* Daily, weekly and campaign (date range) schedules, bulk scheduling, smart posting intervals and posting frequency control.
* Database-backed posting queue with statuses, background processing via Action Scheduler (WP-Cron fallback), duplicate prevention and automatic retries.
* Manual publish button, AI post preview, post history, system logs, scheduled post calendar.
* Post analytics dashboard (impressions, clicks, reactions, comments) via Facebook Page Insights.
* API usage tracking, error notifications and token expiry notifications.
* WordPress coding standards, capability checks, nonce verification, sanitization/escaping, encrypted secret storage, translation-ready, uninstall cleanup.

== Installation ==

1. Upload the plugin folder or the ZIP via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open "FB Auto Poster Pro" in the admin menu.

== Facebook setup ==

1. Create a Business app at https://developers.facebook.com/.
2. Add the products: Facebook Login for Business.
3. In Facebook Login settings, add this as a Valid OAuth Redirect URI (shown on the Connection tab):
   https://yoursite.com/wp-admin/admin-post.php?action=aifbp_oauth_callback
4. Request the permissions: pages_show_list, pages_manage_posts, pages_read_engagement, business_management.
5. On the Connection tab save your App ID and App Secret, then click "Connect Facebook account" and select your pages.
6. Use the "Test page token" button to verify.

== AI setup ==

* OpenAI: set the API key (platform.openai.com), optionally a custom base URL and model.
* Gemini: set the API key (aistudio.google.com), model e.g. gemini-2.0-flash.
* Custom: any OpenAI-compatible endpoint (base URL + key + model).

== Requirements ==

* PHP 8.1+, MySQL/MariaDB, WooCommerce for product mode.
* The OpenSSL PHP extension (for encrypted secret storage) and the GD extension (optional, for price badges).
* A real cron job is recommended: wp-cron is used when Action Scheduler is not present.

== Changelog ==

= 2.0.0 =
* Initial Pro release.
