<?php
declare(strict_types=1);

namespace App\Utility;

/**
 * Safety check for app-relative URLs supplied by untrusted module code before
 * they are placed into an href/attribute or a client-side navigation target.
 *
 * A value is safe only when it is a path anchored at the app root that the
 * browser's URL parser cannot re-interpret as pointing at another origin.
 * The naive "starts with '/' but not '//'" test is NOT enough: the WHATWG URL
 * parser treats a backslash like a slash for http(s) and strips ASCII
 * tab/CR/LF, so `/\evil.example` and `/<TAB>/evil.example` both resolve to a
 * foreign origin. This rejects those, closing the open-redirect that the naive
 * check leaves open when the URL reaches an anchor navigation (not covered by
 * any CSP directive).
 */
final class AppUrl
{
    /**
     * True when `$url` is a safe app-relative path (single leading '/', no
     * scheme, no protocol-relative or backslash authority, no control chars).
     */
    public static function isSafeRelative(string $url): bool
    {
        if ($url === '' || $url[0] !== '/') {
            return false;
        }
        // Protocol-relative ('//host') or backslash-authority ('/\host') that the
        // URL parser normalizes to an absolute off-origin URL.
        if (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) {
            return false;
        }
        // Any backslash or ASCII control char (tab/CR/LF/…) is normalized or
        // stripped by the URL parser and can smuggle an authority past the
        // leading-slash check.
        if (preg_match('/[\\\\\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        // A scheme separator means an absolute URL slipped through.
        return !str_contains($url, '://');
    }
}
