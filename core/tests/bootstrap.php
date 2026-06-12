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

use Cake\Cache\Cache;
use Cake\Chronos\Chronos;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
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

// `skip`: The default partitions of the partitioned tables (`audit_log` and
// `event_outbox`) are children of their respective parent; dropping them directly
// fails (they are attached to the parent). They are dropped along with the parent
// anyway — so skip them on rebuild, so that adding a migration does not require a
// manual `DROP DATABASE`.
(new Migrator())->run(['skip' => ['audit_log_default', 'event_outbox_default']]);

// After building the schema the migrator truncates all tables — the
// migration-seeded **default tenant** (a system invariant; present in prod) is
// removed in the process. Without it every `users` INSERT fails on the
// `tenant_id` FK. Restore it idempotently here so the test environment matches the
// production state (cf. migration CoreTenancy).
try {
    ConnectionManager::get('default')->execute(
        'INSERT INTO tenants (id, key, name) '
        . "VALUES ('00000000-0000-0000-0000-000000000001', 'default', 'Default') "
        . 'ON CONFLICT (id) DO NOTHING',
    );
} catch (Throwable) {
}

// Clear the settings/app cache (P02) before the run: the migrator truncates the
// seed data, so a possibly persistent file cache from an earlier run would
// otherwise be stale. Within a run SettingsManager::set() invalidates selectively.
// `_cake_model_`: clear the ORM metadata cache so that schema changes (e.g. new
// columns from an added migration) reliably take effect across runs — otherwise
// the ORM layer ignores new columns on INSERT/UPDATE (cached schema).
foreach (['_app_settings_', '_app_', '_cake_model_'] as $cacheConfig) {
    try {
        Cache::clear($cacheConfig);
    } catch (Throwable) {
    }
}
