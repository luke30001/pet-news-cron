<?php
declare(strict_types=1);

const PET_DATAFORSEO_TERMS = [
    'en' => ['pet travel accessories', 'dog enrichment toys', 'cat care accessories'],
    'it' => ['accessori animali', 'giochi per cani', 'giochi per gatti'],
    'de' => ['haustier zubehör', 'intelligenzspielzeug hund', 'katzen zubehör'],
    'fr' => ['accessoires animaux', 'jouets éducatifs chien', 'accessoires chat'],
    'es' => ['accesorios mascotas', 'juguetes educativos perro', 'accesorios gato'],
];

function dataForSeoTrendingProduct(array $config, string $lang, array $reviewed, array &$history): ?array
{
    $terms = PET_DATAFORSEO_TERMS[$lang] ?? PET_DATAFORSEO_TERMS['en'];
    $term = $terms[(int) (gmdate('z') % count($terms))];
    $market = $config['DATAFORSEO_MARKETS'][$lang] ?? null;
    if (!is_array($market)) throw new RuntimeException("missing DataForSEO market for $lang");
    $payload = [[
        'keyword' => $term,
        'location_name' => $market['location_name'],
        'language_code' => $market['language_code'],
        'se_domain' => $market['se_domain'],
        // Was 20; the rotating queue below only ever uses the top 10 anyway
        // (array_slice(...,0,10)), and a smaller depth keeps DataForSEO's response
        // time down.
        'depth' => 10,
    ]];
    // Called directly: this runs on a GitHub Actions runner (2026-07-14 migration
    // off Altervista), whose IPs aren't the shared/abused free-hosting ranges that
    // got DataForSEO to silently drop connections from Altervista and, via the
    // short-lived Aruba relay, from Aruba's edge too.
    $auth = 'Authorization: Basic ' . base64_encode($config['DATAFORSEO_LOGIN'] . ':' . $config['DATAFORSEO_PASSWORD']);
    // Raised 90 -> 150 on 2026-07-15: DataForSEO's live/advanced endpoint was observed
    // timing out at exactly 90s on several real runs (IT/FR/EN) that would otherwise
    // have likely completed — a standard-priority queued task (task_post) took 110s+
    // for the same query, so 90s was too tight for the live endpoint's real p99.
    $resp = httpRequest('POST', 'https://api.dataforseo.com/v3/merchant/amazon/products/live/advanced', [$auth, 'Content-Type: application/json'], json_encode($payload), 150);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('DataForSEO request failed with HTTP ' . $resp['status']);
    }
    $data = json_decode($resp['body'], true);
    $task = $data['tasks'][0] ?? [];
    if (($task['status_code'] ?? 0) !== 20000) {
        throw new RuntimeException('DataForSEO: ' . ($task['status_message'] ?? 'unknown error'));
    }
    $known = array_map(fn($v) => mb_strtolower(trim((string) $v)), $reviewed);
    $candidates = [];
    foreach (($task['result'][0]['items'] ?? []) as $item) {
        if (($item['type'] ?? '') !== 'amazon_serp') continue;
        $asin = trim((string) ($item['data_asin'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $url = trim((string) ($item['url'] ?? ''));
        $historyKey = $lang . '|' . $asin;
        if ($asin === '' || $title === '' || $url === '') continue;
        $covered = in_array(mb_strtolower($title), $known, true) || !empty($history[$historyKey]['published']);
        $rank = max(1, (int) ($item['rank_group'] ?? 9999));
        $old = (int) ($history[$historyKey]['rank'] ?? $rank);
        $rise = max(0, $old - $rank);
        $item['trend_score'] = (($item['is_best_seller'] ?? false) ? 100000 : 0) + ($rise * 1000) - $rank;
        $item['asin'] = $asin;
        $item['product_name'] = $title;
        $item['sales_rank'] = $rank;
        $item['trend_reason'] = $rise > 0 ? 'La posizione nei risultati Amazon.it è migliorata rispetto al controllo precedente.' : (($item['is_best_seller'] ?? false) ? 'Il prodotto è contrassegnato Best Seller su Amazon.it.' : 'Il prodotto è tra i risultati organici più rilevanti su Amazon.it.');
        $item['covered'] = $covered;
        $candidates[] = $item;
        $history[$historyKey] = array_merge($history[$historyKey] ?? [], ['rank' => $rank, 'checked_at' => gmdate('c')]);
    }
    usort($candidates, fn($a, $b) => $b['trend_score'] <=> $a['trend_score']);
    // Work through a rotating top-10 queue instead of repeatedly selecting the
    // first result. This lets the cron cover a trend wave gradually while still
    // staying inside the ten strongest live signals for that marketplace.
    $topTen = array_slice($candidates, 0, 10);
    if (empty($topTen)) return null;
    if (count(array_filter($topTen, static fn(array $candidate): bool => empty($candidate['covered']))) === 0) {
        // All meaningful live candidates are already represented on the blog.
        // Stop this locale before spending a Gemini generation.
        return null;
    }
    $cursorKey = '__trend_slot_' . $lang;
    $slot = ((int) ($history[$cursorKey] ?? 0)) % count($topTen);
    $history[$cursorKey] = ($slot + 1) % count($topTen);
    $selected = $topTen[$slot];
    $selected['trend_slot'] = $slot + 1;
    // Never substitute a lower-ranked product if this slot was already covered:
    // the next run will naturally move to the next position in the top-ten queue.
    if (!empty($selected['covered'])) return null;
    return $selected;
}

function dataForSeoGeminiArticle(string $apiKey, string $modelId, string $langName, array $product): array
{
    $facts = ['nome' => $product['product_name'], 'asin' => $product['asin'], 'prezzo' => $product['price'] ?? null, 'ragione_trend' => $product['trend_reason']];
    $instruction = "Write a generic, useful 400-500 word article in $langName about the verified Amazon product. Use only supplied facts: never invent specifications, reviews, prices, sales data, or medical claims. Include [AMAZON_LINK] exactly once, say price and availability may change, use 6-8 short paragraphs and only <strong>, <em>, <u>. Return only JSON with title and body.";
    $request = ['contents' => [['role' => 'user', 'parts' => [['text' => json_encode($facts, JSON_UNESCAPED_UNICODE)]]]], 'generationConfig' => ['thinkingConfig' => ['thinkingLevel' => 'LOW'], 'responseMimeType' => 'application/json', 'responseSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'maxLength' => 95], 'body' => ['type' => 'string']], 'required' => ['title', 'body']]], 'systemInstruction' => ['parts' => [['text' => $instruction]]]];
    $resp = httpRequest('POST', "https://generativelanguage.googleapis.com/v1beta/models/$modelId:generateContent", ['x-goog-api-key: ' . $apiKey], json_encode($request), 240);
    if ($resp['status'] < 200 || $resp['status'] >= 300) throw new RuntimeException('Gemini request failed: ' . $resp['status']);
    $data = json_decode($resp['body'], true);
    $article = json_decode((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''), true);
    if (!is_array($article) || trim((string) ($article['title'] ?? '')) === '' || trim((string) ($article['body'] ?? '')) === '') throw new RuntimeException('Gemini returned no article');
    return $article;
}

function runDataForSeoPosts(array $config, string $kind, string $siteUrl, string $wpUser, string $wpPass, string $categorySlug, string $geminiKey, string $geminiModel, string $logFile, int &$created, int &$skipped, int &$failed, bool $dryRun): void
{
    $historyFile = __DIR__ . '/pet-news-dataforseo-ranks.json';
    $history = is_file($historyFile) ? json_decode((string) file_get_contents($historyFile), true) : [];
    $history = is_array($history) ? $history : [];
    $categoryIds = [];
    foreach (PET_NEWS_LANGS as $lang => $name) {
        $slug = $lang === 'en' ? $categorySlug : "$categorySlug-$lang";
        $categoryIds[$lang] = getOrCreateCategory($siteUrl, $wpUser, $wpPass, $slug);
    }
    // Query all posts that carry the generator's product meta, irrespective of category:
    // a product used in an editorial post must never reappear as a review, or vice versa.
    $reviewed = fetchReviewedProducts($siteUrl, $wpUser, $wpPass, []);
    $publishLocales = PET_NEWS_LANGS;
    if ($kind === 'review') {
        // One locale per run: five search + generation passes exceed the hosting
        // time budget, which previously meant the loop stopped after English.
        // Start at Italian for the next run, then persistently rotate all portals.
        $localeCodes = array_keys(PET_NEWS_LANGS);
        $cursor = isset($history['_review_locale_cursor']) ? (int) $history['_review_locale_cursor'] : 1;
        $cursor = (($cursor % count($localeCodes)) + count($localeCodes)) % count($localeCodes);
        $lang = $localeCodes[$cursor];
        $publishLocales = array($lang => PET_NEWS_LANGS[$lang]);
        $history['_review_locale_cursor'] = ($cursor + 1) % count($localeCodes);
        logmsg($logFile, "Review locale for this run: $lang");
    }
    foreach ($publishLocales as $lang => $name) {
        try {
            $product = dataForSeoTrendingProduct($config, $lang, $reviewed, $history); // exactly one localized DataForSEO query
            if ($product === null) { $skipped++; continue; }
            $article = dataForSeoGeminiArticle($geminiKey, $geminiModel, $name, $product);
            $title = trim((string) $article['title']);
            $body = trim((string) $article['body']);
            if ($kind === 'review') {
                $url = appendAmazonTag((string) $product['url'], (string) $config['AMAZON_ASSOCIATE_TAG']);
                if (strpos($body, PET_REVIEW_LINK_TOKEN) === false) $body .= "\n\n" . PET_REVIEW_LINK_TOKEN;
                $body = appendReviewDisclosure($body, $lang);
                $linkText = PET_REVIEW_LINK_TEXT[$lang] ?? PET_REVIEW_LINK_TEXT['en'];
                $html = str_replace(PET_REVIEW_LINK_TOKEN, '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" rel="nofollow sponsored noopener" target="_blank">' . htmlspecialchars($linkText, ENT_QUOTES) . '</a>', bodyToHtml($body));
            } else {
                $html = bodyToHtml($body);
            }
            if ($dryRun) { logmsg($logFile, "[DRY-RUN] $kind [$lang] $title"); $created++; continue; }
            $meta = ['_pet_news_lang' => $lang, '_pet_review_product_name' => $product['product_name'], '_pet_review_asin' => $product['asin']];
            if ($kind === 'review') publishReviewPost($siteUrl, $wpUser, $wpPass, $categoryIds[$lang], $title, $html, $lang, $meta, []);
            else publishReviewPost($siteUrl, $wpUser, $wpPass, $categoryIds[$lang], $title, $html, $lang, $meta, []);
            $created++;
            $reviewed[] = $product['product_name'];
            $history[$lang . '|' . $product['asin']]['published'] = true;
            logmsg($logFile, "Published $kind [$lang]: $title ({$product['asin']})");
        } catch (Throwable $e) { logmsg($logFile, "ERROR $kind [$lang]: " . $e->getMessage()); $failed++; }
    }
    file_put_contents($historyFile, json_encode($history, JSON_UNESCAPED_SLASHES));
}
