<?php

namespace App\Controllers;

use App\Models\Batch;
use App\Models\Item;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BatchController
{
    /**
     * GET /api/batches — List all batches with item count.
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $cache = CacheService::getInstance();
            $cached = $cache->get('batches:list');

            if ($cached !== null) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'data' => $cached,
                ]));
                return $response;
            }

            $batches = Batch::findAll();

            // Append item_count to each batch
            foreach ($batches as &$batch) {
                $batch['item_count'] = Batch::getItemCount((int) $batch['id']);
            }
            unset($batch);

            $cache->set('batches:list', $batches, 600);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $batches,
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
     * GET /api/batches/{id} — Single batch with its items.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $batch = Batch::findById($id);

            if (!$batch) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Batch not found',
                ]));
                return $response->withStatus(404);
            }

            $batch['items'] = Item::findByBatchId($id);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $batch,
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
     * POST /api/batches — Create a new batch.
     */
    public function store(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();

            if (empty($data['name'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Name is required',
                ]));
                return $response->withStatus(400);
            }

            $batch = Batch::create($data);

            // Invalidate cache
            CacheService::getInstance()->delete('batches:list');

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $batch,
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
     * PUT /api/batches/{id} — Update a batch.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $existing = Batch::findById($id);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Batch not found',
                ]));
                return $response->withStatus(404);
            }

            $data = $request->getParsedBody();
            $batch = Batch::update($id, $data);

            // Invalidate cache
            CacheService::getInstance()->delete('batches:list');

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $batch,
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
     * DELETE /api/batches/{id} — Delete a batch.
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $existing = Batch::findById($id);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Batch not found',
                ]));
                return $response->withStatus(404);
            }

            Batch::delete($id);

            // Invalidate caches
            $cache = CacheService::getInstance();
            $cache->delete('batches:list');
            $cache->delete("batch:{$id}:items");

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => ['message' => 'Batch deleted successfully'],
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
