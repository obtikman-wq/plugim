# AI API Configuration Guide

## Providers

Configure on the **AI Settings** tab. Keys are stored encrypted (AES-256-GCM) and never echoed back in full.

| Provider | Fields | Notes |
|---|---|---|
| OpenAI | `openai_key`, `openai_model` (default `gpt-4o-mini`), optional `openai_base` | Official `https://api.openai.com/v1/chat/completions` |
| Google Gemini | `gemini_key`, `gemini_model` (default `gemini-1.5-flash`) | Official `generativelanguage.googleapis.com` generateContent |
| Custom | `custom_base`, `custom_key`, `custom_model` | Any OpenAI-compatible `/chat/completions` endpoint |

The assistant can optionally use a different provider (`assistant_provider` on the Comment Assistant tab).

## What AI is used for

- Bangla + English short/long captions, benefits, hashtags, CTA, variations, rewriting
- Comment & Messenger reply generation (with your business instructions and restricted topics)
- Optional AI image generation (`ai_image_gen`) and image understanding (`ai_image_understand`)

## Direct Mode needs no AI

The Direct Posting Engine builds captions from live WooCommerce data (name, short/long description, regular/sale price, discount %, URL, stock, SKU, category) using 6 content formats or your custom template. If AI fails and "AI fallback to Direct" is enabled, posts still go out.

## Usage tracking

AI and Facebook API call counts are tracked per day/month (Dashboard + Analytics). Rate limits from Meta (codes 4/17/32/613) are detected and retried with backoff instead of hammering the API.
