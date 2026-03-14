<?php
declare(strict_types=1);

/**
 * Hybrid DCA Bot — Adaptive Dollar Cost Averaging with 200MA + Fear & Greed
 * PHP 8.2+ | PSR-12 compliant
 */

const BASE_CONTRIBUTION = 100;
const TICKER = 'SPY';
const CSV_FILE = 'hybrid_dca_history.csv';
const CASH_FILE = 'cash_buffer.txt';
const FG_CACHE_FILE = 'fear_greed_cache.json';
const FG_DEBUG_FILE = 'fear_greed_debug.json';

define('TELEGRAM_BOT_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
define('TELEGRAM_CHAT_ID', $_ENV['TELEGRAM_CHAT_ID'] ?? '');

function writeFileContents(string $path, string $contents, bool $strict = true): void
{
    $result = @file_put_contents($path, $contents);
    if ($result === false) {
        $error = error_get_last();
        $message = 'Failed to write ' . $path . ': ' . ($error['message'] ?? 'unknown error');
        if ($strict) {
            throw new RuntimeException($message);
        }
        error_log($message);
    }
}

function encodeJson(array $payload): string
{
    try {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Failed to encode JSON: ' . $e->getMessage(), 0, $e);
    }
}

function nowUtc(): DateTimeImmutable
{
    try {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        throw new RuntimeException('Failed to read current UTC time: ' . $e->getMessage(), 0, $e);
    }
}

function getNestedArrayValue(array $data, array $path, mixed $default): mixed
{
    $current = $data;
    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return $default;
        }
        $current = $current[$key];
    }
    return $current;
}

function getInvestmentAmount(bool $isBull, int $fgValue): int
{
    if ($isBull) {
        return match (true) {
            $fgValue <= 24 => 400,
            $fgValue <= 44 => 250,
            $fgValue <= 55 => 150,
            $fgValue <= 75 => 100,
            default => 50,
        };
    }
    return match (true) {
        $fgValue <= 24 => 350,
        $fgValue <= 44 => 200,
        $fgValue <= 55 => 100,
        default => 0,
    };
}

function getSpyTrendAndPrice(): array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . TICKER
        . '?range=2y&interval=1d';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HybridDCA/1.0)',
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        $reason = $response === false
            ? ('cURL error: ' . $curlError)
            : ('HTTP ' . $httpCode);
        throw new RuntimeException(
            'Failed to fetch SPY data from Yahoo Finance: ' . $reason
        );
    }

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Invalid Yahoo Finance response: ' . $e->getMessage());
    }

    $closes = getNestedArrayValue(
        $data,
        ['chart', 'result', 0, 'indicators', 'quote', 0, 'close'],
        []
    );
    $numericCloses = array_values(array_filter($closes, static fn($v) => is_numeric($v)));
    if (count($numericCloses) < 200) {
        throw new RuntimeException('Not enough data for 200MA calculation');
    }

    $recentCloses = array_slice($numericCloses, -200);
    $ma200 = array_sum($recentCloses) / 200;
    $currentPrice = end($numericCloses);
    $isBull = $currentPrice > $ma200;

    printf(
        "SPY: %.2f | 200MA: %.2f → %s mode\n",
        $currentPrice,
        $ma200,
        $isBull ? 'BULL' : 'BEAR'
    );

    return [$isBull, $currentPrice, $ma200];
}

