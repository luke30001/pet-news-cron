<?php
/**
 * Generates STORIES_PER_RUN (default 1) fresh pet/animal news stories via Gemini, each in
 * 5 languages, and publishes them to sofiaprints.com via the WP REST API. Vanilla PHP, no
 * extensions beyond what ships with PHP core, no WordPress bootstrap — so it can (and
 * should) run from a DIFFERENT server than the site: the 2026-07-03 setup, where this ran
 * hourly on the Aruba box itself with 4 stories/run (~20 posts, 6-7 min per run), held the
 * site's ~8 shared PHP workers long enough to 503 the whole site. MIN_MINUTES_BETWEEN_RUNS
 * (default 10) additionally rate-limits runs server-side no matter how often cron fires.
 *
 * Two-phase generation, same model + same tools (googleSearch + urlContext) both times:
 *   1. generateTitles() — a plain-text EN headline outline (scaletta), no bodies yet.
 *   2. generateBodies() — given that confirmed title list, writes the EN body plus IT/DE/FR/ES
 *      translations (title + body) for each, with <em>/<u> emphasis around key phrases for
 *      on-page scannability. Titles stay plain text — no HTML tags in any language.
 *
 * No multilingual plugin (Polylang/WPML) is installed. Each language still gets its own WP
 * category (pet-news, pet-news-it, pet-news-de, pet-news-fr, pet-news-es) for organization,
 * and each post naturally gets its own distinct URL since the translated title produces a
 * different slug per language (this site's permalinks are flat /post-name/, not category-
 * prefixed). The 5 sibling posts for one story are linked via a single post meta field set
 * right after the group is created (_pet_news_alternates — a lang => URL JSON map), read back
 * with a plain get_post_meta() and rendered as hreflang <link> tags by the mu-plugin
 * pet-news-hreflang.php. Deliberately NOT looked up with a WP_Query/get_posts() at view time:
 * an earlier version did that and it blocked page caching + caused timeouts under load.
 *
 * Meant to live next to pet-news-cron.config.php (see pet-news-cron.config.example.php) on
 * any box with PHP 8.1+ and be run from cron, e.g. one story every 10 minutes:
 *   *\/10 * * * * php /path/to/pet-news-cron.php >> /path/to/pet-news-cron.cron.log 2>&1
 * Web mode (upload next to the config, trigger via curl with ?token=CRON_SECRET) still
 * works but is discouraged when the host is the live site itself.
 */

declare(strict_types=1);

// Runs in two modes: web (legacy, token-protected — how it ran on Aruba) and CLI
// (current setup: cron on an external server, e.g. `*/10 * * * * php pet-news-cron.php`).
// Running it OFF the Aruba box matters: the orchestration (two Gemini calls with live
// search) used to hold one of the site's ~8 shared PHP workers for minutes per run;
// externally, only the short WP REST publish calls touch the live site.
define('PET_NEWS_CLI', PHP_SAPI === 'cli');
if (!PET_NEWS_CLI) {
    header('Content-Type: application/json');
}

// mbstring fallbacks so the script runs on hosts without the extension. The mb_*
// calls here only do duplicate-title comparison and excerpt/slug trimming, where
// byte-based behavior merely means slightly stricter dedup of accented words and
// the (defensive, UTF-8-safe-enough) truncation points shifting by a few chars.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s): string
    {
        return strtolower($s);
    }
    function mb_strlen(string $s): int
    {
        return strlen($s);
    }
    function mb_substr(string $s, int $start, ?int $length = null): string
    {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
    function mb_strrpos(string $haystack, string $needle, int $offset = 0)
    {
        return strrpos($haystack, $needle, $offset);
    }
}
// Two sequential Gemini calls (titles, then bodies+translations), each with live search
// tools, can each take up to ~240s worst case, plus WP REST round-trips for up to 20 post
// creates and up to 20 lightweight meta-only follow-up updates (hreflang linking).
// Deliberately no retry/pacing sleeps: this script is a request on the live site's own shared
// PHP worker pool, so keeping runtime short matters more than squeezing out a few extra
// successful creates (see publishPost()).
set_time_limit(700);

// Target languages: WP category slug suffix => human name (used in the translation prompt).
// 'en' has no slug suffix — it publishes to the base $categorySlug.
const PET_NEWS_LANGS = [
    'en' => 'English',
    'it' => 'Italian',
    'de' => 'German',
    'fr' => 'French',
    'es' => 'Spanish',
];

// Hardcoded selectivity gate: only headlines the model itself forecasts 'high' trend-rise
// potential get a body written; 'low'/'moderate' ones are dropped after the title phase.
// Deliberately NOT mentioned anywhere in the Gemini prompt (see generateTitles()) — the
// model is only ever asked to *forecast* trend-rise potential, never told that a threshold
// decides what gets published, so it can't learn to game the rating by always answering
// 'high'. Runs on the cron's normal ~10-minute cadence — most runs are expected to produce
// a candidate that gets rejected here and nothing gets published; that's the point (cheap,
// frequent checks instead of one guaranteed daily post) so a fast-emerging trend can still
// be caught within minutes instead of waiting up to a day.
const PET_NEWS_MIN_TREND_RISE = 'high';

// Fixed topic taxonomy (WP tags, not categories — categories are already used to encode
// language). Slugs are stable across languages so the same story topic groups together
// site-wide; display names are translated once here, not left to the model, so the tag
// list stays small and consistent instead of drifting into one-off tags per article.
const PET_NEWS_TOPICS = [
    'health'    => ['en' => 'Health & Vet Care',        'it' => 'Salute & Veterinaria',        'de' => 'Gesundheit & Tierarzt',        'fr' => 'Santé & Vétérinaire',          'es' => 'Salud y Veterinaria'],
    'safety'    => ['en' => 'Safety & Alerts',           'it' => 'Sicurezza & Allerta',          'de' => 'Sicherheit & Warnungen',       'fr' => 'Sécurité & Alertes',           'es' => 'Seguridad y Alertas'],
    'wildlife'  => ['en' => 'Wildlife & Conservation',   'it' => 'Fauna Selvatica & Conservazione', 'de' => 'Wildtiere & Naturschutz',   'fr' => 'Faune Sauvage & Conservation', 'es' => 'Fauna Silvestre y Conservación'],
    'adoption'  => ['en' => 'Adoption & Rescue',         'it' => 'Adozione & Salvataggio',       'de' => 'Adoption & Rettung',           'fr' => 'Adoption & Sauvetage',         'es' => 'Adopción y Rescate'],
    'industry'  => ['en' => 'Products & Trends',         'it' => 'Prodotti & Novità',            'de' => 'Produkte & Trends',            'fr' => 'Produits & Tendances',         'es' => 'Productos y Tendencias'],
    'lifestyle' => ['en' => 'Pet Lifestyle',             'it' => 'Vita con il Pet',              'de' => 'Leben mit Haustieren',         'fr' => 'Vie avec son Animal',          'es' => 'Vida con Mascotas'],
];

// ---- review mode: generates an Amazon-affiliate pet-product review via a SINGLE OpenAI ----
// ---- Responses API call (gpt-5.4-nano + web_search tool) instead of Gemini/per-locale calls ----
// One request returns all 5 marketplaces' title+body at once; each can be a different product,
// chosen independently per that marketplace's own national trends.

// Amazon marketplace per language. 'en' -> amazon.com (not amazon.it): the business's EU
// Associates tag may not earn on .com (a separate US Associates program) — links still work,
// just flagged as a possible monetization gap on this one marketplace.
const PET_REVIEW_AMAZON_TLD = [
    'en' => 'com',
    'it' => 'it',
    'de' => 'de',
    'fr' => 'fr',
    'es' => 'es',
];

// Literal token the model is told to place exactly once in the body, at the natural spot for
// a buy-now call to action. Replaced server-side — never model-authored HTML — with a real
// <a> tag pointing at the validated, tag-appended Amazon URL for that language. Plain text, no
// angle brackets, so it survives sanitizeEmphasisHtml() untouched.
const PET_REVIEW_LINK_TOKEN = '[AMAZON_LINK]';

// Fixed, server-authored affiliate disclosure appended to every review post in every language.
// Deliberately never left to the model (unlike the rest of the body) so it can never be
// missing, watered down, or forgotten — this is a legal requirement of the Amazon Associates
// program and EU consumer-protection rules on affiliate marketing, not just good practice.
const PET_REVIEW_DISCLOSURE = [
    'en' => 'Disclosure: this article contains an Amazon affiliate link. As an Amazon Associate, we may earn from qualifying purchases, at no extra cost to you.',
    'it' => 'Nota: questo articolo contiene un link di affiliazione Amazon. In qualità di Affiliati Amazon, potremmo ricevere un compenso per gli acquisti idonei, senza alcun costo aggiuntivo per te.',
    'de' => 'Hinweis: Dieser Artikel enthält einen Amazon-Partnerlink. Als Amazon-Partner können wir durch qualifizierte Käufe eine Provision verdienen, ohne zusätzliche Kosten für dich.',
    'fr' => "Note : cet article contient un lien d'affiliation Amazon. En tant que Partenaires Amazon, nous pouvons percevoir une commission sur les achats éligibles, sans coût supplémentaire pour vous.",
    'es' => 'Aviso: este artículo contiene un enlace de afiliado de Amazon. Como Afiliados de Amazon, podemos ganar una comisión por compras que cumplan los requisitos, sin coste adicional para ti.',
];

