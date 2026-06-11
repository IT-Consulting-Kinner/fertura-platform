<?php
declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Wohlgeformtheits-Prüfung für UUID-Werte aus URL-/Query-/Formular-Parametern.
 *
 * Raw-SQL (`execute()`) bindet Parameter untypisiert; ein fehlgeformter Wert
 * gegen eine PG-uuid-Spalte wirft SQLSTATE 22P02 ("invalid input syntax for
 * type uuid") und damit HTTP 500 statt eines sauberen "nicht gefunden".
 * Aufrufer prüfen deshalb VOR dem Binden und behandeln fehlgeformte IDs wie
 * unbekannte. ORM-Pfade (`find()->where()`) sind nicht betroffen — dort typed
 * der ORM korrekt.
 */
final class Uuid
{
    /** Wohlgeformte UUID (kanonisches 8-4-4-4-12-Format, case-insensitiv)? */
    public static function isValid(string $value): bool
    {
        // \z statt $: ein abschließender Zeilenumbruch soll NICHT durchrutschen.
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) === 1;
    }
}
