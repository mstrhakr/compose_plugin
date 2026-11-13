#!/usr/bin/php
<?php

// Inspect only the bounded tail of a failed pull, never successful command output.
$file = fopen($argv[1], 'rb');
if ($file === false || fseek($file, 0, SEEK_END) !== 0) {
    fwrite(STDERR, "Unable to inspect registry failure output.\n");
    exit(2);
}
$size = ftell($file);
if ($size === false || fseek($file, max(0, $size - 65536)) !== 0) {
    fwrite(STDERR, "Unable to seek registry failure output.\n");
    exit(2);
}
$text = stream_get_contents($file);
fclose($file);
if ($text === false) {
    fwrite(STDERR, "Unable to read registry failure output.\n");
    exit(2);
}
$text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text);
if ($text === null) {
    fwrite(STDERR, "Unable to parse registry failure output.\n");
    exit(2);
}
if (!preg_match('/toomanyrequests|too many requests|\b(?:HTTP(?:\/[\d.]+)?|status(?: code)?)[\s:=]+429\b/i', $text)) {
    exit(1);
}
$delay = 3.0;
preg_match_all('/retry-after\s*:\s*([^\r\n]+)/i', $text, $matches);
foreach ($matches[1] as $value) {
    $value = trim($value);
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:$|,?\s*allowed\b)/i', $value, $seconds)) {
        $delay = max($delay, (float) $seconds[1]);
    } elseif (preg_match('/^((?:\d+(?:\.\d+)?(?:ns|us|\x{00B5}s|\x{03BC}s|ms|s|m|h))+)(?:$|[,\s])/u', $value, $duration)) {
        preg_match_all('/(\d+(?:\.\d+)?)(ns|us|\x{00B5}s|\x{03BC}s|ms|s|m|h)/u', $duration[1], $parts, PREG_SET_ORDER);
        $seconds = 0.0;
        $units = ['ns' => 1e-9, 'us' => 1e-6, "\u{00B5}s" => 1e-6, "\u{03BC}s" => 1e-6,
            'ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3600];
        foreach ($parts as $part) $seconds += (float) $part[1] * $units[$part[2]];
        $delay = max($delay, $seconds);
    } elseif (preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $value)) {
        $date = strtotime($value);
        if ($date !== false) $delay = max($delay, $date - time());
        else {
            fwrite(STDERR, "Invalid registry Retry-After date; refusing an automatic retry.\n");
            exit(2);
        }
    } else {
        fwrite(STDERR, "Unrecognized registry Retry-After value; refusing an automatic retry.\n");
        exit(2);
    }
}
// 31 represents any wait outside the permitted 30-second retry window.
echo (string) min(31, ceil($delay)), "\n";
