<?php

// Set KEY=VALUE in a .env file, adding the line if KEY is missing.
// Usage: php set-env.php <file> <KEY> <value>

[, $file, $key, $value] = $argv + [null, null, null, ''];

if (! $file || ! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
    fwrite(STDERR, "Usage: php set-env.php <file> <KEY> <value>\n");
    exit(1);
}

// Plain values are written as-is; anything else is double-quoted and escaped.
$line = preg_match('/^[A-Za-z0-9_.:\/@+-]*$/', $value)
    ? "{$key}={$value}"
    : $key.'="'.addcslashes($value, '"\\$').'"';

$contents = file_exists($file) ? file_get_contents($file) : '';
$pattern = '/^'.preg_quote($key, '/').'=.*$/m';

$contents = preg_match($pattern, $contents)
    ? preg_replace_callback($pattern, fn () => $line, $contents, 1)
    : rtrim($contents)."\n".$line."\n";

file_put_contents($file, $contents);
