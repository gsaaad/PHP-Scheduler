<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Auth\AuthContext;
use App\Auth\Authenticator;
use App\Controllers\AuthController;
use App\Controllers\MaintenanceController;
use App\Controllers\MetaController;
use App\Controllers\RobotController;
use App\Controllers\ScheduleController;
use App\Controllers\TaskController;
use App\Database;
use App\Exceptions\HttpException;
use App\Exceptions\TooManyRequestsException;
use App\Exceptions\UnauthorizedException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Router;

// Never render PHP errors into the response body; they are logged instead.
ini_set('display_errors', '0');

$method = Request::method();
$path   = Request::path();

// Under `php -S ... public/index.php` every request reaches this script, so
// static assets must be handed back to the server explicitly. Apache does this
// itself via the !-f / !-d conditions in public/.htaccess.
if (PHP_SAPI === 'cli-server') {
    $candidate = realpath(__DIR__ . $path);
    // realpath() containment check keeps ../ out of the static branch
    if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

try {
    // Connect lazily and once: an unauthenticated 401, a 404 or a 405 should
    // not need a database round trip.
    $connection = null;
    /** @var Closure(): PDO $db */
    $db = static function () use (&$connection): PDO {
        return $connection ??= (new Database())->getConnection();
    };

    // ------------------------------------------------------------ routes
    //
    // Routes are declared with an explicit `public` flag. Anything not marked
    // public requires authentication -- the default is closed, so a new route
    // cannot be left unprotected by forgetting to add a guard.

    $router = new Router();
    $public = [];

    $open = static function (string $method, string $path) use (&$public): void {
        $public[$method . ' ' . $path] = true;
    };

    // -- public --------------------------------------------------------
    $router->get('/', function (): void {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
    });
    $open('GET', '/');

    // Liveness/readiness for load balancers and orchestrators.
    $router->get('/health', function () use ($db): void {
        $checks = ['app' => 'ok'];
        $status = 200;

        try {
            $db()->query('SELECT 1');
            $checks['database'] = 'ok';
        } catch (Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());
            $checks['database'] = 'unreachable';
            $status = 503;
        }

        JsonResponse::send(['status' => $status === 200 ? 'ok' : 'degraded', 'checks' => $checks], $status);
    });
    $open('GET', '/health');

    $router->post('/api/auth/login', fn () => (new AuthController($db, null))->login());
    $open('POST', '/api/auth/login');

    // -- authenticated -------------------------------------------------
    $auth = null; // populated by the auth gate below, captured by reference
    /**
     * Resolves the caller for handlers that require one. Throwing rather than
     * returning null means a route accidentally added to $public whose
     * controller needs an identity fails as a clean 401, not a TypeError.
     */
    $ctx = static function () use (&$auth): AuthContext {
        if ($auth === null) {
            throw new UnauthorizedException('Authentication required for this route.');
        }
        return $auth;
    };

    $router->post('/api/auth/logout', fn () => (new AuthController($db, $ctx()))->logout());
    $router->get('/api/auth/me', fn () => (new AuthController($db, $ctx()))->me());
    $router->post('/api/auth/tokens', fn () => (new AuthController($db, $ctx()))->createToken());
    $router->delete('/api/auth/tokens/{id}', fn (string $id) => (new AuthController($db, $ctx()))->revokeToken($id));

    // Reference data for filters, scoped to what the caller can reach
    $router->get('/api/arenas', fn () => (new MetaController($db, $ctx()))->arenas());
    $router->get('/api/capabilities', fn () => (new MetaController($db, $ctx()))->capabilities());
    $router->get('/api/summary', fn () => (new MetaController($db, $ctx()))->summary());
    $router->get('/api/map', fn () => (new MetaController($db, $ctx()))->map());

    $router->get('/api/robots', fn () => (new RobotController($db, $ctx()))->index());
    $router->post('/api/robots', fn () => (new RobotController($db, $ctx()))->store());
    $router->get('/api/robots/{id}', fn (string $id) => (new RobotController($db, $ctx()))->show($id));
    $router->patch('/api/robots/{id}/status', fn (string $id) => (new RobotController($db, $ctx()))->updateStatus($id));
    $router->post('/api/robots/{id}/ping', fn (string $id) => (new RobotController($db, $ctx()))->ping($id));
    $router->post('/api/robots/{id}/charge/complete', fn (string $id) => (new RobotController($db, $ctx()))->completeCharge($id));
    $router->post('/api/robots/{id}/media/{slot}', fn (string $id, string $slot) => (new RobotController($db, $ctx()))->uploadMedia($id, $slot));
    $router->get('/api/robots/{id}/media/{slot}', fn (string $id, string $slot) => (new RobotController($db, $ctx()))->media($id, $slot));

    $router->get('/api/tasks', fn () => (new TaskController($db, $ctx()))->index());
    $router->post('/api/tasks', fn () => (new TaskController($db, $ctx()))->store());
    $router->get('/api/tasks/{id}/eligible-robots', fn (string $id) => (new TaskController($db, $ctx()))->eligibleRobots($id));
    $router->get('/api/tasks/{id}/eligibility/{robotId}', fn (string $id, string $r) => (new TaskController($db, $ctx()))->robotEligibility($id, $r));

    $router->get('/api/schedules', fn () => (new ScheduleController($db, $ctx()))->index());
    $router->get('/api/schedules/window', fn () => (new ScheduleController($db, $ctx()))->window());
    $router->post('/api/schedules', fn () => (new ScheduleController($db, $ctx()))->store());
    $router->post('/api/schedules/{id}/complete', fn (string $id) => (new ScheduleController($db, $ctx()))->complete($id));

    $router->get('/api/robots/{id}/maintenance', fn (string $id) => (new MaintenanceController($db, $ctx()))->index($id));
    $router->post('/api/robots/{id}/maintenance', fn (string $id) => (new MaintenanceController($db, $ctx()))->open($id));
    $router->post('/api/maintenance/{id}/close', fn (string $id) => (new MaintenanceController($db, $ctx()))->close($id));

    $router->get('/api/firmware', fn () => (new MaintenanceController($db, $ctx()))->firmwareIndex());
    $router->post('/api/firmware', fn () => (new MaintenanceController($db, $ctx()))->firmwareStore());
    $router->post('/api/robots/{id}/firmware', fn (string $id) => (new MaintenanceController($db, $ctx()))->firmwareApply($id));

    // ---------------------------------------------------------- dispatch

    $route = $router->match($method, $path);

    if ($route['status'] === 405) {
        header('Allow: ' . implode(', ', $route['allowed']));
        JsonResponse::error("Method {$method} not allowed for {$path}", 405, ['allowed' => $route['allowed']]);
        exit;
    }

    if ($route['status'] === 404) {
        JsonResponse::error("No route matches {$method} {$path}", 404);
        exit;
    }

    if (!isset($public[$method . ' ' . $route['pattern']])) {
        $auth = (new Authenticator($db()))->resolve(Request::headers(), Request::cookies());

        if ($auth === null) {
            header('WWW-Authenticate: Bearer realm="robot-scheduler"');
            throw new UnauthorizedException(
                'Provide a Bearer token or sign in at POST /api/auth/login.'
            );
        }
    }

    ($route['handler'])(...array_values($route['params']));
} catch (HttpException $e) {
    // 401/403/404/409/422/429 -- safe to show.
    if ($e instanceof TooManyRequestsException && !headers_sent()) {
        header('Retry-After: ' . $e->getRetryAfterSeconds());
    }
    JsonResponse::error($e->getMessage(), $e->getStatusCode(), $e->getContext());
} catch (Throwable $e) {
    // Anything else: log the detail, return a generic message.
    error_log(sprintf('[%s %s] %s in %s:%d', $method, $path, $e->getMessage(), $e->getFile(), $e->getLine()));
    JsonResponse::error('Internal server error', 500);
}
