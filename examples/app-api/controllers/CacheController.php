<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class CacheController extends Controller
{
    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /**
     * Test basic cache operations
     */
    public function actionIndex()
    {
        $cache = Yii::$app->cache;
        $key = 'test_key';
        $value = ['message' => 'Hello from coroutine cache!', 'time' => time()];

        // Set cache with 60 seconds TTL
        $cache->set($key, $value, 60);

        // Get cache
        $cached = $cache->get($key);

        return [
            'operation' => 'set and get',
            'key' => $key,
            'value' => $value,
            'cached' => $cached,
            'matched' => $value === $cached,
        ];
    }

    /**
     * Test cache exists
     */
    public function actionExists()
    {
        $cache = Yii::$app->cache;
        $key = 'test_exists_key';

        // Check before setting
        $existsBefore = $cache->exists($key);

        // Set cache
        $cache->set($key, 'test value', 60);

        // Check after setting
        $existsAfter = $cache->exists($key);

        return [
            'operation' => 'exists check',
            'key' => $key,
            'exists_before' => $existsBefore,
            'exists_after' => $existsAfter,
        ];
    }

    /**
     * Test multiple set and get
     */
    public function actionMultiple()
    {
        $cache = Yii::$app->cache;
        
        $data = [
            'user:1' => ['id' => 1, 'name' => 'Alice'],
            'user:2' => ['id' => 2, 'name' => 'Bob'],
            'user:3' => ['id' => 3, 'name' => 'Charlie'],
        ];

        // Set multiple
        $cache->multiSet($data, 60);

        // Get multiple
        $cached = $cache->multiGet(array_keys($data));

        return [
            'operation' => 'multiSet and multiGet',
            'data' => $data,
            'cached' => $cached,
            'matched' => $data === $cached,
        ];
    }

    /**
     * Test add operation (only set if not exists)
     */
    public function actionAdd()
    {
        $cache = Yii::$app->cache;
        $key = 'test_add_key';

        // First add should succeed
        $firstAdd = $cache->add($key, 'first value', 60);

        // Second add should fail (key already exists)
        $secondAdd = $cache->add($key, 'second value', 60);

        $value = $cache->get($key);

        return [
            'operation' => 'add',
            'key' => $key,
            'first_add_success' => $firstAdd,
            'second_add_success' => $secondAdd,
            'final_value' => $value,
        ];
    }

    /**
     * Test delete operation
     */
    public function actionDelete()
    {
        $cache = Yii::$app->cache;
        $key = 'test_delete_key';

        // Set cache
        $cache->set($key, 'value to delete', 60);
        $existsBefore = $cache->exists($key);

        // Delete cache
        $deleted = $cache->delete($key);
        $existsAfter = $cache->exists($key);

        return [
            'operation' => 'delete',
            'key' => $key,
            'exists_before' => $existsBefore,
            'deleted' => $deleted,
            'exists_after' => $existsAfter,
        ];
    }

    /**
     * Test cache with dependencies
     */
    public function actionDependency()
    {
        $cache = Yii::$app->cache;
        $key = 'test_dependency_key';
        $dependencyKey = 'dependency_version';

        // Set dependency version
        $cache->set($dependencyKey, 1, 3600);

        // Create tag dependency
        $dependency = new \yii\caching\TagDependency([
            'tags' => ['user-1'],
        ]);

        // Set cache with dependency
        $cache->set($key, 'cached data', 60, $dependency);

        $valueBefore = $cache->get($key);

        // Invalidate dependency
        \yii\caching\TagDependency::invalidate($cache, 'user-1');

        $valueAfter = $cache->get($key);

        return [
            'operation' => 'dependency',
            'key' => $key,
            'value_before_invalidation' => $valueBefore,
            'value_after_invalidation' => $valueAfter,
        ];
    }

    /**
     * Test cache expiration
     */
    public function actionExpire()
    {
        $cache = Yii::$app->cache;
        $key = 'test_expire_key';

        // Set cache with 2 seconds TTL
        $cache->set($key, 'will expire soon', 2);

        $existsBefore = $cache->exists($key);
        
        // Wait for expiration
        sleep(3);

        $existsAfter = $cache->exists($key);

        return [
            'operation' => 'expiration test',
            'key' => $key,
            'exists_before' => $existsBefore,
            'exists_after_3_seconds' => $existsAfter,
        ];
    }

    /**
     * Benchmark cache operations
     */
    public function actionBenchmark()
    {
        $cache = Yii::$app->cache;
        $iterations = 100;

        // Benchmark single set
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $cache->set("bench:set:$i", "value $i", 60);
        }
        $setTime = microtime(true) - $start;

        // Benchmark single get
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $cache->get("bench:set:$i");
        }
        $getTime = microtime(true) - $start;

        // Benchmark multi set
        $multiData = [];
        for ($i = 0; $i < $iterations; $i++) {
            $multiData["bench:multi:$i"] = "value $i";
        }
        $start = microtime(true);
        $cache->multiSet($multiData, 60);
        $multiSetTime = microtime(true) - $start;

        // Benchmark multi get
        $start = microtime(true);
        $cache->multiGet(array_keys($multiData));
        $multiGetTime = microtime(true) - $start;

        return [
            'operation' => 'benchmark',
            'iterations' => $iterations,
            'results' => [
                'set' => [
                    'total_ms' => round($setTime * 1000, 2),
                    'avg_ms' => round(($setTime / $iterations) * 1000, 2),
                ],
                'get' => [
                    'total_ms' => round($getTime * 1000, 2),
                    'avg_ms' => round(($getTime / $iterations) * 1000, 2),
                ],
                'multi_set' => [
                    'total_ms' => round($multiSetTime * 1000, 2),
                ],
                'multi_get' => [
                    'total_ms' => round($multiGetTime * 1000, 2),
                ],
            ],
        ];
    }
}

