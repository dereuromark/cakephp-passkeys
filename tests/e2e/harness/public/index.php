<?php
declare(strict_types=1);

/**
 * Minimal single-file Cake harness used by the Playwright E2E suite to load
 * the plugin against a real browser. NOT a production app — only intended to
 * answer two questions:
 *
 *  1. Does the plugin boot from a clean Cake app?
 *  2. Do the helper/cell outputs render the expected DOM hooks (data
 *     attributes, endpoints meta, hidden login button) that the browser
 *     bundle binds against?
 *
 * Static assets (the plugin's webroot/dist/passkeys.min.js) are served via
 * the PHP built-in webserver — `php -S` invokes index.php as a router
 * script, so URLs like /passkeys/dist/passkeys.min.js land here, get the
 * path inspected, and we stream the file from the plugin's webroot.
 */

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Server;
use Cake\Http\ServerRequestFactory;
use Cake\Log\Engine\FileLog;
use Cake\Log\Log;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Cake\Utility\Security;
use Cake\View\View;
use Migrations\Migrations;
use Passkeys\PasskeysPlugin;
use Passkeys\View\Helper\PasskeysHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// php -S router-script: serve the plugin's webroot/dist/* directly when the
// browser requests /passkeys/dist/passkeys.min.js so the bundle is reachable
// from the harness without standing up a separate static-file server.
if (PHP_SAPI === 'cli-server') {
    $requestedPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (str_starts_with($requestedPath, '/passkeys/dist/')) {
        $rel = substr($requestedPath, strlen('/passkeys/dist/'));
        $file = dirname(__DIR__, 4) . '/webroot/dist/' . $rel;
        if (is_file($file)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $type = match ($ext) {
                'js' => 'application/javascript',
                'map' => 'application/json',
                'css' => 'text/css',
                default => 'application/octet-stream',
            };
            header('Content-Type: ' . $type);
            readfile($file);

            return true;
        }
    }
}

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/vendor/cakephp/cakephp/src/Core/functions_global.php';
require dirname(__DIR__, 4) . '/vendor/cakephp/cakephp/src/I18n/functions_global.php';

define('ROOT', dirname(__DIR__));
define('APP_DIR', 'src');
define('APP', ROOT . '/src/');
define('CONFIG', ROOT . '/config/');
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'passkeys_e2e' . DIRECTORY_SEPARATOR);
define('LOGS', TMP . 'logs' . DIRECTORY_SEPARATOR);
define('CACHE', TMP . 'cache' . DIRECTORY_SEPARATOR);
define('CAKE_CORE_INCLUDE_PATH', dirname(__DIR__, 4) . '/vendor/cakephp/cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DIRECTORY_SEPARATOR);

@mkdir(TMP, 0777, true);
@mkdir(LOGS, 0777, true);
@mkdir(CACHE, 0777, true);

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'HarnessApp',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
    'paths' => ['plugins' => []],
]);
Configure::write('Passkeys', [
    'enabled' => true,
    'rpId' => 'localhost',
    'rpName' => 'Harness',
    'users' => [
        'table' => 'Users',
        'columns' => ['id' => 'id', 'email' => 'email', 'displayName' => 'name'],
        'activeColumn' => null,
    ],
    'tenancy' => ['column' => null, 'sessionKey' => null],
    'cache' => 'default',
]);

ConnectionManager::setConfig('default', [
    'className' => 'Cake\Database\Connection',
    'driver' => 'Cake\Database\Driver\Sqlite',
    'database' => TMP . 'e2e.sqlite',
    'cacheMetadata' => false,
    'quoteIdentifiers' => true,
]);
Cache::setConfig('default', ['className' => 'Array']);
Cache::setConfig('_cake_core_', ['className' => 'Array']);
Cache::setConfig('_cake_model_', ['className' => 'Array']);
Cache::setConfig('_cake_translations_', ['className' => 'Array']);
Log::setConfig('debug', ['className' => FileLog::class, 'path' => LOGS, 'file' => 'debug']);
Log::setConfig('error', ['className' => FileLog::class, 'path' => LOGS, 'file' => 'error']);
Security::setSalt('e2e-harness-salt-do-not-use-in-prod');

