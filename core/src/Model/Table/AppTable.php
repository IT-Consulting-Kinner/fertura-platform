<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Basisklasse für alle Core-Tabellen.
 *
 * Aktiviert die universelle, zeitgeordnete UUIDv7-Erzeugung (Entscheidung E6)
 * für jede Tabelle mit einspaltigem uuid-Primärschlüssel. Das UuidV7Behavior
 * prüft den PK-Typ selbst und lässt Tabellen mit Text-/zusammengesetzten PKs
 * (z. B. admin_areas) unangetastet. So bleibt die Konvention konsistent, ohne
 * dass jede Table-Klasse das Behavior einzeln registrieren muss.
 */
class AppTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->addBehavior('UuidV7');
    }
}
