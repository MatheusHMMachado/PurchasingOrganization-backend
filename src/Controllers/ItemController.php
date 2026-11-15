<?php

namespace App\Controllers;

use App\Models\Item;
use App\Models\Batch;
use App\Models\Offer;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ItemController
{
    /**
     * GET /api/batches/{batchId}/items — List items for a batch.
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        try {
            $batchId = (int) $args['batchId'];

            // Verify batch exists
            $batch = Batch::findById($batchId);
            if (!$batch) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Batch not found',
                ]));
                return $response->withStatus(404);
            }

            $cache = CacheService::getInstance();
            $cached = $cache->get("batch:{$batchId}:items");

            if ($cached !== null) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'data' => $cached,
                ]));
                return $response;
            }

            $items = Item::findByBatchId($batchId);

            $cache->set("batch:{$batchId}:items", $items, 300);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $items,
            ]));
            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
            return $response->withStatus(500);
        }
    }

    /**
     * GET /api/items/{id} — Single item with its offers.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $item = Item::findById($id);

            if (!$item) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Item not found',
                ]));
                return $response->withStatus(404);
            }

            $item['offers'] = Offer::findByItemId($id);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $item,
            ]));
            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
            return $response->withStatus(500);
        }
    }

    /**
     * POST /api/batches/{batchId}/items — Create an item in a batch.
     */
    public function store(Request $request, Response $response, array $args): Response
    {
        try {
            $batchId = (int) $args['batchId'];

            // Verify batch exists
            $batch = Batch::findById($batchId);
            if (!$batch) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Batch not found',
                ]));
                return $response->withStatus(404);
            }

            $data = $request->getParsedBody();

            if (empty($data['name'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Name is required',
                ]));
                return $response->withStatus(400);
            }

            $data['batch_id'] = $batchId;
            $item = Item::create($data);

            // Invalidate cache
            $cache = CacheService::getInstance();
            $cache->delete("batch:{$batchId}:items");
            $cache->delete('batches:list');
            $cache->deleteByPattern('dashboard:*');

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $item,
            ]));
            return $response->withStatus(201);
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
            return $response->withStatus(500);
        }
    }

    /**
     * PUT /api/items/{id} — Update an item.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $existing = Item::findById($id);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Item not found',
                ]));
                return $response->withStatus(404);
            }

            $data = $request->getParsedBody();
            $item = Item::update($id, $data);

            // Invalidate cache
            $cache = CacheService::getInstance();
            $cache->delete("batch:{$existing['batch_id']}:items");
            $cache->deleteByPattern('dashboard:*');

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $item,
            ]));
            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
            return $response->withStatus(500);
        }
    }

    /**
     * DELETE /api/items/{id} — Delete an item.
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $existing = Item::findById($id);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Item not found',
                ]));
                return $response->withStatus(404);
            }

            Item::delete($id);

            // Invalidate caches
            $cache = CacheService::getInstance();
            $cache->delete("batch:{$existing['batch_id']}:items");
            $cache->delete("item:{$id}:offers");
            $cache->delete('batches:list');
            $cache->deleteByPattern('dashboard:*');

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => ['message' => 'Item deleted successfully'],
            ]));
            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
            return $response->withStatus(500);
        }
    }
}
