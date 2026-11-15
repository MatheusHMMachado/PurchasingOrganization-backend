<?php

use Slim\Factory\AppFactory;
use App\Middleware\CorsMiddleware;
use App\Middleware\JsonMiddleware;
use App\Middleware\InternalTokenMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\AuthMiddleware;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\BatchController;
use App\Controllers\ItemController;
use App\Controllers\OfferController;
use App\Controllers\DashboardController;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables: real ones (container/Railway) win over the local .env file
$_ENV = array_merge(getenv(), $_ENV);
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Create Slim App
$app = AppFactory::create();

// Parse JSON body
$app->addBodyParsingMiddleware();

// Add middleware
$app->add(new JsonMiddleware());
$app->add(new CorsMiddleware());
$app->add(new AuthMiddleware());
$app->add(new RateLimitMiddleware());
$app->add(new InternalTokenMiddleware());

// Error details only when APP_DEBUG is really true. (bool) "false" is TRUE in PHP, which
// used to show stack traces in production; FILTER_VALIDATE_BOOLEAN reads "false" as false.
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
$errorMiddleware = $app->addErrorMiddleware($debug, true, $debug);
// Errors (404, 405, 500...) as JSON, like the rest of the API
$errorMiddleware->getDefaultErrorHandler()->forceContentType('application/json');

// --- Routes ---

// Health check (Railway); does not touch the database
$app->get('/api/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['success' => true, 'data' => ['status' => 'ok']]));
    return $response;
});

// Auth (single shared login; see AuthService)
$app->post('/api/auth/login', [AuthController::class, 'login']);
$app->post('/api/auth/logout', [AuthController::class, 'logout']);
$app->get('/api/auth/session', [AuthController::class, 'session']);
$app->put('/api/auth/password', [AuthController::class, 'changePassword']);

// Users (login accounts); every change asks for the acting user's current password
$app->get('/api/users', [UserController::class, 'index']);
$app->post('/api/users', [UserController::class, 'store']);
$app->put('/api/users/{id:[1-9][0-9]{0,8}}', [UserController::class, 'update']);
$app->delete('/api/users/{id:[1-9][0-9]{0,8}}', [UserController::class, 'destroy']);

// Dashboard
$app->get('/api/dashboard', [DashboardController::class, 'index']);

// Batches
$app->get('/api/batches', [BatchController::class, 'index']);
$app->get('/api/batches/{id:[1-9][0-9]{0,8}}', [BatchController::class, 'show']);
$app->post('/api/batches', [BatchController::class, 'store']);
$app->put('/api/batches/{id:[1-9][0-9]{0,8}}', [BatchController::class, 'update']);
$app->delete('/api/batches/{id:[1-9][0-9]{0,8}}', [BatchController::class, 'destroy']);

// Items (nested under batches for create/list)
$app->get('/api/batches/{batchId:[1-9][0-9]{0,8}}/items', [ItemController::class, 'index']);
$app->post('/api/batches/{batchId:[1-9][0-9]{0,8}}/items', [ItemController::class, 'store']);

// Items (standalone for show/update/delete)
$app->get('/api/items/{id:[1-9][0-9]{0,8}}', [ItemController::class, 'show']);
$app->put('/api/items/{id:[1-9][0-9]{0,8}}', [ItemController::class, 'update']);
$app->delete('/api/items/{id:[1-9][0-9]{0,8}}', [ItemController::class, 'destroy']);

// Offers (nested under items for create/list)
$app->get('/api/items/{itemId:[1-9][0-9]{0,8}}/offers', [OfferController::class, 'index']);
$app->post('/api/items/{itemId:[1-9][0-9]{0,8}}/offers', [OfferController::class, 'store']);

// Offers (standalone for update/delete)
$app->put('/api/offers/{id:[1-9][0-9]{0,8}}', [OfferController::class, 'update']);
$app->delete('/api/offers/{id:[1-9][0-9]{0,8}}', [OfferController::class, 'destroy']);

// Preflight OPTIONS for all API routes
$app->options('/api/{routes:.+}', function ($request, $response) {
    return $response;
});

// Run the app
$app->run();
