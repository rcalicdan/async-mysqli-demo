<?php

/**
 * Fasync Framework - AsyncMySQLi Web Demo (patched)
 *
 * Notes:
 * - Update DB credentials below before running (DB_USER, DB_PASS, DB_HOST, DB_PORT).
 * - This file adds clearer connection error handling and uses positional mysqli args.
 * - It catches mysqli_sql_exception where appropriate to avoid uncaught exceptions.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\AsyncMySQLi;

// ======================================================================
// 1. CONFIGURATION & SETUP
// ======================================================================

// IMPORTANT: Update these with your MySQL credentials if necessary
define('DB_HOST', '127.0.0.1');      // prefer 127.0.0.1 to force TCP on Windows
define('DB_USER', 'root');
define('DB_PASS', 'Reymart1234');    // change or remove in production
define('DB_NAME', 'async_mysqli_demo');
define('DB_PORT', 3309);             // common default for MySQL (WAMP)
define('QUERY_COUNT', 5);
define('QUERY_DELAY_SECONDS', 0.25);

/**
 * Try to create a mysqli connection and return it. If an error occurs it will
 * throw or return null depending on the environment; we normalize it here.
 *
 * @param string $host
 * @param string $user
 * @param string $pass
 * @param string $db
 * @param int $port
 * @return mysqli
 * @throws RuntimeException
 */
function createMysqli(string $host, string $user, string $pass, string $db = '', int $port = 3306): mysqli
{
    // mysqli may either set connect_errno or throw mysqli_sql_exception depending on settings
    try {
        $mysqli = new mysqli($host, $user, $pass, $db, $port);
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException("MySQL connection failed to {$host}:{$port} — " . $e->getMessage());
    }

    if ($mysqli->connect_errno) {
        $err = $mysqli->connect_error ?: 'Unknown connect error';
        throw new RuntimeException("MySQL connection failed to {$host}:{$port} — " . $err);
    }

    return $mysqli;
}

/**
 * Create the database if it doesn't exist (one-time setup). Uses blocking mysqli.
 */
function setupDatabase(): void
{
    try {
        // Connect without selecting a database first
        $mysqli = createMysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    } catch (RuntimeException $e) {
        die("Error: Could not connect to MySQL to create the database. Details: " . $e->getMessage());
    }

    $safeName = $mysqli->real_escape_string(DB_NAME);
    $createSql = "CREATE DATABASE IF NOT EXISTS `{$safeName}`";
    if (! $mysqli->query($createSql)) {
        $mysqli->close();
        die("Error: Failed to create database `" . DB_NAME . "` — " . $mysqli->error);
    }

    // select the DB to ensure it's available
    if (! $mysqli->select_db(DB_NAME)) {
        $mysqli->close();
        die("Error: Could not select database `" . DB_NAME . "` — " . $mysqli->error);
    }

    $mysqli->close();
}

/**
 * Drop the demo database if possible. Fails silently if unable to connect.
 */
function cleanupDatabase(): void
{
    try {
        $mysqli = createMysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    } catch (RuntimeException $e) {
        // unable to connect — nothing to clean
        return;
    }

    $safeName = $mysqli->real_escape_string(DB_NAME);
    $mysqli->query("DROP DATABASE `{$safeName}`");
    $mysqli->close();
}

// ======================================================================
// 2. PHP LOGIC & DATA FETCHING
// ======================================================================

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/run-demo') {
    setupDatabase();

    $slowQuery = "SELECT SLEEP(" . QUERY_DELAY_SECONDS . ") as sleep_time";
    $syncTimeline = [];
    $syncStartTime = microtime(true);

    for ($i = 0; $i < QUERY_COUNT; $i++) {
        try {
            $mysqli = createMysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        } catch (RuntimeException $e) {
            die("Sync: Could not connect to MySQL — " . $e->getMessage());
        }

        // run the slow query synchronously
        if (! $mysqli->query($slowQuery)) {
            $mysqli->close();
            die("Sync: Query failed — " . $mysqli->error);
        }

        $syncTimeline["Query " . ($i + 1)] = microtime(true) - $syncStartTime;
        $mysqli->close();
    }
    $syncTotalTime = microtime(true) - $syncStartTime;

    // Async configuration
    $dbConfig = [
        'host' => DB_HOST,
        'username' => DB_USER,
        'password' => DB_PASS,
        'database' => DB_NAME,
        'port' => DB_PORT,
    ];

    // initialize the async pool (Fasync library)
    AsyncMySQLi::init($dbConfig, poolSize: QUERY_COUNT);

    $asyncPromises = [];
    $asyncTimeline = [];
    $asyncStartTime = microtime(true);

    for ($i = 0; $i < QUERY_COUNT; $i++) {
        $key = "Query " . ($i + 1);
        $asyncPromises[$key] = AsyncMySQLi::query($slowQuery)
            ->then(function ($result) use (&$asyncTimeline, $key, $asyncStartTime) {
                $asyncTimeline[$key] = microtime(true) - $asyncStartTime;
                return $result;
            });
    }

    // run_all is assumed available in your Fasync runtime (same as original)
    $asyncResults = run_all($asyncPromises);
    $asyncTotalTime = microtime(true) - $asyncStartTime;

    $improvement = (($syncTotalTime - $asyncTotalTime) / max($syncTotalTime, 1e-9)) * 100;
    $speedup = $asyncTotalTime > 0 ? $syncTotalTime / $asyncTotalTime : INF;

    uasort($asyncTimeline, fn($a, $b) => $a <=> $b);

    function renderBar(string $key, float $finishTime, float $totalTime): string
    {
        $width = $totalTime > 0 ? ($finishTime / $totalTime) * 100 : 0;
        $label = sprintf("%s (%.0fms)", $key, $finishTime * 1000);
        $colors = ['#0d6efd', '#198754', '#0dcaf0', '#ffc107', '#fd7e14'];
        $colorIndex = ((int) filter_var($key, FILTER_SANITIZE_NUMBER_INT) - 1) % count($colors);
        $color = $colors[$colorIndex];

        return "<div class='bar' style='width: {$width}%; background-color: {$color};' title='{$label}'>{$label}</div>";
    }

    cleanupDatabase();
}

