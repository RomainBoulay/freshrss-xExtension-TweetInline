# FreshRSS — Tweet Inline

A [FreshRSS](https://freshrss.org/) extension that replaces empty/"lazy" Twitter
(X) embeds and bare tweet links in articles with the **inlined tweet content**:
author, text, and media.

## Why

Some sites publish tweets as *lazy* blockquotes that contain only a tweet id
and a bare link:

```html
<blockquote class="twitter-tweet" data-tweet-id="2068818671909179503">
  <a href="https://twitter.com/Bewww_Foot/status/2068818671909179503">…</a>
</blockquote>
```

The actual tweet text and media are injected **client-side** by Twitter's
`widgets.js`, which a feed reader never runs. So in your reader you only see a
link, not the tweet. This extension fetches the tweet **server-side** at import
time and rewrites the article so the content shows inline.

## How it works

- Hooks `entry_before_insert`, so each article is processed **once** when first
  stored; the inlined HTML is what gets saved.
- Detects both lazy `twitter-tweet` blockquotes and bare
  `twitter.com` / `x.com` `…/status/<id>` links.
- Fetches each tweet from Twitter's public, first-party syndication endpoint
  (`cdn.syndication.twimg.com/tweet-result`) — **no API key, OAuth, or login
  required**. Falls back to [FxTwitter](https://github.com/FixTweet/FxTwitter)
  (`api.fxtwitter.com`) when syndication returns nothing usable.
- Caches each resolved tweet on disk (one JSON file per id) to avoid refetching.
- Degrades gracefully: deleted/protected tweets render as a small
  "unavailable" note; any network/parse error leaves the original embed intact
  and never blocks article ingestion.
- Idempotent: re-processing already-inlined content is a no-op.

## No token required

The syndication endpoint takes a `token` query parameter, but its value is
ignored — it only has to be present. The extension derives a stable dummy token
from the tweet id. There is **no secret, account, or credential** anywhere.

## Install

FreshRSS loads extensions from its `extensions/` directory. With the official
Docker image that directory is typically a mounted volume
(`…/freshrss/extensions:/var/www/FreshRSS/extensions`).

```sh
cd /path/to/freshrss/extensions
git clone https://github.com/RomainBoulay/freshrss-xExtension-TweetInline.git
# FreshRSS expects each extension in its own folder named xExtension-*
ln -s freshrss-xExtension-TweetInline/xExtension-TweetInline xExtension-TweetInline
```

Or copy just the inner `xExtension-TweetInline/` folder directly into
`extensions/`.

Then in FreshRSS: **Settings → Extensions → Tweet Inline → enable**. New
articles fetched after enabling will be inlined. (Existing already-stored
articles are not retro-actively rewritten.)

## Configuration

Settings → Extensions → Tweet Inline → ⚙:

| Setting | Default | Description |
|---|---|---|
| Afficher les médias | on | Inline photos / video posters |
| Afficher les tweets cités | on | Resolve quoted tweets (1 level deep) |
| Durée du cache (s) | 604800 | How long a resolved tweet is cached (7 days) |
| Délai d'attente réseau (s) | 8 | Per-request network timeout |
| Tweets max par article | 25 | Safety cap on tweets resolved per article |

## Limitations & notes

- Relies on undocumented first-party endpoints. Twitter/X can change or remove
  them at any time; if both the syndication endpoint and FxTwitter fail, the
  original embed/link is left untouched.
- Each tweet fetch is a server-side request from your FreshRSS host to Twitter's
  CDN (or FxTwitter). No reading-habit data or credentials are sent.

## License

MIT — see [LICENSE](LICENSE).