// Review-mode confidence gate REMOVED 2026-07-07 (product decision): the model's self-rated
// confidence (low/moderate/high) is still recorded in the log for visibility, but it no longer
// filters anything — every candidate proceeds regardless. (Was: const PET_REVIEW_MIN_CONFIDENCE
// = 'high', matched with an exact !== at the call site, which skipped ~all low/moderate picks.)

// Short, natural link text for the buy-CTA anchor — deliberately NOT the post title (which
// would read as an oddly repeated headline dropped mid-sentence into the model's CTA sentence).
const PET_REVIEW_LINK_TEXT = [
    'en' => 'on Amazon',
    'it' => 'su Amazon',
    'de' => 'bei Amazon',
    'fr' => 'sur Amazon',
    'es' => 'en Amazon',
];

$dir = __DIR__;
$logFile = $dir . '/pet-news-cron.log';
$errorLogFile = $dir . '/pet-news-errors.log';
$lockFile = $dir . '/pet-news-cron.lock';

function logmsg(string $file, string $msg): void
{
    $ts = gmdate('Y-m-d\TH:i:s\Z');
    $line = "[$ts] $msg\n";
    file_put_contents($file, $line, FILE_APPEND);
    // Keep an operationally useful, compact log separate from the verbose run
    // log. Any error written through logmsg() is duplicated here automatically.
    if (str_starts_with($msg, 'ERROR') && !empty($GLOBALS['errorLogFile'])) {
        file_put_contents($GLOBALS['errorLogFile'], $line, FILE_APPEND);
    }
}

function fail(string $file, string $msg, int $httpStatus = 500): never
{
    logmsg($file, "ERROR: $msg");
    if (!PET_NEWS_CLI) {
        http_response_code($httpStatus);
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit(1);
}

// ---- config (sibling file, not web-servable — see pet-news-cron.config.example.php) ----
$configPath = $dir . '/pet-news-cron.config.php';
if (!is_file($configPath)) {
    fail($logFile, 'missing pet-news-cron.config.php next to this script');
}
$config = require $configPath;
require_once $dir . '/pet-news-dataforseo.php';

function cfg(array $config, string $key, string $logFile, ?string $default = null): string
{
    $val = trim((string) ($config[$key] ?? ''));
    if ($val === '') {
        if ($default !== null) {
            return $default;
        }
        fail($GLOBALS['logFile'], "missing required config key $key");
    }
    return $val;
}

// ---- shared-secret check (web mode only): the endpoint must reject randoms ----
if (!PET_NEWS_CLI) {
    $cronSecret = cfg($config, 'CRON_SECRET', $logFile);
    $providedSecret = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    if (!hash_equals($cronSecret, (string) $providedSecret)) {
        fail($logFile, 'invalid or missing token', 403);
    }
}

// ---- locking: skip this run if the previous one is still going ----
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    logmsg($logFile, 'Previous run still in progress, skipping.');
    echo json_encode(['ok' => true, 'skipped' => 'already running']);
    exit(0);
}

// ---- pacing: hard minimum spacing between runs, regardless of how often the ----
// ---- external cron fires. This is the server-side throttle that keeps the   ----
// ---- shared PHP worker pool breathing: the 2026-07-03 hourly 4-story runs   ----
// ---- (~20 posts each) were saturating the pool and 503ing the whole site.   ----
$lastRunFile = $dir . '/pet-news-cron.last-run';
$minMinutes = (int) cfg($config, 'MIN_MINUTES_BETWEEN_RUNS', $logFile, '10');
$bypassPacing = !PET_NEWS_CLI && isset($_GET['bypass_pacing']) && $_GET['bypass_pacing'] === '1';
if (!$bypassPacing && $minMinutes > 0 && is_file($lastRunFile) && (time() - (int) filemtime($lastRunFile)) < $minMinutes * 60) {
    echo json_encode(['ok' => true, 'skipped' => 'ran less than ' . $minMinutes . 'm ago']);
    exit(0);
}
if ($bypassPacing) {
    logmsg($logFile, 'Pacing bypassed for this authenticated web request.');
}
// Touch up front so even a failing/slow run counts toward the spacing window.
@touch($lastRunFile);

$geminiApiKey = cfg($config, 'GEMINI_API_KEY', $logFile);
$geminiModelId = cfg($config, 'GEMINI_MODEL_ID', $logFile, 'gemini-3.1-flash-lite');
$siteUrl = rtrim(cfg($config, 'LIVE_URL', $logFile), '/');
$wpUser = cfg($config, 'WP_REST_USER', $logFile);
$wpPass = cfg($config, 'WP_REST_APP_PASSWORD', $logFile);
$categorySlug = cfg($config, 'WP_CATEGORY_SLUG', $logFile, 'pet-news');
// Stories per run × 5 languages = posts per run. Keep this at 1 and pace volume
// with the external cron frequency + MIN_MINUTES_BETWEEN_RUNS instead of doing
// big batches: short runs hold a shared PHP worker for ~1-2 min instead of 6-7.
$storiesPerRun = max(1, (int) cfg($config, 'STORIES_PER_RUN', $logFile, '1'));
$reviewProbabilityPercent = max(0, min(100, (int) cfg($config, 'REVIEW_PROBABILITY_PERCENT', $logFile, '10')));
$reviewCategorySlug = cfg($config, 'WP_REVIEW_CATEGORY_SLUG', $logFile, 'product-reviews');
cfg($config, 'DATAFORSEO_LOGIN', $logFile);
cfg($config, 'DATAFORSEO_PASSWORD', $logFile);

// CLI-only test override: `php pet-news-cron.php --mode=review` (or --mode=news) forces which
// branch this invocation runs, bypassing the random draw below. Pacing/locking above still
// apply as normal — this only decides content type, not whether the run is allowed at all.
function cliForcedMode(array $argv): ?string
{
    foreach ($argv as $arg) {
        if (preg_match('/^--mode=(news|review)$/', (string) $arg, $m)) {
            return $m[1];
        }
    }
    return null;
}

// Web requests (already authenticated by the CRON_SECRET check above) can force a mode the
// same way CLI does, via `?mode=review` / `?mode=news`. This lets the external scheduler run
// two independent triggers instead of relying solely on the random draw below — review posts
// are already rare because of the confidence/Amazon-URL gates, and diluting them further by
// competing with the far more common news draws meant too many review slots were lost.
function webForcedMode(): ?string
{
    $mode = (string) ($_GET['mode'] ?? '');
    return preg_match('/^(news|review)$/', $mode) ? $mode : null;
}

$forcedMode = PET_NEWS_CLI ? cliForcedMode($argv ?? []) : webForcedMode();
// CLI-only `--dry-run`: run the full pipeline (OpenAI/Gemini calls, URL + tag validation) but
// skip the actual WP publish, logging what WOULD be created. Lets us validate the gates locally
// without mutating the live site. Currently honored by review mode.
$dryRun = PET_NEWS_CLI && in_array('--dry-run', $argv ?? [], true);
if ($dryRun) {
    logmsg($logFile, 'DRY-RUN: generation + validation will run, but nothing will be published to WP.');
}
$mode = $forcedMode ?? ((mt_rand(1, 100) <= $reviewProbabilityPercent) ? 'review' : 'news');
logmsg($logFile, "Mode: $mode" . ($forcedMode !== null ? (PET_NEWS_CLI ? ' (forced via --mode)' : ' (forced via ?mode=)') : " (random draw, {$reviewProbabilityPercent}% review chance)"));

// ---- generic HTTP helper over php streams (no curl extension on this box) ----
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSec = 30): array
{
    $headerLines = $headers;
    if ($body !== null && !array_filter($headers, fn($h) => stripos($h, 'Content-Type:') === 0)) {
        $headerLines[] = 'Content-Type: application/json';
    }

    // Altervista's shared host has broken/blackholed outbound IPv6: PHP's
    // file_get_contents stream wrapper tries the AAAA record first, fails fast,
    // and surfaces as a generic "no response" with no diagnostic detail. curl
    // with CURL_IPRESOLVE_V4 skips IPv6 entirely and reports a real error code
    // if it still fails. Confirmed fix (2026-07-14): forced this for the OpenAI
    // review call and its "no response" failures went to zero afterward.
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        $curlOpts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge($headerLines, ['Connection: close', 'Expect:']),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Aruba's edge appears to reset slow (20-30s+) backend responses
            // mid-stream; over HTTP/2 that surfaces as an opaque framing-layer
            // error (curl 16) instead of a clean timeout/reset. HTTP/1.1 doesn't
            // multiplex/frame the same way and tolerates this better.
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $curlOpts);
        $result = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($result === false || $errno !== 0) {
            throw new RuntimeException("request to $url failed (curl error $errno: $error)");
        }
        return ['status' => $status, 'body' => (string) $result];
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'timeout' => $timeoutSec,
            'ignore_errors' => true, // so we can read 4xx/5xx bodies instead of a warning
        ],
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    if ($result === false) {
        throw new RuntimeException("request to $url failed (no response)");
    }

    return ['status' => $status, 'body' => $result];
}

