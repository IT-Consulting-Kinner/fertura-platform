<?php
declare(strict_types=1);

namespace App\Service\Registry;

use InvalidArgumentException;

/**
 * Versions-Anforderung gemäß Kap. 26.6.4: entweder eine **exakte** Version
 * (`2.3.1`) oder ein **expliziter Bereich** mit Vergleichsoperatoren
 * (`>=2.1.0 <3.0.0`). Caret/Tilde-Kurzformen sind unzulässig.
 *
 * Kompatibilitätsregel: Eine exakte Anforderung A.B.C ist mit einem Angebot
 * X.Y.Z kompatibel, wenn gleiche Major-Version (X = A) UND das Angebot
 * mindestens so neu ist (>= A.B.C). Das wird als Bereich [>=A.B.C, <(A+1).0.0]
 * abgebildet.
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

        // Exakte Version -> [>=A.B.C, <(A+1).0.0].
        if (preg_match('/^\d+\.\d+\.\d+$/', $spec)) {
            $v = SemVer::parse($spec);

            return new self([
                ['op' => '>=', 'version' => $v],
                ['op' => '<', 'version' => new SemVer($v->major + 1, 0, 0)],
            ]);
        }

        // Bereich: leerzeichengetrennte Vergleiche, z. B. ">=2.1.0 <3.0.0".
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
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