// Router collection has to be initialized before RoutingMiddleware tries to
// build a route builder against it. `Router::reload()` is the documented
// way to bootstrap the collection from scratch in a test/harness context.
\Cake\Routing\Router::reload();

// Minimal users table + a single fixed identity for the manager cell to
// render against. We don't model a real auth flow — Playwright spins this
// up to verify DOM shape, not credential storage.
$conn = ConnectionManager::get('default');
$conn->execute('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email VARCHAR(255), name VARCHAR(255))');
$conn->execute("INSERT OR IGNORE INTO users (id, email, name) VALUES (1, 'alice@example.com', 'Alice')");

$migrations = new Migrations(['connection' => 'default', 'plugin' => 'Passkeys']);
$migrations->migrate();

/**
 * Anonymous identity object used to feed `request->getAttribute('identity')`
 * for the manager cell. Matches the same shape PasskeysController expects
 * (`getIdentifier(): int|string`).
 */
final class HarnessIdentity
{
    public function __construct(private readonly int $id)
    {
    }

    public function getIdentifier(): int
    {
        return $this->id;
    }
}

/**
 * Inject a fake identity on every route except `/login` so the manager cell
 * has someone to render passkeys for, while the login page renders the
 * unauthenticated (hidden) login button.
 */
final class HarnessIdentityMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/login') {
            $request = $request->withAttribute('identity', new HarnessIdentity(1));
        }

        return $handler->handle($request);
    }
}

/**
 * Intercepts the two harness pages (`/` and `/login`) and emits HTML built
 * from the PasskeysHelper + ManagerCell. Everything else (the plugin's own
 * `/passkeys/*` JSON routes) falls through to RoutingMiddleware untouched.
 *
 * Must run AFTER RoutingMiddleware so the Router collection is populated
 * (the helper calls `Router::url(...)` for the endpoints meta).
 */
final class HarnessRenderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/' || $path === '/login') {
            \Cake\Routing\Router::setRequest($request instanceof ServerRequest ? $request : null);
            $isLogin = ($path === '/login');

            return (new Response())
                ->withType('text/html')
                ->withStringBody($this->renderPage($request, $isLogin));
        }

        return $handler->handle($request);
    }

    private function renderPage(ServerRequestInterface $request, bool $isLogin): string
    {
        $cakeRequest = $request instanceof ServerRequest ? $request : new ServerRequest();
        $view = new View($cakeRequest);
        /** @var \Passkeys\View\Helper\PasskeysHelper $passkeys */
        $passkeys = $view->loadHelper('Passkeys', ['className' => PasskeysHelper::class]);

        $body = '';
        if ($isLogin) {
            $body = '<h1>Login</h1>' . $passkeys->loginButton();
        } else {
            $body = '<h1>Settings</h1>' . (string)$view->cell('Passkeys.Manager');
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>'
            . ($isLogin ? 'Login' : 'Settings') . '</title>'
            . $passkeys->script() . $passkeys->endpointsMeta()
            . '</head><body>' . $body . '</body></html>';
    }
}

final class HarnessApplication extends BaseApplication
{
    public function bootstrap(): void
    {
        parent::bootstrap();
        if (!$this->getPlugins()->has('Passkeys')) {
            $this->addPlugin(PasskeysPlugin::class);
        }
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new HarnessIdentityMiddleware())
            ->add(new RoutingMiddleware($this))
            ->add(new HarnessRenderMiddleware())
            ->add(new \Cake\Http\Middleware\BodyParserMiddleware());
    }

    public function routes(RouteBuilder $routes): void
    {
        // Placeholder routes so RoutingMiddleware can resolve / and /login to
        // a (never-dispatched) controller. HarnessRenderMiddleware short-
        // circuits the response before dispatching ever happens.
        $routes->scope('/', function (RouteBuilder $r): void {
            $r->connect('/', ['controller' => 'Harness', 'action' => 'home']);
            $r->connect('/login', ['controller' => 'Harness', 'action' => 'login']);
        });
        parent::routes($routes);
    }
}

$server = new Server(new HarnessApplication(CONFIG));
$server->emit($server->run(ServerRequestFactory::fromGlobals()));
