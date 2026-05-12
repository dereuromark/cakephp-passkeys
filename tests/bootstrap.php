<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Engine\FileLog;
use Cake\Log\Log;
use Cake\Utility\Security;

require dirname(__DIR__) . '/vendor/autoload.php';

// Cell templates and other view code expect the global helper functions
// (h(), __(), __d(), ...) to be available. Real apps load these via
// vendor/cakephp/cakephp/src/*/functions_global.php during bootstrap.
require dirname(__DIR__) . '/vendor/cakephp/cakephp/src/Core/functions_global.php';
require dirname(__DIR__) . '/vendor/cakephp/cakephp/src/I18n/functions_global.php';

define('ROOT', dirname(__DIR__));
define('APP_DIR', 'src');
define('APP', ROOT . '/tests/test_app/src/');
define('CONFIG', ROOT . '/tests/test_app/config/');
define('TESTS', ROOT . '/tests/');
define('TMP', sys_get_temp_dir() . DS . 'passkeys_tests' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

@mkdir(TMP, 0777, true);
@mkdir(LOGS, 0777, true);
@mkdir(CACHE, 0777, true);

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
    'paths' => ['plugins' => [ROOT . '/tests/test_app/plugins/']],
]);

ConnectionManager::setConfig('test', [
    'className' => 'Cake\Database\Connection',
    'driver' => 'Cake\Database\Driver\Sqlite',
    'database' => ':memory:',
    'cacheMetadata' => false,
    'quoteIdentifiers' => true,
]);
ConnectionManager::alias('test', 'default');

Cache::setConfig('default', ['className' => 'Array']);
Cache::setConfig('_cake_core_', ['className' => 'Array']);
Cache::setConfig('_cake_model_', ['className' => 'Array']);
Cache::setConfig('_cake_translations_', ['className' => 'Array']);

Log::setConfig('debug', [
    'className' => FileLog::class,
    'path' => LOGS,
    'file' => 'debug',
    'levels' => ['notice', 'info', 'debug'],
]);
Log::setConfig('error', [
    'className' => FileLog::class,
    'path' => LOGS,
    'file' => 'error',
    'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
]);

Security::setSalt('passkeys-plugin-test-salt-not-secret-not-prod');
