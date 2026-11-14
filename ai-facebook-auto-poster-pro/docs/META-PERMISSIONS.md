# Meta App Configuration & Permissions

## 1. Create the app

1. Go to <https://developers.facebook.com/apps> and click **Create App**.
2. Choose **Business** as the app type.
3. Add the **Facebook Login for Business** product and (for assistants) the **Webhooks** product.
4. Copy the **App ID** and **App Secret** into the plugin's Facebook Connection tab.

## 2. Configure OAuth

1. In the plugin, copy the shown **Redirect URI** (`admin-post.php?action=aifbp_oauth_callback` on your site).
2. In the Meta App: **Facebook Login for Business > Settings > Valid OAuth Redirect URIs** — add that exact URI.
3. Add your domain under **App Domains**.
4. While the app is in **Development mode**, only accounts with a role in the app (admin/developer/tester) can log in. For other admins, submit the app for review.

## 3. Permissions requested by the plugin

The OAuth login URL requests exactly these scopes:

| Permission | Used for | Review required |
|---|---|---|
| `pages_show_list` | Listing the Pages you manage | Yes (Advanced Access) |
| `pages_manage_posts` | Publishing product posts to Pages | Yes |
| `pages_read_engagement` | Reading page posts/insights, posting comments | Yes |
| `pages_manage_engagement` | Replying to comments as the Page (Comment Assistant) | Yes |
| `pages_read_user_content` | Reading user comments on Page posts | Yes |
| `pages_messaging` | Messenger Inbox Assistant (send/receive) | Yes |
| `business_management` | Optional, only for Business Manager setups | Optional |

The plugin **never** asks for personal-profile posting, never touches user passwords, and never bypasses OAuth.

## 4. Webhooks subscription

1. Meta App > **Webhooks** > object **Page**: subscribe to `feed` (comments).
2. Meta App > **Webhooks** > object **Messenger**: subscribe to `messages`, `messaging_postbacks`.
3. Callback URL and verify token: see [WEBHOOKS.md](WEBHOOKS.md).

## 5. App Review notes

- Provide a screencast showing: connecting a Page, an automatic product post appearing on the Page, and the comment assistant replying.
- Explain that data (page tokens, comments, messages) is used solely to operate the user's own marketing automation and is stored encrypted on their own site.
- Messenger replies are only sent inside the standard 24-hour messaging window, as required by Meta Platform Terms.

## 6. API versions

The plugin calls **Graph API v23.0** endpoints (`/me/accounts`, `/{page-id}/photos`, `/{page-id}/feed`, `/{post-id}/insights`, `/{comment-id}/comments`, `/me/messages`). Update the version constant in `includes/Facebook/Client.php` when Meta deprecates versions.
