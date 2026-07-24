# ram

Self-hosted video bookmark library. `index.html` is a single-page React app (loaded via CDN, no build step) that talks to a PHP backend (`api.php`) storing data in `videos.json`. A Chrome extension (`chrome-extension/`) lets you add videos and edit titles/tags/covers directly from the browser while watching.

Deployment is automatic: pushing to `main` runs `.github/workflows/deploy-viewer.yml`, which mirrors the repo over FTP to the production host. The server sits behind HTTP Basic Auth configured at the host level (not in this repo).

## Data model

`videos.json`:
```json
{
  "videos": [
    {
      "id": "v_...",
      "url": "https://...",
      "title": "...",
      "cover": "images/v_....jpg | data:image/...;base64,... | null",
      "tags": ["..."],
      "rating": 0,
      "created_at": "2026-01-01T00:00:00+00:00"
    }
  ],
  "labels": ["tag1", "tag2"]
}
```
Covers are downloaded and re-encoded as JPGs under `images/` when possible; `cover` can also briefly hold a raw base64 `data:` URI before being migrated to disk.

## api.php actions

All POST actions take a JSON body `{"action": "...", ...}`. All endpoints require whatever Basic Auth is configured on the server.

### GET

| action | params | description |
|---|---|---|
| `list` | — | Returns the full `videos.json` contents. |
| `refetch_titles` | — | Backfills a title for every video whose title is empty or still just the raw URL (existing titles are left untouched). Meant to be hit directly from a browser; returns `{"success","checked","updated"}`. Safe to re-run. |

### POST

| action | body | description |
|---|---|---|
| `add` | `url`, `tags[]`, `title?`, `cover_data?` | Adds a video. Rejects duplicates (409) by exact URL match. Fetches title/cover automatically via `fetchVideoInfo` unless overridden. |
| `delete` | `id` | Removes a video and its cover file. |
| `add_label` | `label` | Adds a tag to the global label list. |
| `delete_label` | `label` | Removes a tag from the label list and from every video. |
| `fetch_meta` | `url` | Previews title/cover for a URL without saving (used by the Add Video modal/extension). Flags `exists`/`existing_title` if the URL is already in the library. |
| `rate_video` | `id`, `rating` (0-5) | Sets a video's rating. |
| `update_video` | `id`, `url?`, `title?`, `cover?`, `tags?`, `rating?` | Edits an existing video; a new base64 `cover` is saved to disk and the old file removed. |
| `update_tags` | `id`, `tags[]` | Replaces a video's tags. |
| `migrate_images` | — | One-off migration: converts any video still storing its cover as inline base64 into a JPG file under `images/`. |

## Metadata fetching (`fetchVideoInfo`)

Used by `add`, `fetch_meta`, and `refetch_titles` (with a `titleOnly` flag that skips downloading/re-encoding the cover entirely). Resolution order:

1. **YouTube** — oEmbed for the title, `maxresdefault.jpg` falling back to `hqdefault.jpg` for the cover.
2. **Vimeo** — oEmbed for title and thumbnail (upgraded to 1280x720 when possible).
3. **Generic** — fetches the page and parses `og:title`/`twitter:title`/`<title>` for the title, and `og:image`/`twitter:image`/`twitter:image:src`/JSON-LD `thumbnailUrl`/`image` for the cover.

Cover images are decoded via GD, with a WebP-specific fallback (`imagecreatefromwebp`) and an Imagick fallback for formats GD's build can't handle; if all decoding fails, the raw bytes are still saved under the correct extension rather than dropping the cover.

Some sources won't yield a cover no matter what: a handful of sites (e.g. certain xhamster videos) serve a metadata-stripped page to some server IPs due to geo-blocking — the `<title>` still comes through but there's no `og:image` at all to fetch.

## Chrome extension

`chrome-extension/` is a side panel (Manifest V3) for adding the currently open tab's video to the library, with Basic Auth support configured via `options.html`/`options.js` (stores the API URL and credentials in extension storage). See `background.js`/`panel.js` for the add/fetch flow.
