<?php
declare(strict_types=1);

/**
 * HockeyWeerelt "HAPI" client (app.hockeyweerelt.nl).
 *
 * De oude publieke matchcenter-API (publicaties.hockeyweerelt.nl/mc) is in 2026
 * vervangen. Het nieuwe matchcenter gebruikt een anonieme device-registratie en
 * signeert elk request:
 *
 *   POST /device/register {uuid, os:"Web"}  ->  {token}
 *   GET  <pad> met headers:
 *     X-HAPI-Authorization: <token>
 *     X-HAPI-Timestamp:     <unix-seconden>
 *     X-HAPI-Signature:     sha1(ts . pad . query . strrev(uuid))
 *     X-HAPI-Version:       7
 *
 * Pad en query worden voor het signeren ontdaan van alle tekens buiten
 * [a-zA-Z0-9-/] resp. [a-zA-Z0-9-/=]; queryparen worden zonder scheidingsteken
 * aaneengeplakt als "key=value".
 */

const HAPI_BASE = 'https://app.hockeyweerelt.nl';

// ── Device-token ──────────────────────────────────────────────

function hapi_device_path(): string
{
    return CACHE_DIR . '/hapi_device.json';
}

function hapi_register_device(): ?array
{
    // UUID v4
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 10,
        'ignore_errors' => true,
        'header'        => implode("\r\n", [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: hockey-iframe/2.0 (XAMPP local)',
        ]),
        'content'       => json_encode(['uuid' => $uuid, 'os' => 'Web']),
    ]]);

    $body = @file_get_contents(HAPI_BASE . '/device/register', false, $ctx);
    if ($body === false) return null;

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['token'])) return null;

    $device = ['uuid' => $uuid, 'token' => $data['token']];

    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
    file_put_contents(hapi_device_path(), json_encode($device), LOCK_EX);

    return $device;
}

function hapi_device(bool $forceNew = false): ?array
{
    static $device = null;
    if ($device !== null && !$forceNew) return $device;

    if (!$forceNew && file_exists(hapi_device_path())) {
        $cached = json_decode((string)file_get_contents(hapi_device_path()), true);
        if (is_array($cached) && !empty($cached['uuid']) && !empty($cached['token'])) {
            return $device = $cached;
        }
    }
    return $device = hapi_register_device();
}

// ── Gesigneerde GET met file-cache ────────────────────────────

function hapi_signature(string $path, array $query, string $uuid, string $ts): string
{
    $cleanPath = preg_replace('~[^a-zA-Z0-9\-/]+~', '', $path);
    $parts = '';
    foreach ($query as $k => $v) {
        if ($k === '') continue;
        $ck = preg_replace('~[^a-zA-Z0-9\-/=]+~', '', (string)$k);
        $cv = preg_replace('~[^a-zA-Z0-9\-/=]+~', '', is_array($v) ? implode('', $v) : (string)$v);
        $parts .= $ck . '=' . $cv;
    }
    return sha1($ts . $cleanPath . $parts . strrev($uuid));
}

function hapi_get(string $path, array $query = []): ?array
{
    $url = HAPI_BASE . $path . ($query ? '?' . http_build_query($query) : '');

    $cachePath = CACHE_DIR . '/' . md5($url) . '.json';
    if (CACHE_TTL > 0 && file_exists($cachePath) && (time() - filemtime($cachePath)) < CACHE_TTL) {
        return json_decode((string)file_get_contents($cachePath), true);
    }

    $body = hapi_fetch_raw($path, $query, $url);

    if ($body === null) return null;

    $data = json_decode($body, true);
    if ($data !== null && CACHE_TTL > 0) {
        if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
        file_put_contents($cachePath, $body, LOCK_EX);
    }
    return $data;
}

function hapi_fetch_raw(string $path, array $query, string $url, bool $retried = false): ?string
{
    $device = hapi_device();
    if ($device === null) return null;

    $ts  = (string)time();
    $sig = hapi_signature($path, $query, $device['uuid'], $ts);

    $ctx = stream_context_create(['http' => [
        'timeout'       => 10,
        'ignore_errors' => true,
        'header'        => implode("\r\n", [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: hockey-iframe/2.0 (XAMPP local)',
            'X-HAPI-Authorization: ' . $device['token'],
            'X-HAPI-Timestamp: ' . $ts,
            'X-HAPI-Signature: ' . $sig,
            'X-HAPI-Version: 7',
        ]),
    ]]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;

    // Token verlopen/ongeldig -> één keer her-registreren
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int)$m[1];
    }
    if ($status === 401 && !$retried) {
        hapi_device(true);
        return hapi_fetch_raw($path, $query, $url, true);
    }
    if ($status >= 400) return null;

    return $body;
}
