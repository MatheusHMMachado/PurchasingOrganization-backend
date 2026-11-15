<?php

use Slim\Factory\AppFactory;
use App\Middleware\CorsMiddleware;
use App\Middleware\JsonMiddleware;
use App\Middleware\InternalTokenMiddleware;
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
$app->add(new InternalTokenMiddleware());

// Add Slim error middleware
$app->addErrorMiddleware(
    (bool) ($_ENV['APP_DEBUG'] ?? false),
    true,
    true
);

// --- Routes ---

// Health check (Railway); does not touch the database
$app->get('/api/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['success' => true, 'data' => ['status' => 'ok']]));
    return $response;
});

// Dashboard
$app->get('/api/dashboard', [DashboardController::class, 'index']);

// Batches
$app->get('/api/batches', [BatchController::class, 'index']);
$app->get('/api/batches/{id}', [BatchController::class, 'show']);
$app->post('/api/batches', [BatchController::class, 'store']);
$app->put('/api/batches/{id}', [BatchController::class, 'update']);
$app->delete('/api/batches/{id}', [BatchController::class, 'destroy']);

// Items (nested under batches for create/list)
$app->get('/api/batches/{batchId}/items', [ItemController::class, 'index']);
$app->post('/api/batches/{batchId}/items', [ItemController::class, 'store']);

// Items (standalone for show/update/delete)
$app->get('/api/items/{id}', [ItemController::class, 'show']);
$app->put('/api/items/{id}', [ItemController::class, 'update']);
$app->delete('/api/items/{id}', [ItemController::class, 'destroy']);

// Offers (nested under items for create/list)
$app->get('/api/items/{itemId}/offers', [OfferController::class, 'index']);
$app->post('/api/items/{itemId}/offers', [OfferController::class, 'store']);

// Offers (standalone for update/delete)
$app->put('/api/offers/{id}', [OfferController::class, 'update']);
$app->delete('/api/offers/{id}', [OfferController::class, 'destroy']);

// Preflight OPTIONS for all API routes
$app->options('/api/{routes:.+}', function ($request, $response) {
    return $response;
});

// Run the app
$app->run();
