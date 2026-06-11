<?php
declare(strict_types=1);

namespace App\Service\Registry;

use InvalidArgumentException;

/**
 * Version requirement per ch. 26.6.4: either an **exact** version (`2.3.1`) or an
 * **explicit range** with comparison operators (`>=2.1.0 <3.0.0`). Caret/tilde
 * shorthands are not permitted.
 *
 * Compatibility rule: an exact requirement A.B.C is compatible with an offer
 * X.Y.Z when the major version matches (X = A) AND the offer is at least as new
 * (>= A.B.C). This is expressed as the range [>=A.B.C, <(A+1).0.0].
 */
final class VersionConstraint
{
    /** @var list<array{op: string, version: SemVer}> */
    private array $clauses;

    /**
     * @param list<array{op: string, version: SemVer}> $clauses
     */
    private function __construct(array $clauses)
    {
        $this->clauses = $clauses;
    }

    public static function parse(string $spec): self
    {
        $spec = trim($spec);
        if ($spec === '') {
            throw new InvalidArgumentException('Leerer Versionsausdruck.');
        }
        if (str_contains($spec, '^') || str_contains($spec, '~')) {
            throw new InvalidArgumentException(
                'Caret/Tilde sind unzulässig (Kap. 26.6.4): exakte Version oder expliziter Bereich.'
            );
        }

        // Exact version -> [>=A.B.C, <(A+1).0.0].
        if (preg_match('/^\d+\.\d+\.\d+$/', $spec)) {
            $v = SemVer::parse($spec);

            return new self([
                ['op' => '>=', 'version' => $v],
                ['op' => '<', 'version' => new SemVer($v->major + 1, 0, 0)],
            ]);
        }

        // Range: whitespace-separated comparisons, e.g. ">=2.1.0 <3.0.0".
        $clauses = [];
        foreach (preg_split('/\s+/', $spec) as $part) {
            if (!preg_match('/^(>=|<=|>|<|=)(\d+\.\d+\.\d+)$/', $part, $m)) {
                throw new InvalidArgumentException("Ungültiger Versionsausdruck: \"$part\".");
            }
            $clauses[] = ['op' => $m[1], 'version' => SemVer::parse($m[2])];
        }

        return new self($clauses);
    }

    public function isSatisfiedBy(SemVer $offered): bool
    {
        foreach ($this->clauses as $clause) {
            $cmp = $offered->compare($clause['version']);
            $ok = match ($clause['op']) {
                '>=' => $cmp >= 0,
                '>' => $cmp > 0,
                '<=' => $cmp <= 0,
                '<' => $cmp < 0,
                '=' => $cmp === 0,
                // Operators are parsed from a fixed regex set; a value outside it
                // is a programming error, not a recoverable input.
                default => throw new \LogicException('Unknown version operator: ' . $clause['op']),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
