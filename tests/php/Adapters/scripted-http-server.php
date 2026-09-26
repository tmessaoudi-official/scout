<?php

declare(strict_types=1);

/*
 * A one-request HTTP responder for wire-level client tests, forked by NetworkAdaptersTest.
 *
 * Not a test and not a class — PHPUnit never scans it (no `Test.php` suffix) and PSR-4 never loads
 * it. It exists because `CurlHttpClient` speaks through real libcurl, so the only way to assert
 * what the client PUTS ON THE WIRE — the User-Agent above all, hard rule 5 — is to be the server.
 * A constant-pinning test cannot see the wiring: cURL would happily send a disguise while the
 * honest constant sits unread, and a review demonstrated exactly that.
 *
 *   argv[1]  transcript path: every request-head line received, one per line, written before exit.
 *   argv[2]  optional `redirect:<location>` — answer 301 with that Location instead of the 200.
 *
 * Prints `host:port` on stdout once listening (the OS picks the port). Responds to the single
 * request with a fixed 200 and a small JSON body, then exits. Every socket wait is bounded at
 * 5 seconds, so a wedged client cannot hang the suite.
 */

$transcriptPath = $argv[1];
$redirectTo = str_starts_with($argv[2] ?? '', 'redirect:') ? substr($argv[2], strlen('redirect:')) : null;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);

$transcript = [];
$flush = static function () use (&$transcript, $transcriptPath): void {
    file_put_contents($transcriptPath, implode("\n", $transcript) . "\n");
};

$conn = @stream_socket_accept($server, 5);
if ($conn === false) {
    $flush();
    exit(1);
}

stream_set_timeout($conn, 5);

// The request head ends at the first blank line.
while (($line = @fgets($conn, 8192)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        break;
    }
    $transcript[] = $line;
}

// A POST carries a body after the blank line, and closing without reading it makes some clients
// report a broken transfer instead of accepting the response. Read exactly what Content-Length
// announces and put it in the transcript — the ntfy wire test asserts on it.
$announced = 0;
foreach ($transcript as $line) {
    if (preg_match('~^Content-Length:\s*(\d+)$~i', $line, $m) === 1) {
        $announced = min((int) $m[1], 1024 * 1024);
    }
}

$received = '';
while (strlen($received) < $announced) {
    $chunk = @fread($conn, $announced - strlen($received));
    if ($chunk === false || $chunk === '') {
        break;
    }
    $received .= $chunk;
}

if ($received !== '') {
    $transcript[] = $received;
}

$body = $redirectTo === null ? '{"results":{"items":[]}}' : '';
@fwrite(
    $conn,
    ($redirectTo === null ? "HTTP/1.1 200 OK\r\n" : "HTTP/1.1 301 Moved Permanently\r\nLocation: " . $redirectTo . "\r\n")
    . "Content-Type: application/json\r\n"
    . 'Content-Length: ' . strlen($body) . "\r\n"
    . "Connection: close\r\n"
    . "\r\n"
    . $body,
);

$flush();
@fclose($conn);
exit(0);
