# socialbored

A first-party social-network layer for [bulletinbored](https://github.com/bulletinbored/bulletinbored-core).

It turns a bulletinbored installation into a small social network: a feed, profiles, follows,
reactions, reposts, bookmarks, hashtags, reports and notifications. The classic forum remains
available at all times.

## Requirements

- bulletinbored core `>= 1.1.2`
- PHP `>= 8.1`
- **editbored** `>= 1.0.1` (composer / Markdown rendering)
- **bellbored** `>= 1.0.1` (notification center)
- **textmebored** `>= 1.0.1` (optional, direct messages)

`editbored` and `bellbored` are hard dependencies: enable them before enabling `socialbored`.
`textmebored` is optional; when disabled the "send message" buttons are hidden. The plugin does
not silently install dependencies; the admin installs and enables them from the plugin
catalog first.

## Installation

1. Install and enable **editbored**.
2. Install and enable **bellbored**.
3. Install `socialbored` from the plugin catalog (or upload the ZIP).
4. Enable `socialbored`.
5. Optionally install the `socialbored` theme and activate it from **Admin → Social**.
6. Open **Admin → Social** and choose a mode:
   - **Classic forum** — the home page stays the forum; the social feed is at `/social`.
   - **Social network** — the home page becomes the social feed; the classic forum is at `/forum`.
   - **Both** — the forum stays at `/`, the social feed is at `/social`.

The plugin creates its tables on the first request after enabling. No CLI step is required;
`php bb.php migrate` also applies the bundled migration.

## What it adds

- Social feed with **Following**, **Discover** and **Latest** tabs.
- Composer with optional title, visibility (`public`, `followers`, `private`), Markdown and
  editbored integration.
- Social profiles with cover image, display name, bio, location, website, privacy, stats,
  posts, replies, media, likes, reposts and bookmarks.
- Follow/unfollow, block/unblock, mute/unmute.
- Reactions (like), reposts, bookmarks, hashtags, mentions (through editbored/the core).
- Reports with a moderation queue at **Admin → Social → Reports**.
- Notifications through bellbored (`follow`, `like`, `repost`).
- Privacy-aware media downloads: `/download/{id}` is shadowed so attachments of
  followers-only/private posts cannot be fetched directly.
- Privacy-aware classic forum listing: the plugin owns `/` in forum/both mode and `/forum` in
  social/both mode, and `/search` in forum mode, so private/followers-only social posts never
  leak into the classic lists. The social profile (`/u/{user}`) is used in every mode for the
  same reason.
- Feature flags for reactions, reposts, bookmarks, hashtags and the Following feed.
- Social profile settings at `/settings/social`.

## Privacy model

Visibility is enforced in SQL for feeds, profiles, search, hashtags and the classic forum
listing, and by a `thread_before_view` filter for the core thread page. Replies are checked by
a `post_before_create` filter. Admins and moderators can still open direct thread links for
moderation.

A social profile's privacy setting (`public`, `followers`, `private`) governs its **social
posts** (threads without a category). Classic forum threads keep their own forum visibility,
so a user with a private social profile can still write public forum threads. Profile privacy
is combined with per-post visibility; the more restrictive rule wins.

Note: editbored image uploads are written to the public `/uploads` directory by
editbored itself. If you publish followers-only or private posts with images, keep
that in mind, or use text-only posts for private content. Core attachments uploaded
through `/download/{id}` are privacy-aware because socialbored shadows that route and
checks thread visibility.

Other plugins that build their own content listings (sitemapbored, RSS/feed plugins,
custom search or API endpoints) are not automatically aware of social visibility. Keep
them disabled or extend them to join `social_threads` and apply the same visibility and
block rules before publishing URLs or titles.

## Data and uninstall

Uninstalling the plugin **does not delete social data**. The admin page exposes a separate
**Delete social data** action (type `DELETE` to confirm). It removes social profiles, follows,
blocks, mutes, reactions, bookmarks, reposts, hashtags and reports, but never core threads or
posts.

Core user deletion has no plugin hook, so social rows can become orphaned. The admin page
also exposes **Clean up orphaned data**, which removes social rows whose user, thread or post
no longer exists.

**Important:** while the plugin is enabled it owns the classic listing (`/` in forum/both mode
and `/forum` in social/both mode) and applies social visibility in SQL, and it installs the
`thread_before_view` / `post_before_create` filters that protect direct thread links. If you
**disable or uninstall** the plugin, those filters are gone: private and followers-only posts
are still core `threads` rows and become visible to everyone. Before disabling/uninstalling,
either make those posts public, delete the social data, or keep the plugin enabled. Deleting
the plugin files does not delete the data automatically (by design), so use the admin
**Delete social data** action first when privacy matters.

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
`plugins/socialbored` and `themes/socialbored` into the separate
`socialbored-plugin` / `socialbored-theme` repositories, or use the build script below and
publish the ZIPs.

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

Build the plugin ZIP (and the theme ZIP if the theme is present):

```bash
php plugins/socialbored/tools/build.php
```

The archives are written to `plugins/socialbored/dist/`. Publish the plugin and theme as
separate repositories (`socialbored-plugin`, `socialbored-theme`) and add them to the
bulletinbored catalog. Ready-to-merge catalog entries are included as
`plugins/socialbored/catalog-entry.json` and `themes/socialbored/catalog-entry.json`.
Update the `repo` URLs if you publish under a different account.

## License

BSD Zero Clause License. See `LICENSE`.
