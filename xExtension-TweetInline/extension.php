<?php

declare(strict_types=1);

/**
 * Tweet Inline — FreshRSS extension.
 *
 * Many sites embed tweets as "lazy" blockquotes that contain only a
 * `data-tweet-id` and a bare link; the actual
 * tweet text/media is injected client-side by Twitter's widgets.js, which a
 * feed reader never runs. This extension detects those tweet references in an
 * article's HTML and replaces each one with the inlined tweet content
 * (author, text, media), fetched server-side from Twitter's public
 * first-party syndication endpoint — no API key, OAuth, or login required.
 *
 * Hook: entry_before_insert (runs once, when the entry is first stored), so
 * the cost is paid a single time per article and the inlined HTML is what
 * gets saved to the database.
 */
final class TweetInlineExtension extends Minz_Extension {

	/** Default settings, overridable from the configuration panel. */
	private const DEFAULTS = [
		'show_media'        => true,   // inline photos / video posters
		'show_quoted'       => true,   // recursively resolve quoted tweets (1 level)
		'cache_ttl'         => 604800, // seconds to keep a resolved tweet (7 days)
		'fetch_timeout'     => 8,      // per-request curl timeout (seconds)
		'max_tweets_entry'  => 25,     // safety cap on tweets resolved per article
	];

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerHook('entry_before_insert', [$this, 'processEntry']);

