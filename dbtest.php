<?php
// TEMPORARY diagnostic — safe (masks password). Remove after debugging.
header('Content-Type: text/plain');

$vars = ['MYSQL_URL','MYSQL_PRIVATE_URL','DATABASE_URL','DB_URL','DB_HOST','DB_NAME','DB_USER','DB_PORT'];
foreach ($vars as $v) {
    $val = getenv($v);
    if ($val === false) { echo "$v = (unset)\n"; continue; }
    echo "$v = " . preg_replace('#(://[^:]+:)[^@]+@#', '$1***@', $val) . "\n";
}
echo "----\n";

$dbUrl = getenv('MYSQL_URL') ?: getenv('MYSQL_PRIVATE_URL') ?: getenv('DATABASE_URL') ?: getenv('DB_URL') ?: '';
if ($dbUrl !== '') {
    $p = parse_url($dbUrl);
    $host = $p['host'] ?? '?'; $port = (int)($p['port'] ?? 3306);
    $user = urldecode($p['user'] ?? '?'); $pass = isset($p['pass']) ? urldecode($p['pass']) : '';
    $db   = isset($p['path']) ? ltrim($p['path'], '/') : '?';
    echo "source=URL host=$host port=$port user=$user db=$db passlen=" . strlen($pass) . "\n";
} else {
    $host = getenv('DB_HOST') ?: 'localhost'; $port = (int)(getenv('DB_PORT') ?: 3306);
    $user = getenv('DB_USER') ?: 'root'; $pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';
    $db   = getenv('DB_NAME') ?: 'alke_store';
    echo "source=DB_* host=$host port=$port user=$user db=$db passlen=" . strlen($pass) . "\n";
}

mysqli_report(MYSQLI_REPORT_OFF);
$t = microtime(true);
$c = @mysqli_connect($host, $user, $pass, $db, $port);
$ms = round((microtime(true) - $t) * 1000);
if (!$c) {
    echo "CONNECT FAIL ({$ms}ms): " . mysqli_connect_error() . "\n";
} else {
    echo "CONNECT OK ({$ms}ms)\n";
    $r = $c->query("SELECT COUNT(*) FROM products");
    if ($r) { echo "products=" . $r->fetch_row()[0] . "\n"; }
}
