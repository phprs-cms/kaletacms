<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Response;

/**
 * 3.41: a Content-Security-Policy for the public site in draft previews – the builder's canvas and draft builds
 * (?build=koncept), signed preview links, the whole-site preview and the look preview, and the pop-up and component
 * canvases. These pages are opened by people who are signed in (or hold a preview link) and show content not yet
 * published; the policy (deep audit of 10 November, hardening 1) stops a script that is not the site's own: only files
 * from the site and inline scripts carrying this request's nonce run. Kaleta's inline scripts and the code the
 * administrator put into the head, the cookie bar and the marketing slot get the nonce where they are written
 * (attribute(), withNonce()) – never by rewriting the finished page, which would bless injected markup as well.
 *
 * The published site for visitors sends no policy (third-party code the owner added must keep working, and a page from
 * the cache must not carry one request's nonce). In a preview, scripts that such code loads from other servers do not run.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** Turns the policy on for this request (the preview was recognised before anything is rendered). */
    public static function start(): void
    {
        self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** Whether this request is a preview with the policy. */
    public static function active(): bool
    {
        return self::$nonce !== null;
    }

    /** Ends the policy (tests; a second Kernel in one process). */
    public static function reset(): void
    {
        self::$nonce = null;
    }

    /** ' nonce="…"' for an inline <script> Kaleta writes; '' outside a preview. */
    public static function attribute(): string
    {
        return self::$nonce === null ? '' : ' nonce="' . self::$nonce . '"';
    }

    /**
     * Code the administrator wrote (head code, the external cookie bar, marketing code, a page's own head code) with the
     * nonce on each of its <script> tags – in a preview only; elsewhere it is returned as it is.
     */
    public static function withNonce(string $code): string
    {
        if (self::$nonce === null || $code === '') {
            return $code;
        }

        return (string) preg_replace('/<script\b(?![^>]*\snonce=)/i', '<script nonce="' . self::$nonce . '"', $code);
    }

    /** The policy itself. */
    public static function policy(): string
    {
        return "script-src 'self' 'nonce-" . self::$nonce . "'; object-src 'none'; base-uri 'self'";
    }

    /** The response with the policy when this is a preview and an HTML page that does not set its own. */
    public static function apply(Response $response): Response
    {
        if (self::$nonce === null || isset($response->headers['Content-Security-Policy']) || !str_starts_with((string) ($response->headers['Content-Type'] ?? ''), 'text/html')) {
            return $response;
        }

        return new Response($response->body, $response->status, $response->headers + ['Content-Security-Policy' => self::policy()], $response->file);
    }
}
