<?php

// Issues a proof-of-work seed for the AccessGate (see Module/AccessGate.php).
// Deliberately does not use ServiceUtils: that would pull in Authenticator,
// and this endpoint must stay feather-light because unverified clients
// (including bots) hit it.

require_once(dirname(__FILE__) . "/../ContentsPlanet.php");
require_once(dirname(__FILE__) . "/../Module/AccessGate.php");

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"error":"POST required"}';
    exit;
}

$seed = AccessGate::issueSeed($_SERVER['REMOTE_ADDR'] ?? '');
if ($seed === false) {
    http_response_code(503);
    echo '{"error":"gate not configured"}';
    exit;
}

echo json_encode(['seed' => $seed, 'bits' => AccessGate::powBits()]);
