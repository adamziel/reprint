<?php
/**
 * WASM PHP curl crash: MySQL query inside gzip WRITEFUNCTION.
 *
 * The crash happens when PDO::exec() is called inside curl's
 * CURLOPT_WRITEFUNCTION callback while curl is auto-decompressing
 * a gzip response. The Asyncify stack unwinding from the MySQL I/O
 * corrupts zlib's internal state mid-inflate().
 *
 * Native PHP: works fine.
 * WASM PHP:   "Error: fetch failed" or "RuntimeError: unreachable"
 */

$url = getenv('REPRO_URL') ?: 'http://127.0.0.1:18787';

echo "PHP " . PHP_VERSION . "\n";

$pdo = new PDO("mysql:host=127.0.0.1;dbname=repro", "root", "test",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo "MySQL: connected\n";
$pdo->exec("DROP TABLE IF EXISTS t");
$pdo->exec("CREATE TABLE t (id INT)");

$n = 0;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => 'gzip, deflate',
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) use ($pdo, &$n) {
        // Execute a MySQL query inside the curl write callback
        // while gzip decompression is in progress.
        $pdo->exec("INSERT INTO t VALUES ($n)");
        $n++;
        return strlen($data);
    },
]);

echo "Fetching with MySQL exec inside WRITEFUNCTION...\n";
curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

echo $errno ? "curl error $errno\n" : "Done: $n callbacks, no crash.\n";