function basicAuthHeader(string $user, string $pass): string
{
    return 'Authorization: Basic ' . base64_encode("$user:$pass");
}

// A short, external heartbeat for the fulfillment flow. Hoplix credentials and
// order changes remain on Sofia Prints; Altervista only calls the authenticated
// REST task that checks whether Hoplix has shipped an order.
function syncHoplixShipments(string $siteUrl, string $wpUser, string $wpPass, string $logFile): void
{
    try {
        $resp = httpRequest(
            'POST',
            $siteUrl . '/wp-json/spp/v1/hoplix-sync-shipments',
            [basicAuthHeader($wpUser, $wpPass), 'Content-Type: application/json'],
            '{}',
            45
        );
        $data = json_decode($resp['body'], true);
        if ($resp['status'] >= 200 && $resp['status'] < 300 && is_array($data)) {
            logmsg($logFile, 'Hoplix shipment sync: checked=' . (int) ($data['checked'] ?? 0) . ', completed=' . count((array) ($data['completed'] ?? [])));
            return;
        }
        logmsg($logFile, 'Hoplix shipment sync failed: HTTP ' . $resp['status']);
    } catch (Throwable $e) {
        // The blog cron must still run if the storefront is temporarily unavailable.
        logmsg($logFile, 'Hoplix shipment sync failed: ' . $e->getMessage());
    }
}

if (!empty($config['HOPLIX_SHIPMENT_SYNC_ENABLED'])) {
    syncHoplixShipments($siteUrl, $wpUser, $wpPass, $logFile);
} else {
    logmsg($logFile, 'Hoplix shipment sync is disabled.');
}

// Resolve a category slug to its WP term id (0 if not found / lookup failed).
function fetchCategoryId(string $siteUrl, string $wpUser, string $wpPass, string $slug): int
{
    try {
        $url = $siteUrl . '/wp-json/wp/v2/categories?' . http_build_query(['slug' => $slug, '_fields' => 'id']);
        $resp = httpRequest('GET', $url, [basicAuthHeader($wpUser, $wpPass)]);
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            $rows = json_decode($resp['body'], true);
            return (int) ($rows[0]['id'] ?? 0);
        }
    } catch (Throwable $e) {
        // fall through — caller degrades to an unfiltered fetch
    }
    return 0;
}

// ---- 1. fetch titles published in the last 24h so Gemini doesn't repeat them ----
// Filtered to the EN category when possible: the model only ever generates English
// headlines, so the 4 translated siblings per story are dead weight here — and at
// hundreds of posts/day the unfiltered fetch was paging through 5x the REST results.
function fetchRecentTitles(string $siteUrl, string $wpUser, string $wpPass, int $hours = 24, int $categoryId = 0): array
{
    $since = gmdate('Y-m-d\TH:i:s', time() - $hours * 3600);
    $titles = [];
    $page = 1;
    $auth = basicAuthHeader($wpUser, $wpPass);

    while ($page <= 20) {
        $args = [
            'after' => $since,
            'per_page' => 100,
            'page' => $page,
            'status' => 'publish',
            '_fields' => 'title',
            'orderby' => 'date',
            'order' => 'desc',
        ];
        if ($categoryId > 0) {
            $args['categories'] = $categoryId;
        }
        $url = $siteUrl . '/wp-json/wp/v2/posts?' . http_build_query($args);
        $resp = httpRequest('GET', $url, [$auth]);
        if ($resp['status'] === 400 && $page > 1) {
            break;
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException("WP posts fetch failed with status {$resp['status']}: {$resp['body']}");
        }
        $batch = json_decode($resp['body'], true);
        if (empty($batch)) {
            break;
        }
        foreach ($batch as $item) {
            $titles[] = html_entity_decode($item['title']['rendered'], ENT_QUOTES);
        }
        $page++;
        if (count($batch) < 100) {
            break;
        }
    }
    return $titles;
}

// Shared "mission" block reused verbatim by both phases so the model investigates the
// same way whether it's picking headlines or writing their bodies.
const PET_NEWS_MISSION = <<<'TXT'
You are a real-time SEO traffic intelligence assistant for a pets and animals news website.

Your mission is to detect fast-rising animal and pet topics early enough to capture traffic before competitors and before a traffic drop happens. You must use live search tools every time before generating ideas.

Focus on the most time-sensitive and traffic-sensitive topics possible, including:
- Breaking pet and animal news from the last few hours
- Sudden spikes in Google Search interest
- Emerging Google Trends-style breakout queries
- Viral animal stories spreading across search and news
- Pet food recalls, animal health alerts, disease warnings, weather-related pet safety risks, wildlife incidents, rescue stories, shelter stories, celebrity pet stories, and unusual animal behavior stories
- Seasonal or location-based topics likely to spike quickly
- Search queries that may explain an upcoming rise or crash in visits

Your goal is not only to generate article ideas, but to protect and grow traffic by spotting what people are starting to search for right now.
TXT;

// ---- 2a. call Gemini to build the title outline (scaletta) — headlines only, no bodies yet ----
function generateTitles(string $apiKey, string $modelId, array $previousTitles, int $storiesPerRun): array
{
    $systemInstruction = PET_NEWS_MISSION . <<<'TXT'


Before creating titles, investigate:
1. What pet and animal topics appear to be rising right now?
2. Which stories are new, urgent, emotional, useful, or likely to go viral?
3. Which topics could create sudden traffic opportunities today?
4. Which topics could explain or prevent a drop in traffic if the site does not cover them?
5. Which angles are not already covered by the previously created titles?

Use the user's provided list of previously created titles as memory. Do not repeat, paraphrase, or slightly rewrite those titles. Create fresh angles only.

Generate exactly {{STORIES_PER_RUN}} new headlines. Since so few are produced, each one must be the single best, most compelling real pet/animal news story of the day — not just an acceptable one. This is a title outline only — do not write article bodies yet. For each headline also pick the single best-fitting topic from this fixed list (used for site navigation, not a creative field): health, safety, wildlife, adoption, industry, lifestyle.
- health: veterinary care, disease, treatment, medical research
- safety: recalls, warnings, hazards, accidents, urgent alerts
- wildlife: wild animals, conservation, ecosystems, non-pet species
- adoption: rescue, shelters, adoption stories, animal welfare organizations
- industry: pet products, market trends, company news, new launches
- lifestyle: everyday pet care, behavior, travel, home life with pets

This must be forward-looking, not backward-looking: reject any topic that is already famous, already viral, or already saturated in search/media — by the time something is confirmed-popular it's too late to be first. Also reject banal, generic, or predictable angles — a headline that reads like a hundred others already published elsewhere is a fail even if it's timely. Use live search only to sense-check faint, early signals, then make an actual prediction about where that thread is heading next — anticipate a trend before it's obvious, don't report one everyone else has already spotted.

For each headline also forecast its trend-rise potential: your genuine prediction of whether this specific, original angle is still early and likely to grow bigger from here — not whether it is already popular. Answer low, moderate, or high.

Prioritize headline ideas with:
- High urgency
- High emotional appeal
- Clear search intent
- Strong upward trajectory that hasn't peaked yet — early signal over confirmed mainstream popularity
- Originality — a genuinely fresh angle, never a banal or predictable one
- Practical reader value
- Freshness from the last few hours whenever possible

Headline rules:
- Make titles specific, timely, and search-friendly
- Target 55–85 characters when possible, hard maximum 95 characters — the title must be a complete, grammatical headline (never end on an article, preposition, or conjunction such as "the", "delle", "des", "und")
- Prefer a shorter complete headline over a longer one that would need to be cut mid-phrase
- Use words such as now, today, warning, recall, alert, viral, rescue, vet, dog, cat, pet owners, wildlife, or animal shelter only when genuinely relevant
- Go all-in on making each headline as attention-grabbing as possible — the only limit is accuracy: never claim something the article can't back up
- Avoid misleading clickbait (a hook is fine, a false claim is not)
- Avoid repeating headline formats
- Do not mention Google Trends unless the article is about search behavior itself
- Plain text only — no HTML, no markdown, no quotation marks around the title
- Avoid headlines tied to a specific country or region outside the EU (e.g. a US-only recall, an Asia-only regulation, an Australia-only incident) — this business only sells within the EU for now, so that audience has little reason to care; prefer EU-wide, pan-European, or globally-general angles instead

Traffic-risk awareness:
- If a topic may cause a traffic crash because competitors are covering it and this site is not, prioritize it
- Prefer topics with sudden public attention over slow evergreen topics
- Prefer actionable news readers would search immediately
- Avoid stale news unless it is still actively rising in search interest

Return only valid JSON matching this schema:

{
  "titles": [
    { "title": "string", "topic": "health|safety|wildlife|adoption|industry|lifestyle", "trend_rise": "low|moderate|high" }
  ]
}

Return exactly {{STORIES_PER_RUN}} items inside titles. Do not include markdown, explanations, citations, comments, or text outside the JSON.
TXT;
    $systemInstruction = str_replace('{{STORIES_PER_RUN}}', (string) $storiesPerRun, $systemInstruction);

    $titlesBlock = empty($previousTitles)
        ? '(none yet today)'
        : implode("\n", array_map(fn($t) => "- $t", $previousTitles));

    $userText = "Previously created titles to avoid repeating or paraphrasing:\n\n$titlesBlock\n\n"
        . "Task:\n"
        . "Use live search to find the most real-time, fast-rising pets and animals topics right now. "
        . "Focus on topics that could create a traffic spike today or cause a visit crash if we miss them.\n\n"
        . "Generate exactly $storiesPerRun new headlines for urgent animal and pet news articles (titles only, no bodies).";

    $requestBody = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $userText]]],
        ],
        'generationConfig' => [
            'thinkingConfig' => ['thinkingLevel' => 'HIGH'],
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'titles' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'maxLength' => 100],
                                'topic' => [
                                    'type' => 'string',
                                    'enum' => array_keys(PET_NEWS_TOPICS),
                                ],
                                'trend_rise' => [
                                    'type' => 'string',
                                    'enum' => ['low', 'moderate', 'high'],
                                ],
                            ],
                            'required' => ['title', 'topic', 'trend_rise'],
                            'propertyOrdering' => ['title', 'topic', 'trend_rise'],
                        ],
                    ],
                ],
                'required' => ['titles'],
                'propertyOrdering' => ['titles'],
            ],
        ],
        'tools' => [
            ['urlContext' => new stdClass()],
            ['googleSearch' => new stdClass()],
        ],
        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/$modelId:generateContent";
    $resp = httpRequest('POST', $url, ['x-goog-api-key: ' . $apiKey], json_encode($requestBody), 180);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("Gemini title request failed with status {$resp['status']}: {$resp['body']}");
    }

    $data = json_decode($resp['body'], true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        throw new RuntimeException('Gemini title response missing candidates[0].content.parts[0].text');
    }
    $parsed = json_decode($text, true);
    if (!isset($parsed['titles']) || !is_array($parsed['titles'])) {
        throw new RuntimeException('Gemini title response JSON missing "titles" array');
    }
    // Each item: ['title' => string, 'topic' => one of PET_NEWS_TOPICS keys].
    return $parsed['titles'];
}

