# socialbored

A first-party social-network layer for [bulletinbored](https://github.com/bulletinbored/bulletinbored-core).

It turns a bulletinbored installation into a small social network: a feed, profiles, follows,
reactions, reposts, bookmarks, hashtags, reports and notifications. The classic forum remains
available at all times.

## Screenshots

| Feed | Post |
| :---: | :---: |
| ![Social feed](https://blog.bulletinbored.net/images/socialbored/socialbored-feed.png) | ![Post detail](https://blog.bulletinbored.net/images/socialbored/socialbored-post.png) |

| Profile | Direct messages |
| :---: | :---: |
| ![Social profile](https://blog.bulletinbored.net/images/socialbored/socialbored-profile.png) | ![Direct messages](https://blog.bulletinbored.net/images/socialbored/socialbored-messages.png) |

## Requirements

- bulletinbored core `>= 1.2.0` (core thread/profile visibility + visibility hooks)
- PHP `>= 8.1`
- **bellbored** `>= 1.0.1` (required notification center)
- **textmebored** `>= 1.0.1` (required direct messages)

`bellbored` and `textmebored` are hard dependencies: enable them before enabling
`socialbored`. The plugin does not silently install dependencies; the admin installs and
enables them from the plugin catalog first.

## Installation

1. Install and enable **bellbored**.
2. Install and enable **textmebored**.
3. Install `socialbored` from the plugin catalog (or upload the ZIP).
4. Enable `socialbored`.
5. Open **Admin → Social** and choose a mode:
   - **Classic forum** — the home page stays the forum; the social feed is at `/social`.
   - **Social network** — the home page becomes the social feed; the classic forum is at `/forum`.
   - **Both** — the forum stays at `/`, the social feed is at `/social`.

The plugin creates its tables on the first request after enabling. No CLI step is required;
`php bb.php migrate` also applies the bundled migration.

## What it adds

- Social feed with **Following**, **Discover** and **Latest** tabs.
- Composer with optional title, visibility (`public`, `followers`, `private`) and Markdown.
- Composer media: upload an image (stored privately and served through `/download/{id}`),
  paste an image URL, or paste a video URL (direct MP4/WebM/OGG, YouTube or Vimeo).
  One media item per post.
- Post detail page with inline media, a small lightbox for images and Facebook-style
  minimal comments (the forum sidebar is not used in social mode).
- Social profiles with cover image, display name, bio, location, website, privacy, stats,
  posts, replies, media, likes, reposts and bookmarks.
- Follow/unfollow, block/unblock, mute/unmute.
- Reactions (like), reposts, bookmarks, hashtags, mentions (through the core).
- Reports with a moderation queue at **Admin → Social → Reports**.
- Notifications through bellbored (`follow`, `like`, `repost`).
- Privacy-aware media downloads: the core `/download/{id}` handler now enforces thread and
  profile visibility, so attachments of followers-only/private posts cannot be fetched
  directly even if this plugin is disabled.
- Privacy-aware classic forum listing: the core `fetch_threads()` applies the same visibility
  rules, so `/`, `/forum`, `/search` and `/category/*` never list private/followers-only social
  posts. The plugin keeps its richer social views on top of that.
- Core visibility columns: the plugin writes `threads.visibility` and
  `users.profile_visibility` alongside its `social_*` tables, and registers the follow-graph
  hooks (`thread_followers_visibility_sql`, `thread_listing_audience_sql`,
  `thread_view_decision`, `profile_view_decision`) that let the core resolve
  "followers" and apply block/mute filters.
- Feature flags for reactions, reposts, bookmarks, hashtags and the Following feed.
- Social profile settings at `/settings/social`.

## Privacy model

Visibility is enforced by the core (`threads.visibility`, `users.profile_visibility`) for
listings, search, profiles, direct thread links and downloads, and by the plugin's SQL for its
own feeds, hashtags, replies and media tabs. The plugin supplies the follow graph through the
core visibility hooks; replies are still checked by a `post_before_create` filter. Admins and
moderators can open direct thread links for moderation.

A social profile's privacy setting (`public`, `followers`, `private`) governs its **social
posts** (threads without a category). Classic forum threads keep their own forum visibility,
so a user with a private social profile can still write public forum threads. Profile privacy
is combined with per-post visibility; the more restrictive rule wins.

Note: images uploaded through the **social composer** are written to
`uploads/private/` and served through `/download/{id}`, so the core visibility
check applies. External image and video URLs are inherently public to anyone who
has the URL.

Other plugins that build their own content listings (sitemapbored, RSS/feed plugins,
custom search or API endpoints) should use the core `fetch_threads()` (which applies
visibility) or the `bb_thread_visibility_condition()` / `bb_can_view_thread()` helpers.
They should also honour the core block/mute rules if they expose social content.

## Data and uninstall

Uninstalling the plugin **does not delete social data**. The admin page exposes a separate
**Delete social data** action (type `DELETE` to confirm). It removes social profiles, follows,
blocks, mutes, reactions, bookmarks, reposts, hashtags and reports, but never core threads or
posts. Core `threads.visibility` / `users.profile_visibility` are intentionally left untouched:
deleting social metadata must not turn previously private content public.

Core user deletion has no plugin hook, so social rows can become orphaned. The admin page
also exposes **Clean up orphaned data**, which removes social rows whose user, thread or post
no longer exists.

**Important:** core `>= 1.2.0` stores `threads.visibility` and `users.profile_visibility` and
enforces them in listings, direct thread links, profiles and downloads. The plugin only
extends the "followers" case through its hooks. If you **disable or uninstall** the plugin,
followers-only and private posts stay hidden (the core fails closed when nobody answers the
follow hooks); public posts remain public. The plugin still installs a
`thread_before_view` filter as a second layer for direct links. Deleting the plugin files does
not delete the data automatically (by design), so use the admin **Delete social data** action
when you also want to remove the social metadata.

## Development

The plugin is developed inside the core checkout:

```text
plugins/socialbored/
  manifest.json
  socialbored.php
  src/...
  views/...
  assets/social.css
  assets/social.js
  lang/{en,it}.json
  migrations/
  tests/
  tools/build.php
```

The core `.gitignore` intentionally ignores `plugins/*` and `themes/*` (except freshbored),
so this working copy is not tracked by the core repository. Copy/extract
`plugins/socialbored` into the separate `socialbored-plugin` repository, or use the build
script below and publish the ZIP.

Run the core test suite (it auto-discovers `plugins/*/tests/*Test.php`):

```bash
php tests/run.php SocialBored
php tests/run.php
```

Lint the plugin:

```bash
find plugins/socialbored -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Packaging

Build the plugin ZIP:

```bash
php plugins/socialbored/tools/build.php
```

The archive is written to `plugins/socialbored/dist/`. Publish the plugin as the
`socialbored-plugin` repository and add it to the bulletinbored catalog. A ready-to-merge
catalog entry is included as `plugins/socialbored/catalog-entry.json`. Update the `repo` URL
if you publish under a different account. The social visual identity ships in the core
`freshbored` theme, so there is no separate theme package.

## License

BSD Zero Clause License. See `LICENSE`.
