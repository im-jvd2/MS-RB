<?php
declare(strict_types=1);

/*
 * Royal Brand indexing diagnostic, v1.0.
 * Run from the command line, outside public_html. It does not load WordPress,
 * read configuration, write files, change settings, or submit indexing requests.
 * Only the fixed public URLs in rb_urls() may be requested.
 */
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit("Not found\n");
}

error_reporting(0);
ini_set('display_errors', '0');

const RB_DIAG_VERSION = '1.0.0';
const RB_HOST = 'royalbrand.vip';
const RB_MAX_BODY_BYTES = 3145728;
const RB_MAX_REDIRECTS = 2;
const RB_BROWSER_UA = 'Mozilla/5.0 (Linux; Android 12; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Mobile Safari/537.36';
const RB_SIMULATED_GOOGLEBOT_UA = 'Mozilla/5.0 (Linux; Android 12; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

/** Hard-coded destinations; there is no user-provided URL option. */
function rb_urls(): array
{
    $origin = 'https://' . RB_HOST;
    return array(
        'hub' => $origin . '/services/',
        'branding' => $origin . '/services/' . rawurlencode('کمپین-برندینگ') . '/',
        'campaign_360' => $origin . '/services/' . rawurlencode('کمپین-360-درجه') . '/',
        'robots' => $origin . '/robots.txt',
        'page_sitemap' => $origin . '/page-sitemap.xml',
        'sitemap_index' => $origin . '/sitemap_index.xml',
    );
}

function rb_targets(): array
{
    $urls = rb_urls();
    return array('hub' => $urls['hub'], 'branding' => $urls['branding'], 'campaign_360' => $urls['campaign_360']);
}

