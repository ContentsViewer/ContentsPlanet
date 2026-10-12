<?php
namespace ErrorHandling;

require_once dirname(__FILE__) . "/Logger.php";

$_SEVERITY_TO_STRING = [
    E_ERROR               => 'E_ERROR',
    E_WARNING             => 'E_WARNING',
    E_PARSE               => 'E_PARSE',
    E_NOTICE              => 'E_NOTICE',
    E_CORE_ERROR          => 'E_CORE_ERROR',
    E_CORE_WARNING        => 'E_CORE_WARNING',
    E_COMPILE_ERROR       => 'E_COMPILE_ERROR',
    E_COMPILE_WARNING     => 'E_COMPILE_WARNING',
    E_USER_ERROR          => 'E_USER_ERROR',
    E_USER_WARNING        => 'E_USER_WARNING',
    E_USER_NOTICE         => 'E_USER_NOTICE',
    E_STRICT              => 'E_STRICT',
    E_RECOVERABLE_ERROR   => 'E_RECOVERABLE_ERROR',
    E_DEPRECATED          => 'E_DEPRECATED',
    E_USER_DEPRECATED     => 'E_USER_DEPRECATED',
];

function StyledErrorHandler($severity, $message, $file, $line) {
    // error was suppressed with the @-operator.
    if (!(error_reporting() & $severity)) return;
    
    global $_SEVERITY_TO_STRING;
    $severityString = $_SEVERITY_TO_STRING[$severity] ?? 'E_UNKNOWN';

    OutputDebugLog($severity, $message, $file, $line);
    echo "<div style='color: #000; background-color: #ff7777bf; border: 1px solid #dc6363; font-size: 90%; margin: 0 0 .5em; padding: .4em; overflow: hidden; border-radius: 5px;'><b>{$severityString}</b>: {$message} in <b>{$file}</b> on line <b>{$line}</b></div>";
}

/**
 * Error handler for Service endpoints. Logs only, never writes to the body.
 *
 * Services emit machine-readable bodies (mostly JSON), so anything echoed here
 * lands in front of the payload and makes it unparseable. set_error_handler
 * also bypasses display_errors, so echoing would leak absolute paths and line
 * numbers to every client regardless of configuration. Read OutputLog.txt.
 */
function PlainErrorHandler($severity, $message, $file, $line) {
    // error was suppressed with the @-operator.
    if (!(error_reporting() & $severity)) return;

    OutputDebugLog($severity, $message, $file, $line);
}

function OutputDebugLog($severity, $message, $file, $line) {
    global $_SEVERITY_TO_STRING;
    $severityString = $_SEVERITY_TO_STRING[$severity] ?? 'E_UNKNOWN';
    // Not set under CLI, and a missing key here would raise inside the handler.
    $requestUri = $_SERVER['REQUEST_URI'] ?? '(none)';
    \logger()->critical("
RuntimeError Occurred:
    REQUEST_URI: {$requestUri}
    Severity   : {$severityString}
    Message    : {$message}
    File       : {$file}
    Line       : {$line}
");
}
