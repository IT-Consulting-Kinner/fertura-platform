<?php
declare(strict_types=1);

namespace App\Service\Registry;

use InvalidArgumentException;
use Stringable;

/**
 * Semantic-Versioning-Wert (MAJOR.MINOR.PATCH), Kap. 26.6.4.
 */
final class SemVer implements Stringable
{
    public function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {
    }

    public static function parse(string $version): self
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', trim($version), $m)) {
            throw new InvalidArgumentException("Ungültige Version: \"$version\" (erwartet MAJOR.MINOR.PATCH).");
        }

        return new self((int)$m[1], (int)$m[2], (int)$m[3]);
    }

    /** @return int -1|0|1 */
    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch]
            <=> [$other->major, $other->minor, $other->patch];
    }

    public function __toString(): string
    {
        return "$this->major.$this->minor.$this->patch";
    }
}