// ---- 2b. given the confirmed title outline, write the EN body + IT/DE/FR/ES translations — same model, same tools ----
function generateBodies(string $apiKey, string $modelId, array $titles): array
{
    $langList = implode(', ', array_map(
        fn($code, $name) => "$code ($name)",
        array_keys(PET_NEWS_LANGS),
        array_values(PET_NEWS_LANGS)
    ));

    $systemInstruction = PET_NEWS_MISSION . <<<TXT


The headlines below have already been chosen in English (do not rewrite, retitle, or reorder them). Your job now is to write the article body for each one, then translate both the headline and the body into every other target language.

Target languages: $langList. Every item must include all five.

For the English body, write 400-500 words explaining the story, why people are searching for it, why it matters now, and what readers should know. Then add a practical section — general, evergreen guidance for pet owners connected to the topic (e.g. what to watch for, questions to ask a vet, everyday precautions) that does not depend on unconfirmed facts, so the article gives real standalone value beyond the news item itself. Finally add one closing paragraph (see "Closing store mention" below). For every other language, translate that same body naturally for a native reader of that language — not a literal word-for-word translation — keeping the same facts, length, and paragraph structure.

Body rules:
- Write in a clear, catchy, engaging news/blog style — hook the reader in the opening sentence
- Explain why the topic matters right now
- Mention the likely search intent behind the topic when useful
- Do not invent exact facts, numbers, quotes, names, dates, or locations unless confirmed by live search
- If facts are uncertain, keep the wording general
- The practical-guidance section must stay general and evergreen — never invent specific statistics, dosages, product names, or claims of official endorsement
- For pet health or safety topics, recommend checking official sources or contacting a veterinarian
- Do not claim something is confirmed unless live search clearly supports it
- Split the body into 6-8 short paragraphs separated by a blank line: the opening/news paragraphs, then 1-2 practical-guidance paragraphs, then exactly one final closing-paragraph (see below)

Closing store mention (final paragraph of every body, every language):
- Sofia's Pawfect Prints (sofiaprints.com) turns a customer's own pet photo into a custom-made portrait/print. Write one short closing paragraph (2-3 sentences) that bridges naturally from this specific article's topic into a soft, tasteful mention of turning a pet photo into a keepsake portrait
- Vary the angle every time so it never reads like a template — e.g. depending on the story, it could be about cherishing the bond with a pet, marking a milestone, honoring a pet's memory, celebrating an adoption, or simply a lighthearted aside — pick whatever angle actually fits this article's topic and mood
- Tone: warm and editorial, like a natural aside from the writer, not a sales pitch — no prices, discounts, exclamation-heavy language, or imperative commands like "Order now" or "Buy today"
- Do not mention this rule or explain that this paragraph is promotional

Title translation rules:
- Each translated title is a natural, search-friendly headline in that language, not a literal translation — plain text only, no HTML, no markdown, no quotation marks
- Target 55–85 characters when possible, hard maximum 95 characters — every title must read as a complete headline (never stop on an article, preposition, or conjunction: e.g. not "…delle", "…des", "…the", "…und")
- German compounds especially tend to run long — rephrase into a shorter complete headline rather than cutting mid-phrase

HTML emphasis rules (for on-page readability/scannability, and to make the body feel catchy rather than a flat wall of text), applied independently in every language:
- Inside the body text, wrap the single most important word or short phrase per paragraph in <strong>...</strong> (bold), <em>...</em> (italic), or <u>...</u> (underline) — pick whichever fits best per paragraph, never combine two of them on the same phrase
- Use <strong> for key facts, numbers, or the topic's punchiest word; use <em> for tone or emphasis words; use <u> sparingly
- Every paragraph should carry at least one emphasized phrase — an unbroken paragraph of plain text is a miss
- Use emphasis sparingly within a paragraph: never wrap a whole sentence, never wrap more than one phrase per paragraph
- Do not use any other HTML tags, markdown, headings, or colors — <strong>, <em>, and <u> are the only tags allowed

Return only valid JSON matching this schema:

{
  "news": [
    {
      "title": "string (must exactly match one of the given English headlines, used only to match this item back to its headline)",
      "translations": [
        { "lang": "en", "title": "string", "body": "string" },
        { "lang": "it", "title": "string", "body": "string" },
        { "lang": "de", "title": "string", "body": "string" },
        { "lang": "fr", "title": "string", "body": "string" },
        { "lang": "es", "title": "string", "body": "string" }
      ]
    }
  ]
}

Return exactly one object per given headline, in the same order, each with exactly 5 translations covering all target languages. Do not include markdown, explanations, citations, comments, or text outside the JSON.
TXT;

    $titlesBlock = implode("\n", array_map(fn($t) => "- $t", $titles));
    $userText = "Confirmed English headlines (write a body for each, do not change the wording):\n\n$titlesBlock\n\n"
        . "Task:\n"
        . "Use live search to ground each body in real, current information about its headline. "
        . 'Write ' . count($titles) . ' stories, each with an English body and IT/DE/FR/ES translations, with <em>/<u> emphasis as instructed.';

    $requestBody = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $userText]]],
        ],
        'generationConfig' => [
            // Titles/topics are already decided in phase 1 — this call is just fast writing
            // and translation, so keep thinking near-zero to minimize latency.
            'thinkingConfig' => ['thinkingLevel' => 'LOW'],
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'news' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'maxLength' => 100],
                                'translations' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'lang' => ['type' => 'string'],
                                            'title' => ['type' => 'string', 'maxLength' => 100],
                                            'body' => ['type' => 'string'],
                                        ],
                                        'required' => ['lang', 'title', 'body'],
                                        'propertyOrdering' => ['lang', 'title', 'body'],
                                    ],
                                ],
                            ],
                            'required' => ['title', 'translations'],
                            'propertyOrdering' => ['title', 'translations'],
                        ],
                    ],
                ],
                'required' => ['news'],
                'propertyOrdering' => ['news'],
            ],
        ],
        'tools' => [
            ['urlContext' => new stdClass()],
            ['googleSearch' => new stdClass()],
        ],
        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/$modelId:generateContent";
    $resp = httpRequest('POST', $url, ['x-goog-api-key: ' . $apiKey], json_encode($requestBody), 240);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("Gemini body request failed with status {$resp['status']}: {$resp['body']}");
    }

    $data = json_decode($resp['body'], true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        throw new RuntimeException('Gemini body response missing candidates[0].content.parts[0].text');
    }
    $parsed = json_decode($text, true);
    if (!isset($parsed['news']) || !is_array($parsed['news'])) {
        throw new RuntimeException('Gemini body response JSON missing "news" array');
    }
    return $parsed['news'];
}

