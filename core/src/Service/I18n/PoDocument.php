<?php
declare(strict_types=1);

namespace App\Service\I18n;

/**
 * Verlustfreies PO-Dokument-Modell für den Feld-Editor (i18n-6, E41).
 *
 * Bewahrt Kommentare (`#`, `#.`, `#:`, `#|`, `#~`), `msgctxt`, `msgid`,
 * `msgid_plural` und die `msgstr`/`msgstr[n]`-Werte je Eintrag. Editiert wird
 * im Editor nur die Übersetzung (`msgstr`); Struktur/Schlüssel bleiben erhalten
 * und werden beim Serialisieren unverändert zurückgeschrieben.
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
                // Leerzeile trennt Einträge (Kommentare ohne Eintrag werden verworfen).
                if ($have) {
                    $flush();
                }
                $state = null;
                continue;
            }
            if ($line[0] === '#') {
                if ($have && $msgid !== null) {
                    // Kommentar nach abgeschlossenem Eintrag → neuer Eintrag.
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
                // Neuer msgid = Eintragsgrenze, auch ohne trennende Leerzeile
                // (msgctxt davor gehört noch zum selben Eintrag → msgid noch null).
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
                // Fortsetzungszeile zum aktuellen Feld.
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
     * Editierbare Einträge (ohne den PO-Header = leere msgid).
     *
     * @return list<array{index:int,ctx:?string,id:string,plural:?string,msgstr:list<string>,comments:list<string>}>
     */
    public function editableEntries(): array
    {
        $out = [];
        foreach ($this->entries as $i => $e) {
            if ($e['msgid'] === '') {
                continue; // Header
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

    /** Setzt die msgstr-Werte eines Eintrags (per Index). */
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
