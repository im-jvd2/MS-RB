<?php
declare(strict_types=1);

define('RB_DIAG_LIBRARY_ONLY', true);
require __DIR__ . '/diagnose.php';

// The production CLI suppresses notices to keep JSON clean; tests must fail visibly.
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fake_response(int $status, array $headers = array(), string $body = ''): array
{
    return array('status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => false, 'curl_error_code' => 0, 'elapsed_ms' => 5);
}

$urls = rb_urls();
$targets = rb_targets();
$allowed = array_values($urls);

// Fixed destinations, relative links and redirect safety.
check(count($urls) === 6 && count($targets) === 3, 'only three pages and three support files may be requested');
check(!rb_is_allowed_url('https://royalbrand.vip/', $allowed), 'the homepage must not be fetched');
check(!rb_is_allowed_url('https://royalbrand.vip/services/کمپین-پزشکی/', $allowed), 'no fourth page may be fetched');
check(rb_is_allowed_url($urls['hub'], $allowed), 'hub must be allowlisted');
check(rb_is_allowed_url('https://royalbrand.vip/services/کمپین-برندینگ/', $allowed), 'Unicode and encoded paths should compare equal');
check(!rb_is_allowed_url('http://royalbrand.vip/services/', $allowed), 'HTTP must be rejected');
check(!rb_is_allowed_url('https://evil.example/services/', $allowed), 'external host must be rejected');
check(!rb_is_allowed_url('https://user:secret@royalbrand.vip/services/', $allowed), 'userinfo must be rejected');
check(!rb_is_allowed_url($urls['hub'] . '?token=PRIVATE_TOKEN', $allowed), 'query strings must be rejected');
check(!rb_is_allowed_url('https://royalbrand.vip/services/../wp-admin/', $allowed), 'unlisted paths must be rejected');
check(rb_url_key(rb_resolve_url($urls['hub'], './کمپین-برندینگ/')) === rb_url_key($urls['branding']), 'relative path resolution');
check(rb_resolve_url($urls['hub'], 'javascript:alert(1)') === null, 'non-web links must be ignored');
check(strpos((string) rb_safe_url('https://royalbrand.vip/cpsessTESTFIXTURE/file?token=PRIVATE_TOKEN'), 'PRIVATE_TOKEN') === false, 'query token must be hidden');
check(strpos((string) rb_safe_url('https://royalbrand.vip/cpsessTESTFIXTURE/file?token=PRIVATE_TOKEN'), 'cpsessTESTFIXTURE') === false, 'unrelated path must be hidden');
check(strpos((string) rb_safe_url('https://elsewhere.example/private?token=PRIVATE_TOKEN'), '/private') === false, 'external paths must be hidden');
check(strpos((string) rb_safe_url('https://secret-name.example/private'), 'secret-name') === false, 'external hosts must be hidden');
check(strpos((string) rb_safe_url('https://royalbrand.vip/services/#PRIVATE_FRAGMENT'), 'PRIVATE_FRAGMENT') === false, 'fragments must be hidden');
check(strpos((string) rb_safe_url('https://royalbrand.vip/services/#PRIVATE_FRAGMENT'), '#[fragment-redacted]') !== false, 'redacted fragments must be signaled');
check(strpos((string) rb_safe_url('https://royalbrand.vip:8443/services/'), ':8443') !== false, 'nonstandard port must remain visible');

$requests = array();
$redirectTransport = function ($url, $ua) use (&$requests, $urls) {
    $requests[] = $url;
    if (rb_url_key($url) === rb_url_key($urls['branding'])) {
        return fake_response(301, array('location' => array($urls['campaign_360'])));
    }
    return fake_response(200, array('content-type' => array('text/html; charset=UTF-8')), '<html><body>ok</body></html>');
};
$chain = rb_request_chain($urls['branding'], 'test', $allowed, $redirectTransport);
check(count($chain['hops']) === 2 && $chain['hops'][0]['status'] === 301 && $chain['hops'][1]['status'] === 200, 'allowed redirect chain');
check(count($requests) === 2, 'expected exactly two requests');

$calls = 0;
$externalTransport = function ($url, $ua) use (&$calls) {
    $calls++;
    return fake_response(302, array('location' => array('https://other.example/secret?token=PRIVATE_TOKEN')));
};
$chain = rb_request_chain($urls['hub'], 'test', $allowed, $externalTransport);
check($calls === 1 && $chain['stop_reason'] === 'redirect_not_followed_destination_not_allowlisted', 'external redirects must not be followed');
check(strpos((string) json_encode($chain['hops']), 'PRIVATE_TOKEN') === false, 'redirect query must not be output');

$calls = 0;
$queryTransport = function ($url, $ua) use (&$calls, $urls) {
    $calls++;
    return fake_response(302, array('location' => array($urls['hub'] . '?token=PRIVATE_TOKEN')));
};
$chain = rb_request_chain($urls['hub'], 'test', $allowed, $queryTransport);
check($calls === 1 && $chain['stop_reason'] === 'redirect_not_followed_destination_not_allowlisted', 'query redirects must not be followed');
$loopTransport = function ($url, $ua) { return fake_response(301, array('location' => array($url))); };
check(rb_request_chain($urls['hub'], 'test', $allowed, $loopTransport)['stop_reason'] === 'redirect_loop', 'redirect loop detection');

// HTML checks intentionally use raw source, not JS execution or Google-selected canonical.
$html = '<!doctype html><html><head><title>Public title</title>' .
    '<link rel="canonical" href="' . $urls['hub'] . '">' .
    '<meta name="robots" content="noindex, nofollow"><meta name="googlebot" content="index, follow">' .
    '</head><body><h1>Public title</h1><p>Visible text</p>' .
    '<a href="' . $urls['branding'] . '" rel="nofollow">Branding</a>' .
    '<a href="' . $urls['campaign_360'] . '">360</a>' .
    '<a href="https://mahdishahnazari.com/example/">Approved personal-site link</a>' .
    '<script>PRIVATE_SCRIPT_TEXT</script></body></html>';
$parsed = rb_analyze_html($html, $urls['hub'], $targets, false);
check($parsed['html_parseable'] && $parsed['h1_count'] === 1, 'HTML and H1');
check($parsed['canonical_html_count'] === 1 && $parsed['canonical_in_head_count'] === 1 && $parsed['canonical_matches_final_url'] === true && $parsed['canonical_matches_requested_url'] === true, 'self canonical');
check($parsed['robots_meta'][0]['in_head'] === true, 'meta location');
check($parsed['noindex_in_relevant_meta'] === true, 'noindex meta detected');
check($parsed['links_to_targets']['branding']['anchor_href_count'] === 1 && $parsed['links_to_targets']['branding']['nofollow_count'] === 1, 'anchor and nofollow counts');
check($parsed['links_to_targets']['campaign_360']['anchor_href_count'] === 1, '360 link');
check(strpos((string) json_encode($parsed), 'PRIVATE_SCRIPT_TEXT') === false, 'HTML body must not be emitted');
$missingCanonical = rb_analyze_html('<html><head><link rel="canonical" href=""></head><body>x</body></html>', $urls['hub'], $targets, false);
check($missingCanonical['canonical_html_count'] === 1 && $missingCanonical['canonical_invalid_or_fragment_count'] === 1, 'invalid canonical must not be silently ignored');
$fragmentCanonical = rb_analyze_html('<html><head><link rel="canonical" href="' . $urls['hub'] . '#fragment"></head><body>x</body></html>', $urls['hub'], $targets, false);
check($fragmentCanonical['canonical_invalid_or_fragment_count'] === 1, 'canonical fragment must be reported');
$bodyCanonical = rb_analyze_html('<html><head></head><body><link rel="canonical" href="' . $urls['hub'] . '"><meta name="robots" content="noindex"></body></html>', $urls['hub'], $targets, false);
check($bodyCanonical['canonical_in_head_count'] === 0 && $bodyCanonical['canonical_outside_head_count'] === 1 && $bodyCanonical['canonical_matches_requested_url'] === null, 'body canonical must not be reported as an accepted head canonical');
check($bodyCanonical['robots_meta'][0]['in_head'] === false, 'meta outside the head is flagged');
$partial = rb_analyze_html('<html><body>partial', $urls['hub'], $targets, true);
check($partial['partial_body'] && $partial['noindex_in_relevant_meta'] === null, 'partial response is inconclusive');
$afterRedirect = rb_analyze_html('<link rel="canonical" href="' . $urls['branding'] . '"><body>ok</body>', $urls['branding'], $targets, false, $urls['hub']);
check($afterRedirect['canonical_matches_final_url'] === true && $afterRedirect['canonical_matches_requested_url'] === false, 'requested and final canonical comparisons must be distinct');
$persian = rb_analyze_html('<html><body>سلام</body></html>', $urls['hub'], $targets, false);
check($persian['source_body_text_codepoints_approx'] === 4 && $persian['source_body_text_utf8_bytes'] === 8, 'Unicode code points and bytes must not be confused');
$withBase = rb_analyze_html('<html><head><base href="' . $urls['hub'] . '"><link rel="canonical" href="کمپین-برندینگ/"></head><body><a href="کمپین-360-درجه/">360</a></body></html>', $urls['branding'], $targets, false);
check($withBase['html_base_href_usable'] === true && $withBase['canonical_matches_final_url'] === true, 'relative canonical respects HTML base');
check($withBase['links_to_targets']['campaign_360']['anchor_href_count'] === 1, 'relative anchor respects HTML base');
check(rb_analyze_html('<title>Just a moment...</title>', $urls['hub'], $targets, false)['possible_challenge_marker_heuristic'] === true, 'challenge heuristic');
$headerLinks = array('link' => array('<' . $urls['hub'] . '>; rel="canonical", <https://example.org/>; rel="alternate"'));
check(count(rb_header_canonicals($headerLinks, $urls['hub'])) === 1, 'HTTP canonical header');

// robots.txt group specificity, wildcard, longest match, equal-length allow precedence.
$robots = "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow: /services/کمپین-360-درجه/\nAllow: /services/\n";
check(rb_robots_decision($robots, $urls['hub'])['decision'] === 'allowed', 'specific Googlebot group');
check(rb_robots_decision($robots, $urls['campaign_360'])['decision'] === 'blocked', 'specific disallow');
$robotsTie = "User-agent: Googlebot\nDisallow: /services/*\nAllow: /services/*\n";
check(rb_robots_decision($robotsTie, $urls['branding'])['decision'] === 'allowed', 'equal rules prefer allow');
$robotsEnd = "User-agent: Googlebot\nDisallow: /services/$\n";
check(rb_robots_decision($robotsEnd, $urls['hub'])['decision'] === 'blocked', 'end-anchored rule');
check(rb_robots_decision($robotsEnd, $urls['branding'])['decision'] === 'allowed', 'end anchor should not match longer path');

// Sitemap parsing requires the right root and refuses DTDs.
$xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . $urls['hub'] . '</loc><lastmod>2020-01-02T03:04:05+00:00</lastmod></url></urlset>';
$sitemap = rb_parse_sitemap($xml, 'urlset');
check($sitemap['ok'] && array_key_exists(rb_url_key($urls['hub']), $sitemap['entries']), 'valid namespaced sitemap');
check(!rb_parse_sitemap('<html><body>not a sitemap</body></html>', 'urlset')['ok'], 'wrong XML root must be inconclusive');
check(!rb_parse_sitemap('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset><url><loc>&x;</loc></url></urlset>', 'urlset')['ok'], 'DTD must be refused');

// Exercise the complete report pipeline offline. Fixture responses are NOT host observations.
$requests = array();
$fixture = function ($url, $ua) use (&$requests, $urls, $targets, $allowed, $robots, $xml) {
    check(rb_is_allowed_url($url, $allowed), 'pipeline requested an unlisted URL');
    $requests[] = array($url, $ua);
    if ($url === $urls['robots']) {
        return fake_response(200, array('content-type' => array('text/plain; charset=UTF-8')), $robots);
    }
    if ($url === $urls['page_sitemap']) {
        return fake_response(200, array('content-type' => array('application/xml')), $xml);
    }
    if ($url === $urls['sitemap_index']) {
        return fake_response(200, array('content-type' => array('application/xml')), '<sitemapindex><sitemap><loc>' . $urls['page_sitemap'] . '</loc></sitemap></sitemapindex>');
    }
    if ($url === $urls['branding'] && $ua === RB_BROWSER_UA) {
        return fake_response(302, array('location' => array('https://private-host.example/private?token=PRIVATE_TOKEN')));
    }
    if ($url === $urls['campaign_360'] && $ua === RB_BROWSER_UA) {
        return fake_response(200, array('content-type' => array('application/pdf')), 'not HTML');
    }
    $meta = $url === $urls['hub'] && $ua === RB_SIMULATED_GOOGLEBOT_UA ? '<meta name="robots" content="noindex">' : '';
    $body = '<html><head><link rel="canonical" href="' . $url . '">' . $meta . '</head><body><h1>Title</h1>' .
        '<a href="' . $urls['branding'] . '">Branding</a><a href="' . $urls['campaign_360'] . '">360</a>' .
        '<p>PRIVATE_HTML_TEXT</p></body></html>';
    $response = fake_response(200, array('content-type' => array('text/html'), 'x-robots-tag' => array('otherbot: noindex'), 'set-cookie' => array('secret=PRIVATE_COOKIE')), $body);
    if ($url === $urls['campaign_360'] && $ua === RB_SIMULATED_GOOGLEBOT_UA) {
        $response['truncated'] = true;
        $response['curl_error_code'] = 23;
    }
    return $response;
};
ob_start();
$exitCode = rb_main(array('diagnose.php'), $fixture);
$output = (string) ob_get_clean();
$report = json_decode($output, true);
check($exitCode === 0 && is_array($report), 'full report must be valid JSON');
check(count($requests) === 9, 'only nine fixture requests are expected when redirects are blocked');
check(count($report['pages']) === 3 && !isset($report['link_sources']) && !isset($report['access_log']), 'only requested page scope, no log reading');
check($report['robots_txt']['googlebot_rules_approximate']['campaign_360']['decision'] === 'blocked', 'robots result in report');
check($report['page_sitemap']['entries']['hub']['present'] === true && $report['page_sitemap']['entries']['campaign_360']['present'] === false, 'sitemap presence in report');
check($report['sitemap_index']['entries']['page_sitemap']['present'] === true, 'sitemap index presence in report');
check($report['pages']['hub']['browser_ua']['raw_html']['noindex_in_relevant_meta'] === false, 'browser robots fixture');
check($report['pages']['hub']['googlebot_ua_simulated_not_google']['raw_html']['noindex_in_relevant_meta'] === true, 'simulated agent robots fixture');
check($report['pages']['hub']['browser_ua']['x_robots_tag_directive_words_unscoped'][0][0] === 'noindex', 'header directive words must remain unscoped');
check($report['pages']['branding']['browser_ua']['stop_reason'] === 'redirect_not_followed_destination_not_allowlisted', 'external redirect blocked in report');
check($report['pages']['campaign_360']['browser_ua']['content_type'] === 'application/pdf' && $report['pages']['campaign_360']['browser_ua']['raw_html'] === null, 'non-HTML responses must not be treated as HTML');
check($report['pages']['campaign_360']['googlebot_ua_simulated_not_google']['stop_reason'] === 'response_body_limit_reached', 'truncated transfer has an explicit stop reason');
check($report['pages']['campaign_360']['googlebot_ua_simulated_not_google']['raw_html']['partial_body'] === true, 'truncated body reflected in report');
check($report['pages']['campaign_360']['googlebot_ua_simulated_not_google']['raw_html']['noindex_in_relevant_meta'] === null, 'noindex is inconclusive for incomplete source');
foreach (array('PRIVATE_TOKEN', 'private-host.example', 'PRIVATE_COOKIE', 'PRIVATE_HTML_TEXT') as $secret) {
    check(strpos($output, $secret) === false, 'fixture secret leaked: ' . $secret);
}

ob_start();
$invalidExit = rb_main(array('diagnose.php', '--log=/private/PRIVATE_PATH'), $fixture);
$invalidOutput = (string) ob_get_clean();
check($invalidExit === 2 && strpos($invalidOutput, 'unsupported_argument') !== false && strpos($invalidOutput, 'PRIVATE_PATH') === false, 'local files and arbitrary arguments are rejected');
check(count($requests) === 9, 'invalid arguments must not trigger HTTP');

$failedTransport = function ($url, $ua) { return array('status' => null, 'headers' => array(), 'body' => '', 'truncated' => false, 'curl_error_code' => 6, 'elapsed_ms' => 5); };
ob_start();
$failedExit = rb_main(array('diagnose.php'), $failedTransport);
$failed = json_decode((string) ob_get_clean(), true);
check($failedExit === 0 && $failed['robots_txt']['googlebot_rules_approximate'] === null && $failed['robots_txt']['stop_reason'] === 'curl_error', 'network error must not claim robots allowed or imply a response');
check($failed['page_sitemap']['entries'] === null && $failed['pages']['hub']['browser_ua']['raw_html'] === null, 'network error must leave unobserved results unknown');

$htmlRobots = function ($url, $ua) use ($urls) {
    if ($url === $urls['robots']) {
        return fake_response(200, array('content-type' => array('text/html')), '<html><body>Challenge</body></html>');
    }
    return fake_response(503);
};
ob_start();
rb_main(array('diagnose.php'), $htmlRobots);
$challengedRobots = json_decode((string) ob_get_clean(), true);
check($challengedRobots['robots_txt']['response_looks_like_html'] === true && $challengedRobots['robots_txt']['googlebot_rules_approximate'] === null, 'HTML response to robots must be inconclusive');

echo "All diagnostic fixture tests passed.\n";