// ---- 3. resolve (or create) the target WP category ----
function getOrCreateCategory(string $siteUrl, string $wpUser, string $wpPass, string $slug): int
{
    $auth = basicAuthHeader($wpUser, $wpPass);
    $url = $siteUrl . '/wp-json/wp/v2/categories?' . http_build_query(['slug' => $slug]);
    $resp = httpRequest('GET', $url, [$auth]);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP category lookup failed with status {$resp['status']}: {$resp['body']}");
    }
    $found = json_decode($resp['body'], true);
    if (!empty($found)) {
        return (int) $found[0]['id'];
    }

    $name = ucwords(str_replace('-', ' ', $slug));
    $createUrl = $siteUrl . '/wp-json/wp/v2/categories';
    $resp = httpRequest('POST', $createUrl, [$auth], json_encode(['slug' => $slug, 'name' => $name]));
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP category create failed with status {$resp['status']}: {$resp['body']}");
    }
    $created = json_decode($resp['body'], true);
    return (int) $created['id'];
}

// Same lookup-or-create pattern as getOrCreateCategory(), for the post_tag taxonomy —
// used for the fixed topic tags (PET_NEWS_TOPICS), kept separate from categories because
// categories already encode language for the URL scheme.
function getOrCreateTag(string $siteUrl, string $wpUser, string $wpPass, string $slug, string $name): int
{
    $auth = basicAuthHeader($wpUser, $wpPass);
    $url = $siteUrl . '/wp-json/wp/v2/tags?' . http_build_query(['slug' => $slug]);
    $resp = httpRequest('GET', $url, [$auth]);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP tag lookup failed with status {$resp['status']}: {$resp['body']}");
    }
    $found = json_decode($resp['body'], true);
    if (!empty($found)) {
        return (int) $found[0]['id'];
    }

    $createUrl = $siteUrl . '/wp-json/wp/v2/tags';
    $resp = httpRequest('POST', $createUrl, [$auth], json_encode(['slug' => $slug, 'name' => $name]));
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP tag create failed with status {$resp['status']}: {$resp['body']}");
    }
    $created = json_decode($resp['body'], true);
    return (int) $created['id'];
}

// ---- 4. publish one post ----

// This content is published unreviewed, so strip everything except bare <em>/<u>/<strong>
// tags (no attributes) before it ever reaches wp_insert_post — defense against the model
// emitting stray markup (or something like an onclick attribute) in the body text.
function sanitizeEmphasisHtml(string $text): string
{
    $text = preg_replace('/<(?!\/?(?:em|u|strong)\b)[^>]*>/i', '', $text);
    $text = preg_replace('/<(em|u|strong)\b[^>]*>/i', '<$1>', $text);
    $text = preg_replace('/<\/(em|u|strong)\b[^>]*>/i', '</$1>', $text);
    return $text;
}

function bodyToHtml(string $bodyText): string
{
    $paragraphs = array_filter(array_map('trim', preg_split("/\n\s*\n/", $bodyText)));
    if (empty($paragraphs)) {
        $paragraphs = [trim($bodyText)];
    }
    return implode("\n", array_map(fn($p) => '<p>' . sanitizeEmphasisHtml($p) . '</p>', $paragraphs));
}

// Per-language stopwords for the crude focus-keyword extraction below — English-only
// stopwords would leave foreign articles/conjunctions (il, der, le, el...) in the keyword
// for translated posts, so each target language gets its own short list.
const PET_NEWS_STOPWORDS = [
    'en' => ['the','a','an','and','or','but','of','in','on','for','to','with','what','why','how','is','are','this','that','from','as','at','by','be','your','you','their','its','it','they','new','can','will','has','have','more','most','could','should'],
    'it' => ['il','lo','la','i','gli','le','un','uno','una','e','o','ma','di','in','su','per','con','che','cosa','perché','come','è','sono','questo','questa','questi','queste','da','tuo','tuoi','tua','tue','loro','suo','suoi','sua','sue','nuovo','nuova','può','potrebbe','dovrebbe','più','ha','hanno'],
    'de' => ['der','die','das','ein','eine','und','oder','aber','von','in','auf','für','zu','mit','was','warum','wie','ist','sind','dies','diese','dieser','dein','deine','ihr','ihre','neu','neue','kann','könnte','sollte','mehr','hat','haben'],
    'fr' => ['le','la','les','un','une','et','ou','mais','de','dans','sur','pour','à','avec','quoi','pourquoi','comment','est','sont','ce','cette','ces','votre','vos','leur','leurs','nouveau','nouvelle','peut','pourrait','devrait','plus','a','ont'],
    'es' => ['el','la','los','las','un','una','y','o','pero','de','en','sobre','para','con','qué','por','cómo','es','son','este','esta','estos','estas','tu','tus','su','sus','nuevo','nueva','puede','podría','debería','más','ha','han'],
];

// Optional SEO snippet for Rank Math (≤60 chars, ellipsis) — never applied to the
// WordPress post title shown on the article page.
function serpTitleSnippet(string $title, int $max = 60): string
{
    $title = trim($title);
    if (mb_strlen($title) <= $max) {
        return $title;
    }
    $cut = mb_substr($title, 0, max(20, $max - 1));
    $lastSpace = mb_strrpos($cut, ' ');
    $snippet = $lastSpace !== false && $lastSpace > 20 ? rtrim(mb_substr($cut, 0, $lastSpace)) : rtrim($cut);
    return rtrim($snippet, " \t.,;:!?-—–") . '…';
}

// Derive SEO fields from the generated title + body (no extra AI call).
// Returns an excerpt (≤155 chars), focus keyword, and a SERP title snippet.
// The WordPress post title is stored in full — never truncated for the article page.
function deriveSeo(string $title, string $bodyText, string $lang = 'en'): array
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($bodyText)));
    $desc = '';
    if ($plain !== '') {
        if (mb_strlen($plain) <= 155) {
            $desc = $plain;
        } else {
            $cut = mb_substr($plain, 0, 155);
            $lastSpace = mb_strrpos($cut, ' ');
            $desc = ($lastSpace !== false && $lastSpace > 60) ? mb_substr($cut, 0, $lastSpace) : $cut;
            $desc = rtrim($desc, " \t.,;:!?-—–") . '…';
        }
    }

    // Focus keyword: first significant words of the title (before a colon/dash).
    $stop = PET_NEWS_STOPWORDS[$lang] ?? PET_NEWS_STOPWORDS['en'];
    $headPart = trim((preg_split('/[:|\-–—]/u', $title) ?: [$title])[0]);
    $words = preg_split('/\s+/u', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $headPart))) ?: [];
    $kw = [];
    foreach ($words as $w) {
        $w = trim($w);
        if ($w !== '' && mb_strlen($w) > 2 && !in_array($w, $stop, true)) {
            $kw[] = $w;
            if (count($kw) >= 4) {
                break;
            }
        }
    }
    if (empty($kw)) {
        $kw = array_slice(array_values(array_filter($words, fn($w) => $w !== '')), 0, 4);
    }

    return [
        'description' => $desc,
        'focus_keyword' => implode(' ', $kw),
        'serp_title' => serpTitleSnippet($title),
    ];
}

// Returns ['id' => int, 'link' => string]. The link is needed by the main loop to build the
// _pet_news_alternates map (see updatePostMeta()) once every language sibling exists.
function publishPost(string $siteUrl, string $wpUser, string $wpPass, int $categoryId, string $title, string $bodyText, string $lang = 'en', array $meta = [], array $tagIds = []): array
{
    $auth = basicAuthHeader($wpUser, $wpPass);
    $url = $siteUrl . '/wp-json/wp/v2/posts';
    $title = trim($title);
    $seo = deriveSeo($title, $bodyText, $lang);

    // Create the post. The excerpt (<=155 chars) is the SEO description source
    // / article lead. Rank Math focus keyword + description are applied
    // server-side by the site plugin's save_post hook (REST cannot write RM meta).
    // 'meta' carries _pet_news_lang (see mu-plugin pet-news-hreflang.php), which must be
    // registered with show_in_rest for the REST API to persist them.
    // No retry/backoff here on purpose: this script runs as a request on sofiaprints.com
    // itself, so every extra second it runs is a PHP worker held hostage on the same shared
    // host serving real visitors — sleeping through retries made site-wide slowness worse,
    // not better. A failed create is just skipped; see main loop.
    $payload = json_encode([
        'title' => $title,
        'content' => bodyToHtml($bodyText),
        'excerpt' => $seo['description'],
        'status' => 'publish',
        'categories' => [$categoryId],
        'tags' => array_values(array_filter(array_map('intval', $tagIds))),
        'meta' => $meta,
    ]);
    $resp = httpRequest('POST', $url, [$auth], $payload);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP post create failed with status {$resp['status']}: {$resp['body']}");
    }
    $created = json_decode($resp['body'], true);
    return ['id' => (int) ($created['id'] ?? 0), 'link' => (string) ($created['link'] ?? '')];
}

// Lightweight follow-up write: only touches post meta, no content re-render. Used once a
// story's full language group is known, so every sibling can list the others' URLs — read
// back at view time with a plain get_post_meta() (no WP_Query), so it never disturbs page
// caching the way the earlier get_posts()-based hreflang lookup did.
function updatePostMeta(string $siteUrl, string $wpUser, string $wpPass, int $postId, array $meta): void
{
    $auth = basicAuthHeader($wpUser, $wpPass);
    $url = $siteUrl . '/wp-json/wp/v2/posts/' . $postId;
    $resp = httpRequest('POST', $url, [$auth], json_encode(['meta' => $meta]));
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP post meta update failed with status {$resp['status']}: {$resp['body']}");
    }
}

