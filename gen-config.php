<?php
declare(strict_types=1);

// Builds pet-news-cron.config.php on the ephemeral GitHub Actions runner from
// repository secrets, right before the cron script runs. Never committed —
// see .gitignore. Mirrors the config shape the script already expects.

function envOrFail(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "missing env var $name\n");
        exit(1);
    }
    return $v;
}

$config = [
    'GEMINI_API_KEY' => envOrFail('GEMINI_API_KEY'),
    'GEMINI_MODEL_ID' => getenv('GEMINI_MODEL_ID') ?: 'gemini-3.1-flash-lite',
    'LIVE_URL' => 'https://www.sofiaprints.com',
    'WP_REST_USER' => envOrFail('WP_REST_USER'),
    'WP_REST_APP_PASSWORD' => envOrFail('WP_REST_APP_PASSWORD'),
    'HOPLIX_SHIPMENT_SYNC_ENABLED' => true,
    'WP_CATEGORY_SLUG' => 'pet-news',
    'STORIES_PER_RUN' => '1',
    // Pacing/frequency is controlled externally (cron-job.org calling
    // workflow_dispatch), not by this ephemeral runner's own last-run file.
    'MIN_MINUTES_BETWEEN_RUNS' => '0',
    'AMAZON_ASSOCIATE_TAG' => envOrFail('AMAZON_ASSOCIATE_TAG'),
    'WP_REVIEW_CATEGORY_SLUG' => 'product-reviews',
    'REVIEW_PROBABILITY_PERCENT' => '10',
    'DATAFORSEO_LOGIN' => envOrFail('DATAFORSEO_LOGIN'),
    'DATAFORSEO_PASSWORD' => envOrFail('DATAFORSEO_PASSWORD'),
    'DATAFORSEO_MARKETS' => [
        'en' => ['location_name' => 'United States', 'language_code' => 'en_US', 'se_domain' => 'amazon.com'],
        'it' => ['location_name' => 'Italy', 'language_code' => 'it_IT', 'se_domain' => 'amazon.it'],
        'de' => ['location_name' => 'Germany', 'language_code' => 'de_DE', 'se_domain' => 'amazon.de'],
        'fr' => ['location_name' => 'France', 'language_code' => 'fr_FR', 'se_domain' => 'amazon.fr'],
        'es' => ['location_name' => 'Spain', 'language_code' => 'es_ES', 'se_domain' => 'amazon.es'],
    ],
];

file_put_contents(__DIR__ . '/pet-news-cron.config.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
echo "Config written.\n";