function getFearAndGreed(): array
{
    $url = 'https://production.dataviz.cnn.io/index/fearandgreed/graphdata';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (like Gecko) Chrome/122.0.0.0 '
            . 'Safari/537.36',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_REFERER => 'https://www.cnn.com/',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/plain,*/*',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        $reason = $response === false
            ? ('cURL error: ' . $curlError)
            : ('HTTP ' . $httpCode);
        $cached = loadFearAndGreedCache();
        if ($cached !== null) {
            printf(
                "F&G: using cached data (%s) due to fetch error: %s\n",
                $cached['timestamp'],
                $reason
            );
            return [$cached['value'], normalizeFearGreedRating($cached['rating'])];
        }
        throw new RuntimeException('Failed to fetch Fear & Greed index: ' . $reason);
    }

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Invalid Fear & Greed response: ' . $e->getMessage());
    }

    $extracted = extractFearAndGreed($data);
    if ($extracted === null) {
        $cached = loadFearAndGreedCache();
        if ($cached !== null) {
            printf(
                "F&G: using cached data (%s) due to missing latest values\n",
                $cached['timestamp']
            );
            return [$cached['value'], normalizeFearGreedRating($cached['rating'])];
        }
        saveFearAndGreedDebug($data);
        throw new RuntimeException('Fear & Greed data missing latest values');
    }
    [$value, $rating] = $extracted;
    saveFearAndGreedCache($value, $rating);

    printf("F&G: %d → %s\n", $value, $rating);

    return [$value, $rating];
}

function saveFearAndGreedDebug(array $data): void
{
    $summary = [
        'timestamp' => nowUtc()->format('Y-m-d H:i UTC'),
        'top_level_keys' => array_keys($data),
        'fear_and_greed_keys' => isset($data['fear_and_greed'])
            && is_array($data['fear_and_greed'])
            ? array_keys($data['fear_and_greed'])
            : null,
        'history_count' => isset($data['fear_and_greed_historical'])
            && is_array($data['fear_and_greed_historical'])
            ? count($data['fear_and_greed_historical'])
            : null,
        'sample_entry' => null,
    ];
    if (isset($data['fear_and_greed_historical'])
        && is_array($data['fear_and_greed_historical'])
    ) {
        $history = $data['fear_and_greed_historical'];
        $last = end($history);
        if (is_array($last)) {
            $summary['sample_entry'] = $last;
        }
    }
    writeFileContents(FG_DEBUG_FILE, encodeJson($summary), false);
}

function extractFearAndGreed(array $data): ?array
{
    if (isset($data['fear_and_greed_historical'])
        && is_array($data['fear_and_greed_historical'])
    ) {
        $history = array_reverse($data['fear_and_greed_historical']);
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['y'] ?? $entry['value'] ?? $entry['score'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $rating = $entry['rating'] ?? deriveFearGreedRating((int)$value);
            return [(int)$value, normalizeFearGreedRating($rating)];
        }
    }

    if (isset($data['fear_and_greed']) && is_array($data['fear_and_greed'])) {
        $fg = $data['fear_and_greed'];
        $value = $fg['value'] ?? $fg['now'] ?? $fg['score'] ?? null;
        if (is_numeric($value)) {
            $rating = $fg['rating'] ?? deriveFearGreedRating((int)$value);
            return [(int)$value, normalizeFearGreedRating($rating)];
        }
    }

    if (isset($data['fear_and_greed'])) {
        $value = $data['fear_and_greed'];
        if (is_numeric($value)) {
            return [(int)$value, deriveFearGreedRating((int)$value)];
        }
    }

    return null;
}

function deriveFearGreedRating(int $value): string
{
    return match (true) {
        $value <= 24 => 'Extreme Fear',
        $value <= 44 => 'Fear',
        $value <= 55 => 'Neutral',
        $value <= 75 => 'Greed',
        default => 'Extreme Greed',
    };
}

function normalizeFearGreedRating(string $rating): string
{
    $normalized = strtolower(trim($rating));
    if ($normalized === '') {
        return $rating;
    }
    return ucwords($normalized);
}

function loadFearAndGreedCache(): ?array
{
    if (!file_exists(FG_CACHE_FILE)) {
        return null;
    }
    $raw = @file_get_contents(FG_CACHE_FILE);
    if ($raw === false) {
        return null;
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($data) || !isset($data['value'], $data['rating'], $data['timestamp'])) {
        return null;
    }
    $data['value'] = (int)$data['value'];
    $data['rating'] = (string)$data['rating'];
    $data['timestamp'] = (string)$data['timestamp'];
    return $data;
}

function saveFearAndGreedCache(int $value, string $rating): void
{
    $payload = [
        'value' => $value,
        'rating' => $rating,
        'timestamp' => nowUtc()->format('Y-m-d H:i UTC'),
    ];
    writeFileContents(FG_CACHE_FILE, encodeJson($payload));
}

function loadCashBuffer(): float
{
    if (!file_exists(CASH_FILE)) {
        return 0.0;
    }
    $raw = @file_get_contents(CASH_FILE);
    if ($raw === false) {
        return 0.0;
    }
    $raw = trim($raw);
    $normalized = str_replace(',', '', $raw);
    return $normalized === '' ? 0.0 : (float)$normalized;
}

function saveCashBuffer(float $amount): void
{
    writeFileContents(CASH_FILE, number_format($amount, 2, '.', ''));
}

function appendToCsv(
    string $dateStr,
    int $fgValue,
    string $fgRating,
    string $trend,
    int $recommended,
    float $price,
    float $shares,
    float $cashBefore,
    float $cashAfter
): void {
    $fileExists = file_exists(CSV_FILE);
    $fp = @fopen(CSV_FILE, 'ab');
    if ($fp === false) {
        $error = error_get_last();
        throw new RuntimeException(
            'Failed to open CSV file for append: '
            . ($error['message'] ?? 'unknown error')
        );
    }

    if (!$fileExists) {
        if (fputcsv($fp, [
            'Date','F&G','Category','Trend','Recommended $',
            'SPY Price','Shares Bought','Cash Before','Cash After'
        ]) === false) {
            fclose($fp);
            throw new RuntimeException('Failed to write CSV header');
        }
    }

    if (fputcsv($fp, [
        $dateStr,
        $fgValue,
        $fgRating,
        $trend,
        $recommended,
        number_format($price, 2),
        number_format($shares, 4),
        number_format($cashBefore, 2),
        number_format($cashAfter, 2),
    ]) === false) {
        fclose($fp);
        throw new RuntimeException('Failed to write CSV row');
    }

    fclose($fp);
}

function sendTelegramMessage(string $message): void
{
    if (empty(TELEGRAM_BOT_TOKEN) || empty(TELEGRAM_CHAT_ID)) {
        echo "Telegram not configured\n";
        return;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'Markdown',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function main(): void
{
    $now = nowUtc();
    $dateStr = $now->format('Y-m-d H:i UTC');

    echo "=== Hybrid DCA Checker ===\n";
    echo "Run time: $dateStr\n\n";

    [$isBull, $price, $ma200] = getSpyTrendAndPrice();
    [$fgValue, $fgRating] = getFearAndGreed();

    $recommended = getInvestmentAmount($isBull, $fgValue);
    $cashBefore = loadCashBuffer();
    $cashAvailable = $cashBefore + BASE_CONTRIBUTION;
    $investAmount = min($recommended, $cashAvailable);
    $shares = $price > 0 ? $investAmount / $price : 0.0;
    $cashAfter = $cashAvailable - $investAmount;

    saveCashBuffer($cashAfter);

    $trendText = $isBull ? 'Bull 🟢' : 'Bear 🔴';
    $trendComparison = $isBull ? '>' : '<=';

    $priceFmt = number_format($price, 2);
    $ma200Fmt = number_format($ma200, 2);
    $cashAvailFmt = number_format($cashAvailable, 2);
    $investFmt = number_format($investAmount, 2);
    $sharesFmt = number_format($shares, 4);
    $cashAfterFmt = number_format($cashAfter, 2);

    $message = <<<MSG
*Hybrid DCA — {$now->format('d M Y')}*

F&G: **$fgValue** → $fgRating
Trend: **$trendText** (SPY $priceFmt $trendComparison 200MA $ma200Fmt)

Recommended: **\$$recommended**
Available: **\$$cashAvailFmt**

Investing: **\$$investFmt**
Buying ≈ **$sharesFmt** SPY shares

Cash left: **\$$cashAfterFmt**

Go ahead! 🚀
MSG;

    echo $message . "\n\n";
    sendTelegramMessage($message);

    appendToCsv(
        $dateStr,
        $fgValue,
        $fgRating,
        $trendText,
        $recommended,
        $price,
        $shares,
        $cashBefore,
        $cashAfter
    );
}

try {
    main();
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    error_log($e->getMessage());
    exit(1);
}
