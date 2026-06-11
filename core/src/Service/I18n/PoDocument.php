<?php
declare(strict_types=1);

namespace App\Service\I18n;

/**
 * Lossless PO document model for the field editor (i18n-6, E41).
 *
 * Preserves comments (`#`, `#.`, `#:`, `#|`, `#~`), `msgctxt`, `msgid`,
 * `msgid_plural` and the `msgstr`/`msgstr[n]` values per entry. The editor only
 * edits the translation (`msgstr`); structure/keys are kept and written back
 * unchanged when serializing.
 */
class PoDocument
{
    /** @var list<array{comments:list<string>,msgctxt:?string,msgid:string,msgid_plural:?string,msgstr:list<string>}> */
    public array $entries = [];

    public static function parse(string $content): self
    {
        $doc = new self();
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $comments = [];
        $msgctxt = null;
        $msgid = null;
        $msgidPlural = null;
        $msgstr = [];
        $state = null; // 'ctxt' | 'id' | 'idplural' | array index for msgstr
        $have = false;

        $flush = function () use (&$doc, &$comments, &$msgctxt, &$msgid, &$msgidPlural, &$msgstr, &$have): void {
            if ($have) {
                $doc->entries[] = [
                    'comments' => $comments,
                    'msgctxt' => $msgctxt,
                    'msgid' => (string)($msgid ?? ''),
                    'msgid_plural' => $msgidPlural,
                    'msgstr' => $msgstr === [] ? [''] : array_values($msgstr),
                ];
            }
            $comments = [];
            $msgctxt = null;
            $msgid = null;
            $msgidPlural = null;
            $msgstr = [];
            $have = false;
        };

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                // Blank line separates entries (comments without an entry are discarded).
                if ($have) {
                    $flush();
                }
                $state = null;
                continue;
            }
            if ($line[0] === '#') {
                if ($have && $msgid !== null) {
                    // Comment after a completed entry → new entry.
                    $flush();
                }
                $comments[] = $raw;
                $state = null;
                continue;
            }
            if (str_starts_with($line, 'msgctxt ')) {
                $msgctxt = self::decode(substr($line, 8));
                $state = 'ctxt';
                $have = true;
                continue;
            }
            if (str_starts_with($line, 'msgid_plural ')) {
                $msgidPlural = self::decode(substr($line, 13));
                $state = 'idplural';
                continue;
            }
            if (str_starts_with($line, 'msgid ')) {
                // A new msgid = entry boundary, even without a separating blank
                // line (a preceding msgctxt still belongs to the same entry → msgid still null).
                if ($have && $msgid !== null) {
                    $flush();
                }
                $msgid = self::decode(substr($line, 6));
                $state = 'id';
                $have = true;
                continue;
            }
            if (preg_match('/^msgstr\[(\d+)\]\s+(.*)$/', $line, $m)) {
                $idx = (int)$m[1];
                $msgstr[$idx] = self::decode($m[2]);
                $state = 'str' . $idx;
                continue;
            }
            if (str_starts_with($line, 'msgstr ')) {
                $msgstr[0] = self::decode(substr($line, 7));
                $state = 'str0';
                continue;
            }
            if ($line[0] === '"') {
                // Continuation line for the current field.
                $val = self::decode($line);
                if ($state === 'ctxt') {
                    $msgctxt = (string)$msgctxt . $val;
                } elseif ($state === 'id') {
                    $msgid = (string)$msgid . $val;
                } elseif ($state === 'idplural') {
                    $msgidPlural = (string)$msgidPlural . $val;
                } elseif (is_string($state) && str_starts_with($state, 'str')) {
                    $idx = (int)substr($state, 3);
                    $msgstr[$idx] = ($msgstr[$idx] ?? '') . $val;
                }
                continue;
            }
        }
        $flush();

        return $doc;
    }

    public function serialize(): string
    {
        $out = [];
        foreach ($this->entries as $e) {
            foreach ($e['comments'] as $c) {
                $out[] = $c;
            }
            if ($e['msgctxt'] !== null) {
                $out[] = 'msgctxt ' . self::encode($e['msgctxt']);
            }
            $out[] = 'msgid ' . self::encode($e['msgid']);
            if ($e['msgid_plural'] !== null) {
                $out[] = 'msgid_plural ' . self::encode($e['msgid_plural']);
                foreach ($e['msgstr'] as $i => $s) {
                    $out[] = 'msgstr[' . $i . '] ' . self::encode($s);
                }
            } else {
                $out[] = 'msgstr ' . self::encode($e['msgstr'][0] ?? '');
            }
            $out[] = '';
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Editable entries (excluding the PO header = empty msgid).
     *
     * @return list<array{index:int,ctx:?string,id:string,plural:?string,msgstr:list<string>,comments:list<string>}>
     */
    public function editableEntries(): array
    {
        $out = [];
        foreach ($this->entries as $i => $e) {
            if ($e['msgid'] === '') {
                continue; // header
            }
            $out[] = [
                'index' => $i,
                'ctx' => $e['msgctxt'],
                'id' => $e['msgid'],
                'plural' => $e['msgid_plural'],
                'msgstr' => $e['msgstr'],
                'comments' => $e['comments'],
            ];
        }

        return $out;
    }

    /** Sets the msgstr values of an entry (by index). */
    public function setMsgstr(int $index, array $values): void
    {
        if (!isset($this->entries[$index])) {
            return;
        }
        $this->entries[$index]['msgstr'] = array_values(array_map('strval', $values));
    }

    private static function decode(string $quoted): string
    {
        $quoted = trim($quoted);
        if (strlen($quoted) >= 2 && $quoted[0] === '"' && substr($quoted, -1) === '"') {
            $quoted = substr($quoted, 1, -1);
        }

        return strtr($quoted, [
            '\\n' => "\n",
            '\\t' => "\t",
            '\\r' => "\r",
            '\\"' => '"',
            '\\\\' => '\\',
        ]);
    }

    private static function encode(string $value): string
    {
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\t" => '\\t',
            "\r" => '\\r',
        ]);

        return '"' . $escaped . '"';
    }
}
