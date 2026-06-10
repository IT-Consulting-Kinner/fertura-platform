<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.0.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Chronos\Chronos;
use Cake\Core\Configure;
use Cake\TestSuite\ConnectionHelper;
use Migrations\TestSuite\Migrator;

/**
 * Test runner bootstrap.
 *
 * Add additional configuration/setup your application needs when running
 * unit tests in this file.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/bootstrap.php';

if (empty($_SERVER['HTTP_HOST']) && !Configure::read('App.fullBaseUrl')) {
    Configure::write('App.fullBaseUrl', 'http://localhost');
}

// Fixate now to avoid one-second-leap-issues
Chronos::setTestNow(Chronos::now());

// Fixate sessionid early on, as php7.2+
// does not allow the sessionid to be set after stdout
// has been written to.
session_id('cli');

// Connection aliasing needs to happen before migrations are run.
// Otherwise, table objects inside migrations would use the default datasource
ConnectionHelper::addTestAliases();

// Use migrations to build test database schema.
//
// Will rebuild the database if the migration state differs
// from the migration history in files.
//
// If you are not using CakePHP's migrations you can
// hook into your migration tool of choice here or
// load schema from a SQL dump file with
// use Cake\TestSuite\Fixture\SchemaLoader;
// (new SchemaLoader())->loadSqlFiles('./tests/schema.sql', 'test');

// `skip`: Die Default-Partitionen der partitionierten Tabellen (`audit_log` und
// `event_outbox`) sind Kinder ihres jeweiligen Parents; sie direkt zu droppen
// scheitert (sie hängen am Parent). Beim Drop des Parents fallen sie ohnehin mit
// – also beim Rebuild auslassen, damit das Hinzufügen einer Migration kein
// manuelles `DROP DATABASE` erfordert.
(new Migrator())->run(['skip' => ['audit_log_default', 'event_outbox_default']]);

// Der Migrator truncatet nach dem Schema-Aufbau alle Tabellen — der per Migration
// geseedete **Default-Mandant** (System-Invariante; in Prod vorhanden) fällt dabei
// weg. Ohne ihn schlägt jeder `users`-INSERT an der `tenant_id`-FK fehl. Hier
// idempotent wiederherstellen, damit die Testumgebung dem Produktionszustand
// entspricht (vgl. Migration CoreTenancy).
try {
    \Cake\Datasource\ConnectionManager::get('default')->execute(
        "INSERT INTO tenants (id, key, name) "
        . "VALUES ('00000000-0000-0000-0000-000000000001', 'default', 'Default') "
        . 'ON CONFLICT (id) DO NOTHING',
    );
} catch (\Throwable) {
}

// Settings-/App-Cache (P02) vor dem Lauf leeren: der Migrator truncatet die
// Seed-Daten, ein evtl. persistenter Datei-Cache aus einem früheren Lauf wäre
// sonst stale. Innerhalb eines Laufs invalidiert SettingsManager::set() gezielt.
foreach (['_app_settings_', '_app_'] as $cacheConfig) {
    try {
        \Cake\Cache\Cache::clear($cacheConfig);
    } catch (\Throwable) {
    }
}