// Products already reviewed, ALL-TIME (not just last 24h like news' fetchRecentTitles) — a
// review must never repeat a product, no matter how long ago. Scoped to all 5 review
// categories at once so no two languages land on the same product either.
function fetchReviewedProducts(string $siteUrl, string $wpUser, string $wpPass, array $categoryIds): array
{
    $auth = basicAuthHeader($wpUser, $wpPass);
    $products = [];
    $page = 1;
    $categoryIds = array_values(array_filter($categoryIds, fn($id) => $id > 0));
    while ($page <= 10) { // cap 1000 posts
        $args = [
            'per_page' => 100,
            'page' => $page,
            'status' => 'publish',
            '_fields' => 'meta',
        ];
        if (!empty($categoryIds)) {
            $args['categories'] = implode(',', $categoryIds);
        }
        $url = $siteUrl . '/wp-json/wp/v2/posts?' . http_build_query($args);
        $resp = httpRequest('GET', $url, [$auth]);
        if ($resp['status'] === 400 && $page > 1) {
            break;
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException("WP reviewed-products fetch failed with status {$resp['status']}: {$resp['body']}");
        }
        $batch = json_decode($resp['body'], true);
        if (empty($batch)) {
            break;
        }
        foreach ($batch as $item) {
            $name = trim((string) ($item['meta']['_pet_review_product_name'] ?? ''));
            if ($name !== '') {
                $products[] = $name;
            }
        }
        $page++;
        if (count($batch) < 100) {
            break;
        }
    }
    return array_values(array_unique($products));
}

// Lenient acceptance (2026-07-07 product decision): any Amazon-family URL is accepted as-is,
// including amzn.eu / amzn.to / a.co short links and non-canonical /dp or /gp paths, on ANY
// marketplace TLD. We no longer resolve the product page or require a matching ASIN/marketplace —
// the sole hard requirement (enforced at the call site, after appendAmazonTag) is that the
// published link carries our affiliate tag. $expectedTld is kept for signature compatibility but
// intentionally not enforced. Returns null only for empty input or a clearly non-Amazon host.
function validateAmazonProductUrl(string $url, string $expectedTld): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    // Tolerate scheme-less URLs like "amzn.eu/d/xxx" so parse_url() sees a host, and so the
    // final href is well-formed once the tag is appended.
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return null;
    }
    $host = strtolower($host);
    $isAmazon = (bool) preg_match('#(^|\.)amazon\.[a-z.]{2,}$#', $host)
        || (bool) preg_match('#(^|\.)(amzn\.to|amzn\.eu|a\.co)$#', $host);
    if (!$isAmazon) {
        return null;
    }
    // Return the model's URL unchanged — short links must not be rewritten into a canonical /dp
    // form (there is no ASIN to canonicalize). The affiliate tag is appended by the caller.
    return $url;
}

// Sets OUR affiliate tag as the sole `tag` param. Any tag the source URL already carries (e.g. a
// competitor's tag on a model-found link, or a price-comparison feed's tag) is stripped first —
// Amazon Associates attributes the sale to the FIRST `tag` it sees, so a leftover foreign tag
// would silently steal the commission. Since the lenient validator (2026-07-07) no longer rebuilds
// URLs to a canonical /dp form, this stripping is what guarantees we, not the source, get credited.
// All other query params (and any #fragment) are preserved.
function appendAmazonTag(string $productUrl, string $tag): string
{
    $hashPos = strpos($productUrl, '#');
    $fragment = $hashPos !== false ? substr($productUrl, $hashPos) : '';
    $base = $hashPos !== false ? substr($productUrl, 0, $hashPos) : $productUrl;

    $qPos = strpos($base, '?');
    if ($qPos === false) {
        return $base . '?tag=' . rawurlencode($tag) . $fragment;
    }
    $path = substr($base, 0, $qPos);
    $pairs = array_filter(
        explode('&', substr($base, $qPos + 1)),
        static fn(string $p): bool => $p !== '' && strncasecmp($p, 'tag=', 4) !== 0
    );
    $pairs[] = 'tag=' . rawurlencode($tag);
    return $path . '?' . implode('&', $pairs) . $fragment;
}

// Appends the fixed affiliate-disclosure paragraph as its own trailing paragraph. The
// PET_REVIEW_LINK_TOKEN placeholder inside $bodyText is left as plain text here — it survives
// bodyToHtml()/sanitizeEmphasisHtml() untouched (no angle brackets) and is swapped for the real,
// server-built <a> tag by the caller afterwards, since sanitizeEmphasisHtml() only allow-lists
// em/u/strong and would strip a model-authored anchor tag.
function appendReviewDisclosure(string $bodyText, string $lang): string
{
    $disclosure = PET_REVIEW_DISCLOSURE[$lang] ?? PET_REVIEW_DISCLOSURE['en'];
    return rtrim($bodyText) . "\n\n" . $disclosure;
}

// One OpenAI Responses API call (gpt-5.4-nano + web_search tool + structured JSON output) PER
// LANGUAGE — reverted from a single combined 5-in-1 request after testing showed the model's
// web_search effort/attention gets diluted across 5 simultaneous sub-tasks in one turn (URLs
// came back empty and confidence 'low' far more often than when each call focuses on just one
// marketplace). Costs 5 requests instead of 1, but each one can fully commit to finding a real,
// verifiable URL for its own market before writing the review.
function generateReviewForLocaleWithOpenAI(string $apiKey, string $modelId, string $lang, string $langName, string $tld, array $previousProducts): array
{
    $productsBlock = empty($previousProducts)
        ? '(none yet)'
        : implode("\n", array_map(fn($p) => "- $p", $previousProducts));

    $instructions = PET_NEWS_MISSION . <<<TXT


Your task right now is different: instead of a news headline, find exactly ONE specific, real,
currently-purchasable pet or animal product sold on amazon.$tld (the $langName-market Amazon
site) whose search interest and sales momentum are genuinely rising there — a real breakout,
not something already saturated or already covered everywhere.

Work in this order:
1. Use web_search with a site-restricted query against amazon.$tld (e.g. site:amazon.$tld pet
   supplies bestseller, or site:amazon.$tld <candidate product name>) to find a specific, named
   product — exact brand + model, never a generic category, never a made-up brand.
2. Its Amazon URL must be a URL you actually saw inside a search result/snippet — never
   reconstruct or guess a /dp/ URL from memory. Retry the site-restricted search with different
   keywords before giving up. Only if that still turns up nothing genuine, return null for
   amazon_url rather than guessing.
3. Give an honest confidence rating — low, moderate, or high — reflecting your genuine
   certainty that the product is real, currently purchasable on amazon.$tld, genuinely rising
   (not already-saturated), and that the URL you returned is one you actually saw. Do not
   default to 'high' — say 'low' or 'moderate' whenever unsure.

Use the user's provided list of already-reviewed products as memory. Do not pick the same
product again, even under a slightly different name or a newer model number of the same line.

Then write an honest-style product review article NATIVELY in $langName for a native reader —
an original piece of writing, not a translation. Write 400-500 words: an engaging hook about
why this product is suddenly getting attention, what it does and who it's for, its likely key
features and benefits (keep these general/evergreen — do not invent exact specs, prices, or
claims you can't verify via web_search), and an honest note that readers should check the
current price, availability, and specifications on Amazon before buying since these can change.
Include the literal token [AMAZON_LINK] exactly once — it will be replaced afterwards with a
short linked phrase equivalent to "on Amazon" in $langName (not a URL, not the headline), so
write the sentence around it as if [AMAZON_LINK] itself already means "on Amazon", e.g. "...you
can find it [AMAZON_LINK] right now." Do not add any other link, URL, or HTML around it. Do not
write an affiliate disclosure paragraph yourself — one is appended automatically.

Body rules:
- Write in a clear, catchy, engaging review/blog style — hook the reader in the opening sentence
- Be honest and balanced — mention one realistic caveat or who it might NOT be a great fit for
- Do not invent exact facts, numbers, prices, or specifications unless confirmed by web_search
- Split the body into 6-8 short paragraphs separated by a blank line
- Inside the body, wrap the single most important word or short phrase per paragraph in
  <strong>...</strong>, <em>...</em>, or <u>...</u> — never combine two on the same phrase, and
  never use any other HTML tag, markdown, heading, or color

Title rules:
- A natural, search-friendly review headline written natively in $langName (e.g. mentioning
  the product name and a hook like "why pet owners are buying this now")
- Target 55-85 characters when possible, hard maximum 95 characters, a complete grammatical headline
- Plain text only — no HTML, no markdown, no quotation marks

Must be reasonably relevant to an EU pet-owner audience (this business only sells within the
EU, same constraint as news headlines) — amazon.$tld still means EU-relevant products, not
US-only ones.

Return only valid JSON matching the given schema. Do not include markdown, explanations,
citations, comments, or text outside the JSON.
TXT;

    $userText = "Already-reviewed products to avoid repeating:\n\n$productsBlock\n\n"
        . "Task: find one rising product on amazon.$tld and write its native-$langName review, as instructed.";

    $requestBody = [
        'model' => $modelId,
        'instructions' => $instructions,
        'input' => $userText,
        'tools' => [['type' => 'web_search']],
        'reasoning' => ['effort' => 'medium'],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'review',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => ['type' => 'string'],
                        'trend_reason' => ['type' => 'string'],
                        'confidence' => ['type' => 'string', 'enum' => ['low', 'moderate', 'high']],
                        'amazon_url' => ['type' => ['string', 'null']],
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                    ],
                    'required' => ['product_name', 'trend_reason', 'confidence', 'amazon_url', 'title', 'body'],
                    'additionalProperties' => false,
                ],
            ],
        ],
    ];

    $url = 'https://api.openai.com/v1/responses';
    $resp = httpRequest('POST', $url, ['Authorization: Bearer ' . $apiKey], json_encode($requestBody), 90);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("OpenAI review request failed with status {$resp['status']}: {$resp['body']}");
    }

    $data = json_decode($resp['body'], true);
    $text = null;
    foreach ($data['output'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'message') {
            $text = $item['content'][0]['text'] ?? null;
        }
    }
    if ($text === null) {
        throw new RuntimeException('OpenAI review response missing a message output with text content');
    }
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('OpenAI review response was not a JSON object');
    }
    return $parsed;
}

