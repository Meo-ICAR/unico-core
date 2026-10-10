<?php

namespace Unico\Core\Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;

/**
 * Prepara un database MySQL/MariaDB di prova con le migration del pacchetto,
 * senza bisogno di un'applicazione Laravel completa.
 *
 * Variabili d'ambiente: CORE_TEST_DB_HOST, CORE_TEST_DB_PORT, CORE_TEST_DB_DATABASE,
 * CORE_TEST_DB_USERNAME, CORE_TEST_DB_PASSWORD.
 */
class CoreDatabase
{
    public static ?Capsule $capsule = null;

    public static ?FakeAuth $auth = null;

    public static function boot(): void
    {
        if (self::$capsule !== null) {
            return;
        }

        $config = new Repository([
            'unico-core' => require __DIR__.'/../../config/unico-core.php',
        ]);
        $config->set('unico-core.connection', 'core');
        $config->set('unico-core.documents_disk', 'documents');

        $app = Container::getInstance();
        $app->instance('config', $config);
        self::$auth = new FakeAuth;
        $app->instance('auth', self::$auth);
        Facade::setFacadeApplication($app);

        $capsule = new Capsule($app);
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => getenv('CORE_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('CORE_TEST_DB_PORT') ?: 3306,
            'database' => getenv('CORE_TEST_DB_DATABASE') ?: 'unico_core_test',
            'username' => getenv('CORE_TEST_DB_USERNAME') ?: 'unico',
            'password' => getenv('CORE_TEST_DB_PASSWORD') ?: 'unico',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ], 'core');
        $capsule->getDatabaseManager()->setDefaultConnection('core');
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($app));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $app->instance('db', $capsule->getDatabaseManager());
        $app->bind('db.schema', fn () => $capsule->getConnection('core')->getSchemaBuilder());

        self::$capsule = $capsule;
    }

    /** Ricrea tutte le tabelle core da zero. */
    public static function migrateFresh(): void
    {
        self::boot();

        $db = self::$capsule->getConnection('core');
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->select('SHOW TABLES') as $row) {
            $db->statement('DROP TABLE `'.array_values((array) $row)[0].'`');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');

        $files = glob(__DIR__.'/../../database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            (require $file)->up();
        }
    }

    public static function truncate(string ...$tables): void
    {
        $db = self::$capsule->getConnection('core');
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $db->table($table)->truncate();
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
