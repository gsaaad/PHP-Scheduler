<?php

declare(strict_types=1);

namespace App\Http;

class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Path only, no query string, no trailing slash (except root). The old
     * router exploded the URI and indexed $uri[1]/$uri[2] unguarded, which
     * warned on "/" and "/api".
     */
    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** Decoded JSON body, or null when absent/malformed. Validator rejects null. */
    public static function jsonBody(): mixed
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    /** @return array<string, mixed> */
    public static function query(): array
    {
        return $_GET;
    }

    /**
     * Request headers, lower-cased. getallheaders() is unavailable on some
     * SAPIs, so fall back to reconstructing from $_SERVER.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return array_change_key_case($headers, CASE_LOWER);
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            // mod_rewrite strips Authorization unless it is passed through
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }

    /** @return array<string, string> */
    public static function cookies(): array
    {
        return $_COOKIE;
    }

    public static function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') {
            return true;
        }

        // Behind a load balancer or ingress, TLS terminates upstream.
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * Client IP for the audit trail. X-Forwarded-For is only trusted when
     * TRUSTED_PROXY is enabled, because it is caller-controlled otherwise.
     */
    public static function clientIp(): ?string
    {
        if (getenv('TRUSTED_PROXY') === '1') {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($ua) && $ua !== '' ? $ua : null;
    }
}
