<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;

require dirname(__DIR__) . '/vendor/autoload.php';

define('ROOT', dirname(__DIR__));
define('APP_DIR', 'src');
define('APP', ROOT . '/tests/test_app/src/');
define('CONFIG', ROOT . '/tests/test_app/config/');
define('TESTS', ROOT . '/tests/');
define('TMP', sys_get_temp_dir() . DS . 'passkeys_tests' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . '/vendor/cakephp/cakephp');

@mkdir(TMP, 0777, true);
@mkdir(LOGS, 0777, true);
@mkdir(CACHE, 0777, true);

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'TestApp',
    'paths' => ['plugins' => [ROOT . '/tests/test_app/plugins/']],
]);

ConnectionManager::setConfig('test', [
    'className' => 'Cake\Database\Connection',
    'driver' => 'Cake\Database\Driver\Sqlite',
    'database' => ':memory:',
    'cacheMetadata' => false,
    'quoteIdentifiers' => true,
]);

\Cake\Cache\Cache::setConfig('default', ['className' => 'Array']);