// ======================================================================
// 3. HTML PRESENTATION
// ======================================================================

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fasync AsyncMySQLi Demo (patched)</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
            background-color: #f8f9fa;
        }

        h1,
        h2 {
            color: #212529;
        }

        a {
            color: #0d6efd;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .container {
            background-color: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            font-size: 16px;
            font-weight: bold;
            background-color: #0d6efd;
            color: #fff;
            border-radius: 5px;
            text-align: center;
        }

        .results-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        @media (min-width: 768px) {
            .results-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .result-card {
            border: 1px solid #dee2e6;
            padding: 1.5rem;
            border-radius: 8px;
        }

        .result-card h2 {
            margin-top: 0;
        }

        .timeline {
            position: relative;
            width: 100%;
            height: 180px;
            background: #e9ecef;
            border-radius: 5px;
            padding: 10px;
            box-sizing: border-box;
        }

        .bar {
            height: 30px;
            line-height: 30px;
            color: white;
            padding-left: 10px;
            margin-bottom: 5px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 12px;
            font-weight: bold;
        }

        .total-time {
            font-weight: bold;
            font-size: 24px;
        }

        .sync-time {
            color: #dc3545;
        }

        .async-time {
            color: #198754;
        }

        .summary {
            margin-top: 2rem;
            padding: 1.5rem;
            background-color: #d1e7dd;
            border-left: 5px solid #198754;
        }
    </style>
</head>

<body>

    <div class="container">
        <?php if ($requestPath === '/'): ?>

            <h1>🚀 Fasync Framework AsyncMySQLi Demo</h1>
            <p>This demo showcases the performance difference between standard, blocking <code>mysqli</code> and Fasync's non-blocking, concurrent <code>AsyncMySQLi</code> driver.</p>
            <h2>Database Benchmark</h2>
            <p>The test will execute <strong><?= QUERY_COUNT ?></strong> slow database queries (using <code>SELECT SLEEP(<?= QUERY_DELAY_SECONDS ?>)</code>). First, it will run them sequentially, then concurrently with Fasync.</p>
            <p>This will visually demonstrate how Fasync can dramatically reduce the total time spent waiting for database responses.</p>
            <p><a href="/run-demo" class="btn">Run the Benchmark &rarr;</a></p>

        <?php elseif ($requestPath === '/run-demo'): ?>

            <h1>📊 AsyncMySQLi Benchmark Results</h1>
            <p><a href="/">&larr; Back to Home</a></p>

            <div class="results-grid">
                <div class="result-card">
                    <h2>Synchronous (Blocking `mysqli`)</h2>
                    <div class="total-time sync-time">Total: <?= sprintf("%.2fms", $syncTotalTime * 1000) ?></div>
                    <div class="timeline">
                        <?php foreach ($syncTimeline as $key => $time) echo renderBar($key, $time, $syncTotalTime); ?>
                    </div>
                </div>

                <div class="result-card">
                    <h2>Asynchronous (Fasync `AsyncMySQLi`)</h2>
                    <div class="total-time async-time">Total: <?= sprintf("%.2fms", $asyncTotalTime * 1000) ?></div>
                    <div class="timeline">
                        <?php foreach ($asyncTimeline as $key => $time) echo renderBar($key, $time, $asyncTotalTime); ?>
                    </div>
                </div>
            </div>

            <div class="summary">
                <h2>Conclusion</h2>
                <p>The synchronous method took the <strong>sum</strong> of all query wait times. Fasync executed all queries <strong>concurrently</strong>, so the total time was only as long as the single longest query.</p>
                <p>The result is a <strong><?= sprintf("%.1f%% reduction in execution time (%.2fx faster)", $improvement, $speedup) ?></strong>.</p>
            </div>

        <?php else: ?>
            <?php http_response_code(404); ?>
            <h1>404 Not Found</h1>
            <p><a href="/">&larr; Back to Home</a></p>
        <?php endif; ?>
    </div>

</body>

</html>