// Orchestrates review-mode end to end: one OpenAI call per language, each independently
// validated/published — a product rejected for one language (bad confidence, unverifiable URL,
// duplicate) doesn't block the other 4.
function runReviewMode(
    string $siteUrl,
    string $wpUser,
    string $wpPass,
    string $reviewCategorySlug,
    string $amazonAssociateTag,
    string $openaiApiKey,
    string $openaiModelId,
    string $logFile,
    int &$created,
    int &$skipped,
    int &$failedCount,
    bool $dryRun = false
): void {
    $categoryIdsByLang = [];
    foreach (PET_NEWS_LANGS as $lang => $langName) {
        $slug = $lang === 'en' ? $reviewCategorySlug : "$reviewCategorySlug-$lang";
        try {
            $categoryIdsByLang[$lang] = getOrCreateCategory($siteUrl, $wpUser, $wpPass, $slug);
        } catch (Throwable $e) {
            fail($logFile, "could not resolve WP review category '$slug': " . $e->getMessage());
        }
    }

    try {
        $previousProducts = fetchReviewedProducts($siteUrl, $wpUser, $wpPass, array_values($categoryIdsByLang));
    } catch (Throwable $e) {
        fail($logFile, 'could not fetch previously reviewed products from WP: ' . $e->getMessage());
    }
    logmsg($logFile, 'Found ' . count($previousProducts) . ' previously reviewed products (all-time, all locales).');
    $reviewedSoFar = $previousProducts;

    foreach (PET_NEWS_LANGS as $lang => $langName) {
        $tld = PET_REVIEW_AMAZON_TLD[$lang] ?? 'com';

        try {
            $r = generateReviewForLocaleWithOpenAI($openaiApiKey, $openaiModelId, $lang, $langName, $tld, $reviewedSoFar);
        } catch (Throwable $e) {
            logmsg($logFile, "ERROR: [$lang] OpenAI review generation failed: " . $e->getMessage());
            $failedCount++;
            continue;
        }

        $productName = trim((string) ($r['product_name'] ?? ''));
        $confidence = mb_strtolower(trim((string) ($r['confidence'] ?? '')));
        if (!in_array($confidence, ['low', 'moderate', 'high'], true)) {
            $confidence = 'low';
        }
        $rawUrl = trim((string) ($r['amazon_url'] ?? ''));
        $langTitle = trim((string) ($r['title'] ?? ''));
        $langBody = trim((string) ($r['body'] ?? ''));

        if ($productName === '' || $langTitle === '' || $langBody === '') {
            logmsg($logFile, "Skipping $lang review: empty product name/title/body.");
            $skipped++;
            continue;
        }
        if (in_array(mb_strtolower($productName), array_map('mb_strtolower', $reviewedSoFar), true)) {
            logmsg($logFile, "Skipping $lang review: product already reviewed before: $productName");
            $skipped++;
            continue;
        }
        // Confidence gate removed 2026-07-07: record the model's self-rating for visibility, but
        // never skip on it — every candidate proceeds regardless of low/moderate/high.
        logmsg($logFile, "Proceeding with $lang review ($confidence confidence): $productName");

        $verifiedUrl = validateAmazonProductUrl($rawUrl, $tld);
        if ($verifiedUrl === null) {
            logmsg($logFile, "Skipping $lang review: not a recognizable Amazon URL for: $productName ($rawUrl)");
            $skipped++;
            continue;
        }

        $amazonUrlWithTag = appendAmazonTag($verifiedUrl, $amazonAssociateTag);
        // Only hard requirement 2026-07-07: the published link must carry our affiliate tag
        // (short amzn.eu/amzn.to/a.co links and non-canonical /dp paths are all accepted now).
        if ($amazonAssociateTag === '' || strpos($amazonUrlWithTag, 'tag=' . rawurlencode($amazonAssociateTag)) === false) {
            logmsg($logFile, "Skipping $lang review: affiliate tag missing from URL for: $productName ($amazonUrlWithTag)");
            $skipped++;
            continue;
        }
        if (strpos($langBody, PET_REVIEW_LINK_TOKEN) === false) {
            $langBody .= "\n\n" . PET_REVIEW_LINK_TOKEN;
        }
        $langBody = appendReviewDisclosure($langBody, $lang);
        $linkText = PET_REVIEW_LINK_TEXT[$lang] ?? PET_REVIEW_LINK_TEXT['en'];
        $anchor = '<a href="' . htmlspecialchars($amazonUrlWithTag, ENT_QUOTES) . '" rel="nofollow sponsored noopener" target="_blank">'
            . htmlspecialchars($linkText, ENT_QUOTES) . '</a>';
        $htmlBody = bodyToHtml($langBody);
        $htmlBody = str_replace(PET_REVIEW_LINK_TOKEN, $anchor, $htmlBody);

        if ($dryRun) {
            logmsg($logFile, "[DRY-RUN] would publish review [$lang]: $langTitle ($productName) -> $amazonUrlWithTag");
            $created++;
            $reviewedSoFar[] = $productName;
            continue;
        }

        try {
            $post = publishReviewPost(
                $siteUrl,
                $wpUser,
                $wpPass,
                $categoryIdsByLang[$lang],
                $langTitle,
                $htmlBody,
                $lang,
                [
                    '_pet_news_lang' => $lang,
                    '_pet_review_product_name' => $productName,
                    '_pet_review_amazon_url' => $amazonUrlWithTag,
                ],
                []
            );
            logmsg($logFile, "Published review post {$post['id']} [$lang]: $langTitle ($productName)");
            $created++;
            $reviewedSoFar[] = $productName;
        } catch (Throwable $e) {
            logmsg($logFile, "ERROR: failed to publish review [$lang] '" . $langTitle . "': " . $e->getMessage());
            $failedCount++;
        }
    }
}

// Same as publishPost() but takes pre-built HTML content directly (the review body already has
// its <a> tag spliced in and disclosure appended), so it must NOT be re-wrapped via bodyToHtml()
// a second time the way publishPost() does internally.
function publishReviewPost(string $siteUrl, string $wpUser, string $wpPass, int $categoryId, string $title, string $htmlContent, string $lang, array $meta, array $tagIds): array
{
    $auth = basicAuthHeader($wpUser, $wpPass);
    $url = $siteUrl . '/wp-json/wp/v2/posts';
    $title = trim($title);
    $plainForSeo = strip_tags($htmlContent);
    $seo = deriveSeo($title, $plainForSeo, $lang);

    $payload = json_encode([
        'title' => $title,
        'content' => $htmlContent,
        'excerpt' => $seo['description'],
        'status' => 'publish',
        'categories' => [$categoryId],
        'tags' => array_values(array_filter(array_map('intval', $tagIds))),
        'meta' => $meta,
    ]);
    $resp = httpRequest('POST', $url, [$auth], $payload);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("WP review post create failed with status {$resp['status']}: {$resp['body']}");
    }
    $created = json_decode($resp['body'], true);
    return ['id' => (int) ($created['id'] ?? 0), 'link' => (string) ($created['link'] ?? '')];
}

// ---- main ----
$created = 0;
$skipped = 0;
$failedCount = 0;