		// Load the card stylesheet on the reading views.
		if (!Minz_Request::isPost()) {
			Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		}
	}

	/**
	 * entry_before_insert handler.
	 * Contract: function(FreshRSS_Entry) -> FreshRSS_Entry | null
	 */
	public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry {
		$content = $entry->content();
		if ($content === '' || stripos($content, 'twitter.com') === false
				&& stripos($content, 'x.com') === false
				&& stripos($content, 'data-tweet-id') === false) {
			return $entry;
		}
		// Already inlined (e.g. a re-import / re-run of the hook). Our rendered
		// cards carry this sentinel class; source embeds never do. Skipping
		// keeps the operation idempotent and avoids re-expanding the /status/
		// links a card holds in its own footer/media.
		if (strpos($content, 'class="tweet-inline"') !== false
				|| strpos($content, 'class="tweet-inline ') !== false) {
			return $entry;
		}

		try {
			$rewritten = $this->rewriteContent($content);
			if ($rewritten !== null && $rewritten !== $content) {
				$entry->_content($rewritten);
			}
		} catch (\Throwable $e) {
			// Never let a fetch/parse problem block ingestion; leave entry as-is.
			Minz_Log::warning('TweetInline: ' . $e->getMessage());
		}

		return $entry;
	}

	// ---------------------------------------------------------------------
	// Content rewriting
	// ---------------------------------------------------------------------

	/**
	 * Find tweet references in the HTML and replace them with inlined cards.
	 * Two reference shapes are handled:
	 *   1. <blockquote class="twitter-tweet" data-tweet-id="ID">...</blockquote>
	 *   2. bare <a href="https://twitter.com/USER/status/ID">...</a> not already
	 *      wrapped in a twitter-tweet blockquote.
	 */
	private function rewriteContent(string $html): ?string {
		$maxTweets = (int) $this->cfg('max_tweets_entry');
		$count = 0;

		// Rendered cards are stashed behind opaque placeholders and substituted
		// back only at the very end. This keeps pass 2 from re-matching the
		// /status/ links a card renders in its own media/footer (which would
		// otherwise nest cards inside cards).
		$cards = [];
		$store = static function (string $cardHtml) use (&$cards): string {
			$token = "\x01TWEETINLINE" . count($cards) . "\x01";
			$cards[$token] = $cardHtml;
			return $token;
		};

		// 1) Lazy "twitter-tweet" blockquotes.
		$html = preg_replace_callback(
			'#<blockquote[^>]*class="[^"]*twitter-tweet[^"]*"[^>]*>.*?</blockquote>#is',
			function (array $m) use (&$count, $maxTweets, $store): string {
				if ($count >= $maxTweets) {
					return $m[0];
				}
				$id = $this->extractIdFromBlock($m[0]);
				if ($id === null) {
					return $m[0];
				}
				$card = $this->renderTweet($id);
				if ($card === null) {
					return $m[0];
				}
				$count++;
				return $store($card);
			},
			$html
		) ?? $html;

		// 2) Bare status links not already turned into a card above. The HTML
		// is now card-free (cards are placeholders), so only the article's own
		// links can match here.
		$html = preg_replace_callback(
			'#<a\b[^>]*href="https?://(?:www\.|mobile\.)?(?:twitter|x)\.com/[^"/]+/status/(\d+)[^"]*"[^>]*>.*?</a>#is',
			function (array $m) use (&$count, $maxTweets, $store): string {
				if ($count >= $maxTweets) {
					return $m[0];
				}
				$card = $this->renderTweet($m[1]);
				if ($card === null) {
					return $m[0];
				}
				$count++;
				return $store($card);
			},
			$html
		) ?? $html;

		if ($cards !== []) {
			$html = strtr($html, $cards);
		}

		return $html;
	}

	private function extractIdFromBlock(string $block): ?string {
		if (preg_match('#data-tweet-id="(\d+)"#', $block, $m)) {
			return $m[1];
		}
		if (preg_match('#(?:twitter|x)\.com/[^"/]+/status/(\d+)#', $block, $m)) {
			return $m[1];
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Tweet fetching (with on-disk cache + fxtwitter fallback)
	// ---------------------------------------------------------------------

	/** Resolve a tweet id to an inlined HTML card, or null if unavailable. */
	private function renderTweet(string $id, int $depth = 0): ?string {
		$data = $this->fetchTweet($id);
		if ($data === null) {
			return null;
		}
		if (($data['__status'] ?? '') === 'tombstone') {
			return $this->renderUnavailable($id, $data['__reason'] ?? '');
		}
		return $this->renderCard($data, $depth);
	}

	/**
	 * Returns a normalized tweet array, or null on hard failure.
	 * Normalized shape:
	 *   ['id','name','screen_name','avatar','text','created_at',
	 *    'media'=>[['type','image','link'],...], 'quoted'=>id|null, '__status']
	 */
	private function fetchTweet(string $id): ?array {
		$cached = $this->cacheGet($id);
		if ($cached !== null) {
			return $cached;
		}

		$data = $this->fetchFromSyndication($id);
		if ($data === null) {
			$data = $this->fetchFromFxTwitter($id);
		}

		if ($data !== null) {
			$this->cacheSet($id, $data);
		}
		return $data;
	}

	/** Twitter's first-party widget backend. No auth; token value is ignored. */
	private function fetchFromSyndication(string $id): ?array {
		// Token must merely be present; derive a stable dummy from the id.
		$token = base_convert(substr($id, -10) ?: '1', 10, 36);
		$url = 'https://cdn.syndication.twimg.com/tweet-result?id=' . urlencode($id)
			. '&lang=fr&token=' . urlencode($token);
		$json = $this->httpGet($url);
		if ($json === null) {
			return null;
		}
		$d = json_decode($json, true);
		if (!is_array($d)) {
			return null;
		}

		$typename = $d['__typename'] ?? '';
		if ($typename === 'TweetTombstone') {
			$reason = $d['tombstone']['text']['text'] ?? 'Tweet indisponible';
			return ['__status' => 'tombstone', '__reason' => $reason];
		}
		if ($typename !== 'Tweet' || empty($d['id_str'])) {
			return null;
		}

		$media = [];
		foreach (($d['mediaDetails'] ?? []) as $m) {
			$type = $m['type'] ?? 'photo';
			$img = $m['media_url_https'] ?? null;
			if ($img === null) {
				continue;
			}
			$media[] = [
				'type'  => $type,
				'image' => $img,
				'link'  => $m['expanded_url'] ?? ($m['url'] ?? ''),
			];
		}

		return [
			'__status'    => 'ok',
			'id'          => (string) $d['id_str'],
			'name'        => (string) ($d['user']['name'] ?? ''),
			'screen_name' => (string) ($d['user']['screen_name'] ?? ''),
			'avatar'      => (string) ($d['user']['profile_image_url_https'] ?? ''),
			'text'        => $this->cleanText((string) ($d['text'] ?? ''), $d['entities'] ?? []),
			'created_at'  => (string) ($d['created_at'] ?? ''),
			'media'       => $media,
			'quoted'      => isset($d['quoted_tweet']['id_str']) ? (string) $d['quoted_tweet']['id_str'] : null,
		];
	}

	/** Community fallback used when syndication returns nothing usable. */
	private function fetchFromFxTwitter(string $id): ?array {
		$json = $this->httpGet('https://api.fxtwitter.com/i/status/' . urlencode($id));
		if ($json === null) {
			return null;
		}
		$d = json_decode($json, true);
		$t = $d['tweet'] ?? null;
		if (!is_array($t) || empty($t['id'])) {
			return null;
		}

		$media = [];
		foreach (($t['media']['all'] ?? []) as $m) {
			$img = ($m['type'] ?? '') === 'video'
				? ($m['thumbnail_url'] ?? null)
				: ($m['url'] ?? null);
			if ($img === null) {
				continue;
			}
			$media[] = [
				'type'  => $m['type'] ?? 'photo',
				'image' => $img,
				'link'  => $t['url'] ?? '',
			];
		}

		return [
			'__status'    => 'ok',
			'id'          => (string) $t['id'],
			'name'        => (string) ($t['author']['name'] ?? ''),
			'screen_name' => (string) ($t['author']['screen_name'] ?? ''),
			'avatar'      => (string) ($t['author']['avatar_url'] ?? ''),
			'text'        => (string) ($t['text'] ?? ''),
			'created_at'  => (string) ($t['created_at'] ?? ''),
			'media'       => $media,
			'quoted'      => null,
		];
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	private function renderCard(array $t, int $depth): string {
		$showMedia = (bool) $this->cfg('show_media');
		$profileUrl = 'https://x.com/' . rawurlencode($t['screen_name']);
		$tweetUrl = $profileUrl . '/status/' . rawurlencode($t['id']);

		$header = '';
		if ($t['name'] !== '' || $t['screen_name'] !== '') {
			$avatar = ($t['avatar'] !== '')
				? '<img class="tweet-inline-avatar" src="' . $this->esc($t['avatar'])
					. '" alt="" width="40" height="40" loading="lazy" referrerpolicy="no-referrer" />'
				: '';
			$header = '<div class="tweet-inline-header">' . $avatar
				. '<span class="tweet-inline-author">'
				. '<a href="' . $this->esc($profileUrl) . '" target="_blank" rel="noopener nofollow">'
				. '<strong>' . $this->esc($t['name']) . '</strong>'
				. ' <span class="tweet-inline-handle">@' . $this->esc($t['screen_name']) . '</span>'
				. '</a></span></div>';
		}

		$body = nl2br($this->linkifyText($t['text']));

		$mediaHtml = '';
		if ($showMedia && !empty($t['media'])) {
			$mediaHtml = '<div class="tweet-inline-media">';
			foreach ($t['media'] as $m) {
				$play = ($m['type'] !== 'photo') ? '<span class="tweet-inline-play">▶</span>' : '';
				$mediaHtml .= '<a href="' . $this->esc($tweetUrl) . '" target="_blank" rel="noopener nofollow">'
					. $play
					. '<img src="' . $this->esc($m['image']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" />'
					. '</a>';
			}
			$mediaHtml .= '</div>';
		}

		$quotedHtml = '';
		if ((bool) $this->cfg('show_quoted') && !empty($t['quoted']) && $depth < 1) {
			$inner = $this->renderTweet($t['quoted'], $depth + 1);
			if ($inner !== null) {
				$quotedHtml = '<div class="tweet-inline-quoted">' . $inner . '</div>';
			}
		}

		$footer = '<div class="tweet-inline-footer">'
			. '<a href="' . $this->esc($tweetUrl) . '" target="_blank" rel="noopener nofollow">'
			. $this->esc($this->prettyDate($t['created_at'])) . ' · Voir sur X</a></div>';

		return '<blockquote class="tweet-inline" data-tweet-id="' . $this->esc($t['id']) . '">'
			. $header . '<div class="tweet-inline-text">' . $body . '</div>'
			. $mediaHtml . $quotedHtml . $footer
			. '</blockquote>';
	}

	private function renderUnavailable(string $id, string $reason): string {
		$reason = $reason !== '' ? $reason : 'Tweet indisponible';
		return '<blockquote class="tweet-inline tweet-inline-unavailable" data-tweet-id="' . $this->esc($id) . '">'
			. '<div class="tweet-inline-text"><em>' . $this->esc($reason) . '</em></div>'
			. '</blockquote>';
	}

	// ---------------------------------------------------------------------
	// Text helpers
	// ---------------------------------------------------------------------

	/** Strip the trailing t.co media link Twitter appends to the text. */
	private function cleanText(string $text, array $entities): string {
		foreach (($entities['media'] ?? []) as $m) {
			if (!empty($m['url'])) {
				$text = str_replace($m['url'], '', $text);
			}
		}
		return trim($text);
	}

	/** Turn URLs, @handles and #hashtags into links, escaping everything else. */
	private function linkifyText(string $text): string {
		$escaped = $this->esc($text);
		// URLs
		$escaped = preg_replace_callback(
			'#\bhttps?://[^\s<]+#',
			static fn(array $m): string =>
				'<a href="' . $m[0] . '" target="_blank" rel="noopener nofollow">' . $m[0] . '</a>',
			$escaped
		) ?? $escaped;
		// @mentions
		$escaped = preg_replace(
			'#(^|[^\w/])@(\w{1,15})#',
			'$1<a href="https://x.com/$2" target="_blank" rel="noopener nofollow">@$2</a>',
			$escaped
		) ?? $escaped;
		// #hashtags (allow unicode word chars). Uses ~ delimiter because the
		// pattern itself contains a literal # for the hashtag.
		$escaped = preg_replace(
			'~(^|[^\w/&])#([\p{L}\p{N}_]+)~u',
			'$1<a href="https://x.com/hashtag/$2" target="_blank" rel="noopener nofollow">#$2</a>',
			$escaped
		) ?? $escaped;
		return $escaped;
	}

	private function prettyDate(string $iso): string {
		if ($iso === '') {
			return '';
		}
		$ts = strtotime($iso);
		return $ts ? date('d/m/Y H:i', $ts) : '';
	}

	private function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// ---------------------------------------------------------------------
	// HTTP
	// ---------------------------------------------------------------------

	private function httpGet(string $url): ?string {
		$timeout = (int) $this->cfg('fetch_timeout');
		$ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
			. 'Chrome/124.0 Safari/537.36';

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => $timeout,
				CURLOPT_USERAGENT      => $ua,
				CURLOPT_HTTPHEADER     => ['Accept: application/json'],
			]);
			$body = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
			if ($body === false || $code < 200 || $code >= 300) {
				return null;
			}
			return is_string($body) ? $body : null;
		}

		$ctx = stream_context_create(['http' => [
			'method'  => 'GET',
			'timeout' => $timeout,
			'header'  => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
		]]);
		$body = @file_get_contents($url, false, $ctx);
		return $body === false ? null : $body;
	}

	// ---------------------------------------------------------------------
	// Cache (one JSON file per tweet under the extension's writable dir)
	// ---------------------------------------------------------------------

	private function cacheDir(): string {
		$dir = $this->getExtensionUserPath() . '/tweet-cache';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		return $dir;
	}

	private function cachePath(string $id): string {
		// id is digits-only from the regexes, safe as a filename.
		return $this->cacheDir() . '/' . $id . '.json';
	}

	private function cacheGet(string $id): ?array {
		$path = $this->cachePath($id);
		if (!is_file($path)) {
			return null;
		}
		$ttl = (int) $this->cfg('cache_ttl');
		if ($ttl > 0 && (time() - (int) filemtime($path)) > $ttl) {
			return null;
		}
		$raw = @file_get_contents($path);
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : null;
	}

	private function cacheSet(string $id, array $data): void {
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json !== false) {
			@file_put_contents($this->cachePath($id), $json, LOCK_EX);
		}
	}

	// ---------------------------------------------------------------------
	// Configuration
	// ---------------------------------------------------------------------

	/** Read a setting, falling back to the built-in default. */
	private function cfg(string $key): mixed {
		$val = $this->getUserConfigurationValue($key, null);
		return $val ?? (self::DEFAULTS[$key] ?? null);
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$this->setUserConfigurationValue('show_media', Minz_Request::paramBoolean('show_media'));
			$this->setUserConfigurationValue('show_quoted', Minz_Request::paramBoolean('show_quoted'));
			$this->setUserConfigurationValue('cache_ttl', Minz_Request::paramInt('cache_ttl') ?: self::DEFAULTS['cache_ttl']);
			$this->setUserConfigurationValue('fetch_timeout', Minz_Request::paramInt('fetch_timeout') ?: self::DEFAULTS['fetch_timeout']);
			$this->setUserConfigurationValue('max_tweets_entry', Minz_Request::paramInt('max_tweets_entry') ?: self::DEFAULTS['max_tweets_entry']);
		}
	}
}
