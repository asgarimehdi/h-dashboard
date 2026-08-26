#!/usr/bin/env php
<?php

// Boost MCP tool bridge for the CLI agent.
// Invokes a Laravel Boost MCP tool via the same in-process path the MCP server uses
// (`boost:execute-tool <class> <base64-args>`), and prints the MCP client output verbatim.
//
// Usage:
//   php scripts/boost_tool.php <ToolName> [json-args]
// Example:
//   php scripts/boost_tool.php DatabaseSchema '{"summary":true}'
//   php scripts/boost_tool.php DatabaseQuery '{"query":"SELECT count(*) FROM hardwares"}'
//   php scripts/boost_tool.php SearchDocs '{"queries":["livewire wire:model"],"token_limit":2000}'
//
// Maps kebab/short names (database-schema, db-schema, schema) to their Boost tool class.

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
require $repoRoot.'/vendor/autoload.php';

$nameMap = [
    'database-schema' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseSchema',
    'db-schema' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseSchema',
    'schema' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseSchema',
    'database-query' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseQuery',
    'db-query' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseQuery',
    'query' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseQuery',
    'search-docs' => 'Laravel\\Boost\\Mcp\\Tools\\SearchDocs',
    'docs' => 'Laravel\\Boost\\Mcp\\Tools\\SearchDocs',
    'get-absolute-url' => 'Laravel\\Boost\\Mcp\\Tools\\GetAbsoluteUrl',
    'url' => 'Laravel\\Boost\\Mcp\\Tools\\GetAbsoluteUrl',
    'application-info' => 'Laravel\\Boost\\Mcp\\Tools\\ApplicationInfo',
    'app-info' => 'Laravel\\Boost\\Mcp\\Tools\\ApplicationInfo',
    'browser-logs' => 'Laravel\\Boost\\Mcp\\Tools\\BrowserLogs',
    'last-error' => 'Laravel\\Boost\\Mcp\\Tools\\LastError',
    'read-log-entries' => 'Laravel\\Boost\\Mcp\\Tools\\ReadLogEntries',
    'database-connections' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseConnections',
    'db-connections' => 'Laravel\\Boost\\Mcp\\Tools\\DatabaseConnections',
    'tinker' => 'Laravel\\Boost\\Mcp\\Tools\\Tinker',
    'record-rule' => 'Laravel\\Boost\\Mcp\\Tools\\RecordRule',
];

$toolArg = $argv[1] ?? null;
if ($toolArg === null) {
    fwrite(STDERR, "Usage: php scripts/boost_tool.php <ToolName> [json-args]\n");
    fwrite(STDERR, 'Tools: '.implode(', ', array_keys($nameMap))."\n");
    exit(2);
}

$key = strtolower($toolArg);
$class = $nameMap[$key] ?? null;
// allow full class name too
if ($class === null && class_exists($toolArg) && is_subclass_of($toolArg, 'Laravel\\Mcp\\Server\\Tool')) {
    $class = $toolArg;
}
if ($class === null) {
    fwrite(STDERR, "Unknown Boost tool: {$toolArg}\n");
    fwrite(STDERR, 'Available: '.implode(', ', array_keys($nameMap))."\n");
    exit(2);
}

$jsonArgs = $argv[2] ?? '{}';
$args = json_decode($jsonArgs, true);
if (! is_array($args)) {
    fwrite(STDERR, "Invalid JSON args: {$jsonArgs}\n");
    exit(2);
}

$encoded = base64_encode(json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// Run the internal command and capture its JSON stdout (stderr separated).
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$cmd = [PHP_BINARY, $repoRoot.'/artisan', 'boost:execute-tool', $class, $encoded];
$proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
if (! is_resource($proc)) {
    fwrite(STDERR, "Failed to launch artisan subprocess.\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($exitCode !== 0) {
    fwrite(STDERR, "boost:execute-tool exited {$exitCode}\n");
    if (trim($stderr) !== '') {
        fwrite(STDERR, "stderr: {$stderr}\n");
    }
    fwrite(STDOUT, $stdout);
    exit($exitCode);
}

// The command prints: {"isError":bool,"content":[{"type":"text","text":"..."}]}
// Print the text content exactly as an MCP client would receive it.
$data = json_decode($stdout, true);
if (json_last_error() !== JSON_ERROR_NONE || ! isset($data['content'][0]['text'])) {
    // Fall back to printing raw stdout.
    fwrite(STDOUT, $stdout);
    exit(0);
}

$text = $data['content'][0]['text'];
// If the text is itself JSON (e.g. schema/query results), pretty-print for readability.
$decoded = json_decode($text, true);
if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    fwrite(STDOUT, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
} else {
    fwrite(STDOUT, $text."\n");
}
exit(0);