if ($mode === 'news') {
    // Reverted to Gemini-only generation for general pet news (2026-07-15): the
    // DataForSEO-backed 'generic' kind cost 5 paid API calls per run for content
    // that doesn't need real Amazon product data. DataForSEO stays reserved for
    // 'review' mode below, where real ASIN/price data is actually required for
    // the affiliate links.
try {
    $enCategoryId = fetchCategoryId($siteUrl, $wpUser, $wpPass, $categorySlug);
    $previousTitles = fetchRecentTitles($siteUrl, $wpUser, $wpPass, 24, $enCategoryId);
} catch (Throwable $e) {
    fail($logFile, 'could not fetch recent titles from WP: ' . $e->getMessage());
}
logmsg($logFile, 'Found ' . count($previousTitles) . ' EN posts published in the last 24h.');

// ---- phase 1: scaletta — title outline only, no bodies yet ----
try {
    $rawTitles = generateTitles($geminiApiKey, $geminiModelId, $previousTitles, $storiesPerRun);
} catch (Throwable $e) {
    fail($logFile, 'Gemini title generation failed: ' . $e->getMessage());
}

$existingLower = array_map('mb_strtolower', $previousTitles);
$skippedLowTrendRise = 0;
$freshTitles = [];
$topicByTitleLower = [];
foreach ($rawTitles as $rawItem) {
    $title = trim((string) (is_array($rawItem) ? ($rawItem['title'] ?? '') : $rawItem));
    $topic = is_array($rawItem) ? mb_strtolower(trim((string) ($rawItem['topic'] ?? ''))) : '';
    if (!isset(PET_NEWS_TOPICS[$topic])) {
        $topic = 'lifestyle'; // safe default if the model returns something outside the enum
    }
    $trendRise = is_array($rawItem) ? mb_strtolower(trim((string) ($rawItem['trend_rise'] ?? ''))) : '';
    if (!in_array($trendRise, ['low', 'moderate', 'high'], true)) {
        $trendRise = 'low'; // safe default if the model returns something outside the enum — gets skipped below
    }
    if ($title === '' || in_array(mb_strtolower($title), $existingLower, true)) {
        logmsg($logFile, $title === '' ? 'Skipping empty title from outline.' : "Skipping duplicate title in outline: $title");
        $skipped++;
        continue;
    }
    // Hardcoded gate (see PET_NEWS_MIN_TREND_RISE): the model forecasts trend-rise
    // potential, this line decides what to do with the forecast — that split keeps the
    // forecast itself unbiased.
    if ($trendRise !== PET_NEWS_MIN_TREND_RISE) {
        logmsg($logFile, "Skipping title with $trendRise trend-rise forecast (need " . PET_NEWS_MIN_TREND_RISE . "): $title");
        $skipped++;
        $skippedLowTrendRise++;
        continue;
    }
    $freshTitles[] = $title;
    $topicByTitleLower[mb_strtolower($title)] = $topic;
    $existingLower[] = mb_strtolower($title);
}
logmsg($logFile, 'Title outline: ' . count($freshTitles) . ' fresh titles, ' . $skipped . ' skipped total (' . $skippedLowTrendRise . ' for low/moderate trend-rise forecast).');

if (empty($freshTitles)) {
    logmsg($logFile, 'No fresh titles to write bodies for, done.');
} else {
    // ---- phase 2: same model + same tools, now writing EN body + IT/DE/FR/ES translations ----
    try {
        $newsItems = generateBodies($geminiApiKey, $geminiModelId, $freshTitles);
    } catch (Throwable $e) {
        fail($logFile, 'Gemini body generation failed: ' . $e->getMessage());
    }

    // One WP category per language — 'en' uses the base slug, others get a -<lang> suffix.
    // WP's default permalink structure includes the category slug, so this alone gives each
    // language its own URL without any multilingual plugin.
    $categoryIdsByLang = [];
    foreach (PET_NEWS_LANGS as $lang => $langName) {
        $slug = $lang === 'en' ? $categorySlug : "$categorySlug-$lang";
        try {
            $categoryIdsByLang[$lang] = getOrCreateCategory($siteUrl, $wpUser, $wpPass, $slug);
        } catch (Throwable $e) {
            fail($logFile, "could not resolve WP category '$slug': " . $e->getMessage());
        }
    }

    // One WP tag per (topic, language) — gives every post a topical bucket (health, safety,
    // wildlife, adoption, industry, lifestyle) on top of the language-encoding category, so
    // the site has real thematic grouping instead of everything sitting under "Pet News".
    $tagIdsByTopicLang = [];
    foreach (PET_NEWS_TOPICS as $topicKey => $namesByLang) {
        foreach (PET_NEWS_LANGS as $lang => $langName) {
            $tagSlug = "$topicKey-$lang";
            try {
                $tagIdsByTopicLang[$topicKey][$lang] = getOrCreateTag($siteUrl, $wpUser, $wpPass, $tagSlug, $namesByLang[$lang]);
            } catch (Throwable $e) {
                logmsg($logFile, "WARNING: could not resolve WP tag '$tagSlug': " . $e->getMessage());
            }
        }
    }

    // Match stories back to the confirmed outline by title (case-insensitive); fall back to
    // positional order if the model didn't echo a title back exactly.
    $itemsByTitleLower = [];
    foreach ($newsItems as $item) {
        $itemTitle = trim((string) ($item['title'] ?? ''));
        if ($itemTitle !== '') {
            $itemsByTitleLower[mb_strtolower($itemTitle)] = $item;
        }
    }
    $newsItemsByIndex = array_values($newsItems);

    foreach ($freshTitles as $index => $title) {
        $item = $itemsByTitleLower[mb_strtolower($title)] ?? $newsItemsByIndex[$index] ?? null;
        $translations = is_array($item['translations'] ?? null) ? $item['translations'] : [];
        if (empty($translations)) {
            logmsg($logFile, "Skipping title with no matching translations: $title");
            $skipped++;
            continue;
        }

        $translationsByLang = [];
        foreach ($translations as $t) {
            $tLang = mb_strtolower(trim((string) ($t['lang'] ?? '')));
            if ($tLang !== '') {
                $translationsByLang[$tLang] = $t;
            }
        }

        // Language siblings published for this story so far, keyed by lang: ['id' => .., 'link' => ..].
        $groupPosts = [];
        $topic = $topicByTitleLower[mb_strtolower($title)] ?? 'lifestyle';
        $storyKey = substr(md5(mb_strtolower($title)), 0, 16);

        foreach (PET_NEWS_LANGS as $lang => $langName) {
            $t = $translationsByLang[$lang] ?? null;
            $langTitle = trim((string) ($t['title'] ?? ''));
            $langBody = trim((string) ($t['body'] ?? ''));
            if ($langTitle === '' || $langBody === '') {
                logmsg($logFile, "Skipping $lang translation with missing title/body for: $title");
                $skipped++;
                continue;
            }
            $tagId = $tagIdsByTopicLang[$topic][$lang] ?? 0;

            if ($dryRun) {
                logmsg($logFile, "[DRY-RUN] would publish news [$lang]: $langTitle");
                $created++;
                continue;
            }

            try {
                $post = publishPost(
                    $siteUrl,
                    $wpUser,
                    $wpPass,
                    $categoryIdsByLang[$lang],
                    $langTitle,
                    $langBody,
                    $lang,
                    ['_pet_news_lang' => $lang, '_pet_news_story_key' => $storyKey],
                    $tagId ? [$tagId] : []
                );
                logmsg($logFile, "Published post {$post['id']} [$lang]: $langTitle");
                $groupPosts[$lang] = $post;
                $created++;
            } catch (Throwable $e) {
                logmsg($logFile, "ERROR: failed to publish [$lang] '" . $langTitle . "': " . $e->getMessage());
                $failedCount++;
            }
        }

        // Link the siblings for hreflang: each post gets the full lang => URL map of the
        // others as a single meta value, so the frontend never needs an extra DB query to
        // find them (see mu-plugin pet-news-hreflang.php). Skipped if <2 languages published
        // (nothing to link) — a lone post with no siblings just has no alternates.
        if (count($groupPosts) >= 2) {
            $alternates = array_map(fn($p) => $p['link'], $groupPosts);
            foreach ($groupPosts as $lang => $post) {
                try {
                    updatePostMeta($siteUrl, $wpUser, $wpPass, $post['id'], ['_pet_news_alternates' => json_encode($alternates)]);
                } catch (Throwable $e) {
                    logmsg($logFile, "ERROR: failed to link hreflang alternates for post {$post['id']} [$lang]: " . $e->getMessage());
                }
            }
        }
    }
}
} elseif ($mode === 'review') {
    // runReviewMode() (OpenAI-based) was the original review path but reads
    // $amazonAssociateTag/$openaiApiKey/$openaiModelId, which nothing in this
    // script ever assigns — every review-mode run fatal-errors before logging
    // anything, silently (found 2026-07-14: ~10h of 50%-drawn runs did nothing).
    // runDataForSeoPosts() already has a fully-built 'review' kind (locale
    // rotation, Amazon tag, disclosure text) that was wired up for 'generic'
    // but never for review; use it here instead of the dead OpenAI path.
    runDataForSeoPosts($config, 'review', $siteUrl, $wpUser, $wpPass, $reviewCategorySlug, $geminiApiKey, $geminiModelId, $logFile, $created, $skipped, $failedCount, $dryRun);
}

logmsg($logFile, "Done. created=$created skipped=$skipped failed=$failedCount");

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

echo json_encode(['ok' => true, 'created' => $created, 'skipped' => $skipped, 'failed' => $failedCount]);
