<?php
declare(strict_types=1);

/**
 * Coverage-Ratschet-Gate (#Reife/Qualität). Liest einen Clover-Bericht und bricht
 * mit Exit-Code 1 ab, wenn die Zeilenabdeckung unter das committete Minimum
 * (`core/coverage-min.txt`) fällt. Das Minimum wird über die Zeit **nur angehoben**,
 * nie gesenkt — so kann die Abdeckung nicht unbemerkt zurückfallen.
 *
 * Aufruf: php bin/coverage-check.php [coverage.xml] [coverage-min.txt]
 */

$cloverPath = $argv[1] ?? 'coverage.xml';
$minPath = $argv[2] ?? __DIR__ . '/../coverage-min.txt';

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage-check: Clover-Bericht nicht gefunden: {$cloverPath}\n");
    exit(2);
}
$min = (float)trim((string)@file_get_contents($minPath));

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "coverage-check: Clover-XML nicht lesbar: {$cloverPath}\n");
    exit(2);
}

$statements = 0;
$covered = 0;
foreach ($xml->xpath('//file/metrics') ?: [] as $m) {
    $statements += (int)$m['statements'];
    $covered += (int)$m['coveredstatements'];
}

$pct = $statements > 0 ? $covered / $statements * 100 : 0.0;
printf("Zeilenabdeckung: %.2f%% (%d/%d), Minimum: %.2f%%\n", $pct, $covered, $statements, $min);

// kleine Epsilon-Toleranz gegen Fließkomma-Jitter
if ($pct + 0.005 < $min) {
    fwrite(STDERR, sprintf(
        "FAIL: Abdeckung %.2f%% liegt unter dem Minimum %.2f%%. Tests ergänzen oder Ursache beheben.\n",
        $pct,
        $min,
    ));
    exit(1);
}
echo "OK: Abdeckung erfüllt das Minimum.\n";
exit(0);
