<?php

namespace Unico\Core\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Unico\Core\Concerns\UsesOwningAppConnection;
use Unico\Core\MorphMap;
use Unico\Core\Tests\Support\CoreDatabase;

/** Ogni modello legge dalla propria tabella e tutte le sue relazioni usano colonne esistenti. */
class ModelsSmokeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    /** @return array<int, class-string<Model>> */
    private function models(): array
    {
        $classes = [];
        foreach (glob(__DIR__.'/../src/Models/*.php') as $file) {
            $class = 'Unico\\Core\\Models\\'.basename($file, '.php');
            if (is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** Tabelle spostate nel database dell'unica app che le usa: non fanno parte dello schema core. */
    private function isOwnedByOneApp(string $class): bool
    {
        return in_array(UsesOwningAppConnection::class, class_uses_recursive($class), true);
    }

    public function test_every_core_table_has_a_model(): void
    {
        $tables = array_map(
            fn ($r) => array_values((array) $r)[0],
            CoreDatabase::$capsule->getConnection('core')->select('show tables'),
        );
        $modelTables = array_map(fn ($c) => (new $c)->getTable(), $this->models());

        $this->assertSame([], array_values(array_diff($tables, $modelTables)));
    }

    public function test_every_model_reads_from_the_core_connection(): void
    {
        foreach ($this->models() as $class) {
            if ($this->isOwnedByOneApp($class)) {
                continue;
            }
            $this->assertSame('core', (new $class)->getConnectionName(), $class);
            $class::query()->limit(1)->get();
            $this->addToAssertionCount(1);
        }
    }

    public function test_every_relation_uses_existing_columns(): void
    {
        $checked = 0;
        foreach ($this->models() as $class) {
            if ($this->isOwnedByOneApp($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType()?->getName();
                if ($method->class !== $class || ! $type || ! is_subclass_of($type, Relation::class) || $type === MorphTo::class) {
                    continue;
                }
                $related = (new $class)->{$method->name}()->getRelated();
                if ($this->isOwnedByOneApp($related::class)) {
                    continue;
                }
                $class::query()->has($method->name)->count();
                $checked++;
            }
        }

        $this->assertTrue($checked > 80);
    }

    public function test_morph_map_points_to_real_models_with_unique_aliases(): void
    {
        $map = MorphMap::all();

        foreach ($map as $alias => $class) {
            $this->assertTrue(is_subclass_of($class, Model::class), "$alias → $class");
            $this->assertSame(strtolower($alias), $alias);
        }
        $this->assertSame($map['cliente'], $map['clienti']);
        unset($map['clienti']);
        $this->assertSame(count($map), count(array_unique(array_values($map))));
    }
}
