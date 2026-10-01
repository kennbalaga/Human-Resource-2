<?php

/**
 * Rewrite the DB_* block in .env to name one database, commenting out whatever
 * was there before.
 *
 * Used by use-local-db.sh and use-railway-db.sh. It writes the block outright
 * rather than toggling live and commented lines: a toggle has to guess which
 * block is which, and it guesses wrong the moment somebody adds a third set of
 * values or an explanatory comment that happens to look like one.
 *
 * Usage: php scripts/db-target.php <local|railway>
 */
$target = $argv[1] ?? '';

if (! in_array($target, ['local', 'railway'], true)) {
    fwrite(STDERR, "Usage: php scripts/db-target.php <local|railway>\n");
    exit(1);
}

$path = __DIR__.'/../.env';
$lines = file($path, FILE_IGNORE_NEW_LINES);

if ($lines === false) {
    fwrite(STDERR, "Could not read .env\n");
    exit(1);
}

/** Every DB_* value in the file, live or commented, so a password survives a switch. */
$known = [];
foreach ($lines as $line) {
    if (preg_match('/^#?\s*(DB_[A-Z_]+)=(.*)$/', trim($line), $m) && ! isset($known[$m[1]])) {
        $known[$m[1]] = $m[2];
    }
}

$railwayPassword = '';
foreach ($lines as $line) {
    // The Railway password is the one attached to a Railway host or a long
    // token; the local one is empty, so "last non-empty wins" is wrong. Take
    // any non-empty DB_PASSWORD, live or commented.
    if (preg_match('/^#?\s*DB_PASSWORD=(.+)$/', trim($line), $m) && trim($m[1]) !== '') {
        $railwayPassword = $m[1];
    }
}

$blocks = [
    'local' => [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'human_resource_2',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => '',
        // Opening a connection to localhost is free; persistence only pays off
        // against a remote host.
        'DB_PERSISTENT' => 'false',
    ],
    'railway' => [
        // The Railway project in use since 2026-10-01 (the old proxy,
        // shuttle.proxy.rlwy.net:28994, expired). If the name hangs on a
        // network with no NAT64 gateway, put its IPv4 in .env instead; see
        // the note there.
        'DB_HOST' => $known['DB_HOST'] ?? 'gondola.proxy.rlwy.net',
        'DB_PORT' => '53303',
        'DB_DATABASE' => 'railway',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => $railwayPassword,
        'DB_PERSISTENT' => 'true',
    ],
];

if ($target === 'railway' && $blocks['railway']['DB_PASSWORD'] === '') {
    fwrite(STDERR, "No Railway password found in .env. Nothing changed.\n");
    exit(1);
}

// Railway keeps whatever host .env already names when that host is not the
// local one, so a proxy address change made by hand is not undone here.
if ($target === 'railway' && ($blocks['railway']['DB_HOST'] === '127.0.0.1' || $blocks['railway']['DB_HOST'] === 'localhost')) {
    $blocks['railway']['DB_HOST'] = 'gondola.proxy.rlwy.net';
}

$wanted = $blocks[$target];
$out = [];
$written = false;

foreach ($lines as $line) {
    if (! preg_match('/^#?\s*(DB_(?:HOST|PORT|DATABASE|USERNAME|PASSWORD|PERSISTENT))=/', trim($line))) {
        $out[] = $line;

        continue;
    }

    if ($written) {
        continue; // every other DB_* line, live or commented, is dropped
    }

    $out[] = '# Database target: '.$target.' (set by scripts/db-target.php)';
    foreach ($wanted as $key => $value) {
        $out[] = $key.'='.$value;
    }

    // The set not in use is kept commented so the other switch can find the
    // password again.
    $other = $target === 'local' ? 'railway' : 'local';
    $out[] = '# '.$other.':';
    foreach ($blocks[$other] as $key => $value) {
        $out[] = '# '.$key.'='.$value;
    }

    $written = true;
}

file_put_contents($path, implode("\n", $out)."\n");
echo ".env now points at the {$target} database.\n";
