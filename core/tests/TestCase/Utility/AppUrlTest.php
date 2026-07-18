<?php
declare(strict_types=1);

namespace App\Test\TestCase\Utility;

use App\Utility\AppUrl;
use Cake\TestSuite\TestCase;

/**
 * Pins the app-relative URL guard against the WHATWG-parser bypasses that the
 * naive "starts with '/' but not '//'" check let through (open-redirect).
 */
class AppUrlTest extends TestCase
{
    public function testAcceptsGenuineAppRelativePaths(): void
    {
        $this->assertTrue(AppUrl::isSafeRelative('/m/ticketing/admin/mailboxes'));
        $this->assertTrue(AppUrl::isSafeRelative('/admin/tenants?page=2&sort=name'));
        $this->assertTrue(AppUrl::isSafeRelative('/'));
        // A percent-encoded slash stays a same-origin path — not a bypass.
        $this->assertTrue(AppUrl::isSafeRelative('/%2Fevil.example'));
    }

    public function testRejectsOffOriginAndSchemeUrls(): void
    {
        $this->assertFalse(AppUrl::isSafeRelative(''));
        $this->assertFalse(AppUrl::isSafeRelative('relative/path'));
        $this->assertFalse(AppUrl::isSafeRelative('https://evil.example/x'));
        $this->assertFalse(AppUrl::isSafeRelative('//evil.example')); // protocol-relative
        $this->assertFalse(AppUrl::isSafeRelative('javascript:alert(1)'));
    }

    public function testRejectsWhatwgParserBypasses(): void
    {
        // The exact bypasses the reviewer verified resolve to https://evil.example/.
        $this->assertFalse(AppUrl::isSafeRelative('/\\evil.example/phish'), 'backslash authority');
        $this->assertFalse(AppUrl::isSafeRelative("/\t/evil.example"), 'tab-smuggled authority');
        $this->assertFalse(AppUrl::isSafeRelative("/\n/evil.example"), 'newline-smuggled authority');
        $this->assertFalse(AppUrl::isSafeRelative("/\r/evil.example"), 'CR-smuggled authority');
        // A backslash anywhere is normalized to '/', so reject it outright.
        $this->assertFalse(AppUrl::isSafeRelative('/path\\..\\evil'));
    }
}
