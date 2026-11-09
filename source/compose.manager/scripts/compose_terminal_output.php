#!/usr/bin/php
<?php

// Keep a bounded live spool; the stack's command log retains the complete output.
$spool = fopen($argv[1], 'c+b');
$log = $argv[2] !== '' ? fopen($argv[2], 'wb') : null;
if ($spool === false || $log === false) {
    fwrite(STDERR, "Unable to open terminal output files.\n");
    exit(1);
}
$size = 0;
$generation = 0;
$followReceipt = getenv('COMPOSE_TERMINAL_FOLLOW_RECEIPT');
$menuBuffer = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 8192);
    if ($chunk === false) {
        fwrite(STDERR, "Unable to read command output.\n");
        exit(1);
    }
    if ($followReceipt !== false) {
        $menuBuffer = substr($menuBuffer . $chunk, -8192);
        $plain = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $menuBuffer);
        if ($plain !== null && preg_match('/\bd\s+Detach\b/', $plain) === 1) {
            if (file_put_contents($followReceipt . '.detach-ready', 'ready') === false) {
                fwrite(STDERR, "Unable to publish native detach readiness.\n");
                exit(1);
            }
            $followReceipt = false;
        }
    }
    if ($log !== null && (fwrite($log, $chunk) !== strlen($chunk) || !fflush($log))) {
        fwrite(STDERR, "Unable to save stack command log.\n");
        exit(1);
    }
    if (!flock($spool, LOCK_EX)) {
        fwrite(STDERR, "Unable to lock terminal output.\n");
        exit(1);
    }
    if ($size + strlen($chunk) > 1048576) {
        if (!ftruncate($spool, 0) || fseek($spool, 0) !== 0) {
            fwrite(STDERR, "Unable to rotate terminal output.\n");
            exit(1);
        }
        $chunk = "[Earlier output omitted from live viewer; see the stack command log.]\n" . $chunk;
        $size = 0;
        if (file_put_contents($argv[1] . '.generation', (string) ++$generation) === false) {
            fwrite(STDERR, "Unable to save terminal output generation.\n");
            exit(1);
        }
    }
    if (fwrite($spool, $chunk) !== strlen($chunk) || !fflush($spool)) {
        fwrite(STDERR, "Unable to save command output.\n");
        exit(1);
    }
    $size += strlen($chunk);
    flock($spool, LOCK_UN);
}
fclose($spool);
if ($log !== null) fclose($log);