/** Compare host case-insensitively and decoded paths; query strings remain distinct. */
function rb_url_key(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower(rtrim((string) $parts['host'], '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    $portPart = ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) ? ':' . $port : '';
    $path = rawurldecode((string) ($parts['path'] ?? '/'));
    if ($path === '') {
        $path = '/';
    }
    $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
    return $scheme . '://' . $host . $portPart . $path . $query;
}

/** Turn a relative Location/href into a URL without making a request. */
function rb_resolve_url(string $base, string $reference): ?string
{
    $reference = html_entity_decode(trim($reference), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($reference === '' || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
        return null;
    }
    if (strpos($reference, '//') === 0) {
        return 'https:' . $reference;
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) {
        return preg_match('~^https?://~i', $reference) ? $reference : null;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $origin = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    $basePath = (string) ($parts['path'] ?? '/');
    if ($reference[0] === '#') {
        return $origin . $basePath . $reference;
    }
    if ($reference[0] === '?') {
        return $origin . $basePath . $reference;
    }
    $refParts = parse_url($reference);
    if (!is_array($refParts)) {
        return null;
    }
    $refPath = (string) ($refParts['path'] ?? '');
    if (isset($refPath[0]) && $refPath[0] === '/') {
        $path = $refPath;
    } else {
        $slash = strrpos($basePath, '/');
        $path = substr($basePath, 0, $slash === false ? 0 : $slash + 1) . $refPath;
    }
    $hasTrailingSlash = substr($path, -1) === '/';
    $segments = array();
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    $path = '/' . implode('/', $segments);
    if ($hasTrailingSlash && $path !== '/') {
        $path .= '/';
    }
    $suffix = isset($refParts['query']) ? '?' . $refParts['query'] : '';
    $suffix .= isset($refParts['fragment']) ? '#' . $refParts['fragment'] : '';
    return $origin . $path . $suffix;
}

/** Only the six fixed HTTPS URLs, no query strings, credentials or fragments. */
function rb_is_allowed_url(string $url, array $allowed): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
        strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) !== RB_HOST ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
        (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return false;
    }
    $key = rb_url_key($url);
    foreach ($allowed as $item) {
        if ($key !== null && $key === rb_url_key((string) $item)) {
            return true;
        }
    }
    return false;
}

/** Never include URL credentials, query, fragment, or an unrelated path. */
function rb_safe_url(?string $url): ?string
{
    if ($url === null) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return '[invalid or relative URL]';
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    if (!in_array($scheme, array('http', 'https'), true)) {
        return '[unsupported URL]';
    }
    $path = rawurldecode((string) ($parts['path'] ?? '/'));
    // The site root may also be shown when a response declares it as canonical.
    // It is not requested as a separate page by this diagnostic.
    $knownPaths = array('/');
    foreach (rb_urls() as $known) {
        $knownParts = parse_url($known);
        $knownPaths[] = rawurldecode((string) ($knownParts['path'] ?? '/'));
    }
    if ($host !== RB_HOST || !in_array($path, $knownPaths, true)) {
        $path = '/[other-path-redacted]';
    }
    if (!in_array($host, array(RB_HOST, 'www.' . RB_HOST), true)) {
        $host = '[external-host-redacted]';
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return $scheme . '://' . $host . $port . $path .
        (isset($parts['query']) ? '?[query-redacted]' : '') .
        (isset($parts['fragment']) ? '#[fragment-redacted]' : '');
}

function rb_first_header(array $headers, string $name): ?string
{
    return isset($headers[$name][0]) ? (string) $headers[$name][0] : null;
}

/** Raw GET from this host. Only an allowlisted URL may be passed by rb_request_chain. */
function rb_http_get(string $url, string $userAgent): array
{
    $body = '';
    $headers = array();
    $headerLimitReached = false;
    $truncated = false;
    $handle = curl_init($url);
    if ($handle === false) {
        return array('status' => null, 'headers' => array(), 'body' => '', 'truncated' => false, 'curl_error_code' => -1, 'elapsed_ms' => null);
    }
    curl_setopt_array($handle, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml,application/xml,text/plain;q=0.9,*/*;q=0.5'),
        CURLOPT_HEADERFUNCTION => function ($unused, $line) use (&$headers, &$headerLimitReached) {
            $length = strlen($line);
            if (preg_match('~^HTTP/\S+\s+\d{3}~i', $line)) {
                $headers = array();
                $headerLimitReached = false;
                return $length;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($line, 0, $colon)));
                if (in_array($name, array('location', 'content-type', 'x-robots-tag', 'link'), true)) {
                    $value = trim(substr($line, $colon + 1));
                    if (strlen($value) > 2048) {
                        $headerLimitReached = true;
                    }
                    if (count($headers[$name] ?? array()) < 20) {
                        $headers[$name][] = substr($value, 0, 2048);
                    } else {
                        $headerLimitReached = true;
                    }
                }
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => function ($unused, $chunk) use (&$body, &$truncated) {
            $length = strlen($chunk);
            $remaining = RB_MAX_BODY_BYTES - strlen($body);
            if ($length > $remaining) {
                if ($remaining > 0) {
                    $body .= substr($chunk, 0, $remaining);
                }
                $truncated = true;
                return 0;
            }
            $body .= $chunk;
            return $length;
        },
    ));
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($handle, constant('CURLOPT_PROTOCOLS_STR'), 'https');
    } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($handle, constant('CURLOPT_PROTOCOLS'), constant('CURLPROTO_HTTPS'));
    }
    curl_exec($handle);
    $errorCode = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $time = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
    curl_close($handle);
    return array(
        'status' => $status > 0 ? $status : null,
        'headers' => $headers,
        'header_limit_reached' => $headerLimitReached,
        'body' => $body,
        'truncated' => $truncated,
        'curl_error_code' => $errorCode,
        'elapsed_ms' => is_numeric($time) ? (int) round((float) $time * 1000) : null,
    );
}

/** The optional transport argument is used by offline fixture tests only. */
function rb_request_chain(string $url, string $userAgent, array $allowed, $transport = null): array
{
    $transport = $transport ?: 'rb_http_get';
    $hops = array();
    $seen = array();
    $response = null;
    $current = $url;
    $reason = 'response_received';
    for ($i = 0; $i <= RB_MAX_REDIRECTS; $i++) {
        if (!rb_is_allowed_url($current, $allowed)) {
            $reason = 'destination_not_allowlisted';
            break;
        }
        $key = rb_url_key($current);
        if (isset($seen[$key])) {
            $reason = 'redirect_loop';
            break;
        }
        $seen[$key] = true;
        try {
            $response = call_user_func($transport, $current, $userAgent);
        } catch (Throwable $ignored) {
            $response = null;
            $reason = 'transport_error';
            break;
        }
        $status = $response['status'] ?? null;
        $location = rb_first_header($response['headers'] ?? array(), 'location');
        $resolved = $location !== null ? rb_resolve_url($current, $location) : null;
        $hops[] = array(
            'url' => rb_safe_url($current),
            'status' => $status,
            'location' => $resolved !== null ? rb_safe_url($resolved) : ($location !== null ? '[invalid Location]' : null),
        );
        if ((int) ($response['curl_error_code'] ?? 0) !== 0) {
            $reason = !empty($response['truncated']) ? 'response_body_limit_reached' : 'curl_error';
            break;
        }
        if (!in_array($status, array(301, 302, 303, 307, 308), true)) {
            break;
        }
        if ($location === null || $resolved === null) {
            $reason = 'redirect_without_valid_location';
            break;
        }
        if (!rb_is_allowed_url($resolved, $allowed)) {
            $reason = 'redirect_not_followed_destination_not_allowlisted';
            break;
        }
        if (isset($seen[rb_url_key($resolved)])) {
            $reason = 'redirect_loop';
            break;
        }
        if ($i === RB_MAX_REDIRECTS) {
            $reason = 'redirect_limit';
            break;
        }
        $current = $resolved;
    }
    return array('hops' => $hops, 'stop_reason' => $reason, 'url' => $current, 'response' => $response);
}

function rb_directives(string $value): array
{
    preg_match_all('/\b(noindex|index|none|nofollow|follow|nosnippet|noarchive|notranslate|noimageindex|indexifembedded|max-snippet|max-image-preview|max-video-preview)\b/i', $value, $matches);
    return array_values(array_unique(array_map('strtolower', $matches[1] ?? array())));
}

function rb_header_canonicals(array $headers, string $base): array
{
    $items = array();
    foreach (($headers['link'] ?? array()) as $line) {
        preg_match_all('~<([^>]+)>\s*;\s*([^,]*)~', (string) $line, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match('~(?:^|;)\s*rel\s*=\s*(?:"[^"]*\bcanonical\b[^"]*"|\'[^\']*\bcanonical\b[^\']*\'|canonical)(?:\s*;|\s*$)~i', $match[2])) {
                $resolved = rb_resolve_url($base, $match[1]);
                if ($resolved !== null) {
                    $items[] = $resolved;
                }
            }
        }
    }
    return $items;
}

function rb_public_response(array $chain): array
{
    $response = $chain['response'];
    if (!is_array($response)) {
        return array('hops' => $chain['hops'], 'stop_reason' => $chain['stop_reason'], 'status' => null);
    }
    $headers = $response['headers'] ?? array();
    $robots = array();
    foreach (($headers['x-robots-tag'] ?? array()) as $value) {
        $robots[] = rb_directives((string) $value);
    }
    $contentType = rb_first_header($headers, 'content-type');
    $type = null;
    if ($contentType !== null && preg_match('~^\s*([a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+)~i', $contentType, $match)) {
        $type = strtolower($match[1]);
    }
    $headerCanonicals = rb_header_canonicals($headers, (string) $chain['url']);
    return array(
        'hops' => $chain['hops'],
        'stop_reason' => $chain['stop_reason'],
        'status' => $response['status'] ?? null,
        'content_type' => $type,
        'x_robots_tag_present' => isset($headers['x-robots-tag']),
        'x_robots_tag_directive_words_unscoped' => $robots,
        'header_canonical_count' => count($headerCanonicals),
        'header_canonical_urls_redacted' => array_map('rb_safe_url', $headerCanonicals),
        'header_limit_reached' => (bool) ($response['header_limit_reached'] ?? false),
        'body_bytes_received' => strlen((string) ($response['body'] ?? '')),
        'body_truncated' => (bool) ($response['truncated'] ?? false),
        'curl_error_code' => (int) ($response['curl_error_code'] ?? 0),
        'elapsed_ms_from_this_host' => $response['elapsed_ms'] ?? null,
    );
}

/** Distinguish metadata in the head from tags elsewhere in the source. */
function rb_node_is_in_head($node): bool
{
    for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
        if (strtolower((string) $parent->nodeName) === 'head') {
            return true;
        }
    }
    return false;
}

/** Source HTML only: this does not execute JavaScript, CSS, or browser rendering. */
function rb_analyze_html(string $html, string $base, array $targets, bool $truncated, ?string $requestedUrl = null): array
{
    $empty = array(
        'html_parseable' => false,
        'partial_body' => $truncated,
        'title_count' => null,
        'h1_count' => null,
        'html_base_href_present' => null,
        'html_base_href_usable' => null,
        'html_base_url_redacted' => null,
        'canonical_html_count' => null,
        'canonical_in_head_count' => null,
        'canonical_outside_head_count' => null,
        'canonical_invalid_or_fragment_count' => null,
        'canonical_html_urls_redacted' => array(),
        'canonical_head_urls_redacted' => array(),
        'canonical_matches_final_url' => null,
        'canonical_matches_requested_url' => null,
        'robots_meta' => array(),
        'noindex_in_relevant_meta' => null,
        'possible_challenge_marker_heuristic' => null,
        'source_body_text_codepoints_approx' => null,
        'source_body_text_utf8_bytes' => null,
        'links_to_targets' => null,
    );
    if ($html === '' || !class_exists('DOMDocument')) {
        return $empty;
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return $empty;
    }
    $xpath = new DOMXPath($document);
    $documentBase = $base;
    $basePresent = false;
    $baseUsable = false;
    foreach ($document->getElementsByTagName('base') as $baseElement) {
        if (!$baseElement->hasAttribute('href')) {
            continue;
        }
        $basePresent = true;
        $resolvedBase = rb_resolve_url($base, $baseElement->getAttribute('href'));
        if ($resolvedBase !== null && rb_url_key($resolvedBase) !== null) {
            $documentBase = $resolvedBase;
            $baseUsable = true;
        }
        break;
    }
    $canonicals = array();
    $headCanonicals = array();
    $canonicalTags = 0;
    $headCanonicalTags = 0;
    $outsideCanonicalTags = 0;
    $invalidCanonicals = 0;
    foreach ($document->getElementsByTagName('link') as $link) {
        $rel = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
        if (in_array('canonical', $rel ?: array(), true)) {
            $canonicalTags++;
            $inHead = rb_node_is_in_head($link);
            if ($inHead) {
                $headCanonicalTags++;
            } else {
                $outsideCanonicalTags++;
            }
            $resolved = rb_resolve_url($documentBase, $link->getAttribute('href'));
            $parts = $resolved !== null ? parse_url($resolved) : false;
            if ($resolved === null || !is_array($parts) || !isset($parts['host']) || isset($parts['fragment'])) {
                $invalidCanonicals++;
            } else {
                $canonicals[] = $resolved;
                if ($inHead) {
                    $headCanonicals[] = $resolved;
                }
            }
        }
    }
    $meta = array();
    $noindex = false;
    foreach ($document->getElementsByTagName('meta') as $node) {
        $name = strtolower(trim($node->getAttribute('name')));
        if (in_array($name, array('robots', 'googlebot'), true)) {
            $directives = rb_directives($node->getAttribute('content'));
            $meta[] = array('name' => $name, 'directive_words' => $directives, 'in_head' => rb_node_is_in_head($node));
            if (in_array('noindex', $directives, true) || in_array('none', $directives, true)) {
                $noindex = true;
            }
        }
    }
    $links = array();
    foreach ($targets as $name => $unused) {
        $links[$name] = array('anchor_href_count' => 0, 'nofollow_count' => 0);
    }
    foreach ($document->getElementsByTagName('a') as $anchor) {
        $href = trim($anchor->getAttribute('href'));
        if ($href === '' || $href[0] === '#') {
            continue;
        }
        $resolved = rb_resolve_url($documentBase, $href);
        if ($resolved === null) {
            continue;
        }
        foreach ($targets as $name => $target) {
            if (rb_url_key($resolved) === rb_url_key($target)) {
                $links[$name]['anchor_href_count']++;
                $rel = preg_split('/\s+/', strtolower(trim($anchor->getAttribute('rel'))));
                if (in_array('nofollow', $rel ?: array(), true)) {
                    $links[$name]['nofollow_count']++;
                }
            }
        }
    }
    $remove = array();
    foreach ($xpath->query('//script|//style|//template|//noscript') as $node) {
        $remove[] = $node;
    }
    foreach ($remove as $node) {
        if ($node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
    $bodyNode = $document->getElementsByTagName('body')->item(0);
    $text = $bodyNode ? preg_replace('/\s+/u', ' ', (string) $bodyNode->textContent) : '';
    $text = is_string($text) ? trim($text) : '';
    // Count UTF-8 code points even without mbstring; this is neither rendered
    // text nor a count of Persian words or Unicode grapheme clusters.
    $leadingBytes = preg_replace('/[\x80-\xBF]/', '', $text);
    $characters = is_string($leadingBytes) ? strlen($leadingBytes) : null;
    $hasOneHeadCanonical = $headCanonicalTags === 1 && count($headCanonicals) === 1;
    return array(
        'html_parseable' => true,
        'partial_body' => $truncated,
        'title_count' => $document->getElementsByTagName('title')->length,
        'h1_count' => $document->getElementsByTagName('h1')->length,
        'html_base_href_present' => $basePresent,
        'html_base_href_usable' => $basePresent ? $baseUsable : null,
        'html_base_url_redacted' => $baseUsable ? rb_safe_url($documentBase) : null,
        'canonical_html_count' => $canonicalTags,
        'canonical_in_head_count' => $headCanonicalTags,
        'canonical_outside_head_count' => $outsideCanonicalTags,
        'canonical_invalid_or_fragment_count' => $invalidCanonicals,
        'canonical_html_urls_redacted' => array_map('rb_safe_url', $canonicals),
        'canonical_head_urls_redacted' => array_map('rb_safe_url', $headCanonicals),
        'canonical_matches_final_url' => $hasOneHeadCanonical ? rb_url_key($headCanonicals[0]) === rb_url_key($base) : null,
        'canonical_matches_requested_url' => $hasOneHeadCanonical ? rb_url_key($headCanonicals[0]) === rb_url_key($requestedUrl ?? $base) : null,
        'robots_meta' => $meta,
        'noindex_in_relevant_meta' => $noindex ? true : ($truncated ? null : false),
        'possible_challenge_marker_heuristic' => preg_match('~cf-chl-|checking your browser|just a moment\.\.\.|verify you are human|access denied~i', $html) === 1,
        'source_body_text_codepoints_approx' => $characters,
        'source_body_text_utf8_bytes' => strlen($text),
        'links_to_targets' => $links,
    );
}

/** Approximate Googlebot robots rules; Search Console remains authoritative for its test. */
function rb_robots_groups(string $text): array
{
    $groups = array();
    $agents = array();
    $rules = array();
    $seenRule = false;
    $flush = function () use (&$groups, &$agents, &$rules, &$seenRule) {
        if ($agents) {
            $groups[] = array('agents' => $agents, 'rules' => $rules);
        }
        $agents = array();
        $rules = array();
        $seenRule = false;
    };
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: array() as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }
        list($name, $value) = array_map('trim', explode(':', $line, 2));
        $name = strtolower($name);
        if ($name === 'user-agent') {
            if ($seenRule) {
                $flush();
            }
            $agents[] = strtolower($value);
        } elseif (in_array($name, array('allow', 'disallow'), true) && $agents) {
            $seenRule = true;
            if ($value !== '') {
                $rules[] = array('type' => $name, 'pattern' => $value);
            }
        }
    }
    $flush();
    return $groups;
}

function rb_robots_decision(string $text, string $url): array
{
    $groups = rb_robots_groups($text);
    $rank = -1;
    $selected = array();
    foreach ($groups as $group) {
        $groupRank = -1;
        foreach ($group['agents'] as $agent) {
            if ($agent === '*') {
                $groupRank = max($groupRank, 0);
            } elseif ($agent !== '' && strpos('googlebot', $agent) !== false) {
                $groupRank = max($groupRank, strlen($agent));
            }
        }
        if ($groupRank > $rank) {
            $rank = $groupRank;
            $selected = $groupRank >= 0 ? $group['rules'] : array();
        } elseif ($groupRank >= 0 && $groupRank === $rank) {
            $selected = array_merge($selected, $group['rules']);
        }
    }
    $parts = parse_url($url);
    $path = rawurldecode((string) ($parts['path'] ?? '/'));
    $bestLength = -1;
    $bestType = null;
    foreach ($selected as $rule) {
        $pattern = rawurldecode($rule['pattern']);
        $endAnchor = substr($pattern, -1) === '$';
        if ($endAnchor) {
            $pattern = substr($pattern, 0, -1);
        }
        $expression = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . ($endAnchor ? '$' : '') . '~';
        if (@preg_match($expression, $path) !== 1) {
            continue;
        }
        $length = strlen(str_replace('*', '', $pattern));
        if ($length > $bestLength || ($length === $bestLength && $rule['type'] === 'allow')) {
            $bestLength = $length;
            $bestType = $rule['type'];
        }
    }
    return array('decision' => $bestType === 'disallow' ? 'blocked' : 'allowed', 'matching_rule_type' => $bestType, 'approximate_only' => true);
}

/** Refuse DTDs and never load external XML entities. */
function rb_parse_sitemap(string $xml, string $kind): array
{
    if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || !class_exists('DOMDocument')) {
        return array('ok' => false, 'entries' => array());
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $expectedRoot = $kind === 'index' ? 'sitemapindex' : 'urlset';
    if (!$loaded || !$document->documentElement || strtolower($document->documentElement->localName) !== $expectedRoot) {
        return array('ok' => false, 'entries' => array());
    }
    $xpath = new DOMXPath($document);
    $element = $kind === 'index' ? 'sitemap' : 'url';
    $entries = array();
    foreach ($xpath->query('//*[local-name()="' . $element . '"]') as $node) {
        $locations = $xpath->query('./*[local-name()="loc"]', $node);
        if (!$locations || $locations->length === 0) {
            continue;
        }
        $location = trim((string) $locations->item(0)->textContent);
        $lastmods = $xpath->query('./*[local-name()="lastmod"]', $node);
        $lastmod = $lastmods && $lastmods->length ? trim((string) $lastmods->item(0)->textContent) : null;
        if ($lastmod !== null && !preg_match('/^[0-9TtZz:+.\-]{4,40}$/', $lastmod)) {
            $lastmod = null;
        }
        $key = rb_url_key($location);
        if ($key !== null) {
            $entries[$key] = $lastmod;
        }
    }
    return array('ok' => true, 'entries' => $entries);
}

function rb_emit(array $data): void
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo ($encoded !== false ? $encoded : '{"error":"json_encoding_failed"}') . PHP_EOL;
}

/** The optional transport is for offline fixture tests, not a CLI option. */
function rb_main(array $args, $transport = null): int
{
    if (count($args) === 2 && $args[1] === '--help') {
        echo "CLI only. Usage: php diagnose.php\n";
        echo "Tests three fixed pages and their public robots.txt and sitemaps; it does not read logs or WordPress configuration.\n";
        return 0;
    }
    if (count($args) !== 1) {
        rb_emit(array('error' => 'unsupported_argument', 'hint' => 'Use --help; arbitrary URLs and local files are not supported.'));
        return 2;
    }
    if (PHP_VERSION_ID < 70400) {
        rb_emit(array('error' => 'php_7_4_or_newer_required'));
        return 2;
    }
    $missing = array();
    $curlFunctions = array('curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec', 'curl_errno', 'curl_getinfo', 'curl_close');
    foreach ($curlFunctions as $function) {
        if (!function_exists($function)) {
            $missing[] = 'curl';
            break;
        }
    }
    if (!class_exists('DOMDocument') || !method_exists('DOMDocument', 'loadHTML') || !method_exists('DOMDocument', 'loadXML') ||
        !class_exists('DOMXPath') || !method_exists('DOMXPath', 'query')) {
        $missing[] = 'dom';
    }
    if ($missing) {
        rb_emit(array('error' => 'missing_php_extensions_or_disabled_functions', 'extensions' => $missing));
        return 2;
    }
    $urls = rb_urls();
    $targets = rb_targets();
    $result = array(
        'tool' => 'Royal Brand index diagnostic',
        'version' => RB_DIAG_VERSION,
        'time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'scope' => array(
            'target_pages' => array_keys($targets),
            'support_files' => array('robots.txt', 'page-sitemap.xml', 'sitemap_index.xml'),
            'normal_request_count_without_redirects' => 9,
            'max_redirects_per_request' => RB_MAX_REDIRECTS,
        ),
        'limitations' => array(
            'Responses are requested from this host, not from a Googlebot IP; a simulated user-agent is not Googlebot.',
            'A successful response does not guarantee that Google crawled, selected a canonical, or indexed a page.',
            'Cache, request order, and rate limiting can affect observed differences between the two user-agents.',
            'HTML analysis is raw source only: it cannot execute JavaScript or show visual rendering.',
            'robots.txt evaluation is approximate and X-Robots-Tag directive words are not agent-scoped.',
            'No access logs or WordPress configuration are read; an HTTP request can still appear in normal server logs or caches.',
            'TLS is verified and configured proxies are bypassed; network failures here do not establish what Google sees.',
            'Output contains limited metadata, not raw pages or logs, but review it for sensitive information before sharing.',
        ),
    );
    $allowed = array_values($urls);
    $robotsChain = rb_request_chain($urls['robots'], RB_BROWSER_UA, $allowed, $transport);
    $robotsResponse = $robotsChain['response'];
    $robotsInfo = rb_public_response($robotsChain);
    $robotsInfo['response_looks_like_html'] = null;
    $robotsInfo['googlebot_rules_approximate'] = null;
    if (is_array($robotsResponse) && ($robotsResponse['status'] ?? null) === 200 && empty($robotsResponse['truncated']) && (int) ($robotsResponse['curl_error_code'] ?? 0) === 0) {
        $robotsBody = (string) $robotsResponse['body'];
        $robotsInfo['response_looks_like_html'] = in_array($robotsInfo['content_type'], array('text/html', 'application/xhtml+xml'), true) ||
            preg_match('~^\s*(?:<!doctype\s+html|<html\b|<head\b|<body\b)~i', $robotsBody) === 1;
        if (!$robotsInfo['response_looks_like_html']) {
            $robotsInfo['googlebot_rules_approximate'] = array();
            foreach ($targets as $name => $url) {
                $robotsInfo['googlebot_rules_approximate'][$name] = rb_robots_decision($robotsBody, $url);
            }
        }
    }
    $result['robots_txt'] = $robotsInfo;
    foreach (array('page_sitemap' => 'urlset', 'sitemap_index' => 'index') as $name => $kind) {
        $chain = rb_request_chain($urls[$name], RB_BROWSER_UA, $allowed, $transport);
        $response = $chain['response'];
        $info = rb_public_response($chain);
        $info['xml_parseable'] = null;
        $info['entries'] = null;
        if (is_array($response) && ($response['status'] ?? null) === 200 && empty($response['truncated']) && (int) ($response['curl_error_code'] ?? 0) === 0) {
            $xml = rb_parse_sitemap((string) $response['body'], $kind);
            $info['xml_parseable'] = $xml['ok'];
            if ($xml['ok']) {
                $info['entries'] = array();
                $needles = $name === 'page_sitemap' ? $targets : array('page_sitemap' => $urls['page_sitemap']);
                foreach ($needles as $label => $needle) {
                    $key = rb_url_key($needle);
                    $info['entries'][$label] = array('present' => array_key_exists($key, $xml['entries']), 'lastmod' => $xml['entries'][$key] ?? null);
                }
            }
        }
        $result[$name] = $info;
    }
    $result['pages'] = array();
    foreach ($targets as $name => $url) {
        $result['pages'][$name] = array();
        foreach (array('browser_ua' => RB_BROWSER_UA, 'googlebot_ua_simulated_not_google' => RB_SIMULATED_GOOGLEBOT_UA) as $agent => $ua) {
            $chain = rb_request_chain($url, $ua, $allowed, $transport);
            $response = $chain['response'];
            $info = rb_public_response($chain);
            $info['raw_html'] = null;
            $contentType = $info['content_type'] ?? null;
            $htmlType = $contentType === null || in_array($contentType, array('text/html', 'application/xhtml+xml'), true);
            if (is_array($response) && ($response['status'] ?? null) === 200 && (string) ($response['body'] ?? '') !== '' && $htmlType) {
                $partial = !empty($response['truncated']) || (int) ($response['curl_error_code'] ?? 0) !== 0;
                $info['raw_html'] = rb_analyze_html((string) $response['body'], (string) $chain['url'], $targets, $partial, $url);
            }
            $result['pages'][$name][$agent] = $info;
        }
    }
    rb_emit($result);
    return 0;
}

if (!defined('RB_DIAG_LIBRARY_ONLY')) {
    exit(rb_main($argv));
}
