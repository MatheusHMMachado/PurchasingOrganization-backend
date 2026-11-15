<?php

namespace App\Controllers;

use App\Models\Offer;
use App\Models\Item;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OfferController
{
    /**
     * GET /api/items/{itemId}/offers — List offers for an item.
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        try {
            $itemId = (int) $args['itemId'];

            // Verify item exists
            $item = Item::findById($itemId);
            if (!$item) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Item not found',
                ]));
                return $response->withStatus(404);
            }

            $cache = CacheService::getInstance();
            $cached = $cache->get("item:{$itemId}:offers");

            if ($cached !== null) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'data' => $cached,
                ]));
                return $response;
            }

            $offers = Offer::findByItemId($itemId);

            $cache->set("item:{$itemId}:offers", $offers, 180);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $offers,
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
     * POST /api/items/{itemId}/offers — Create an offer for an item.
     */
    public function store(Request $request, Response $response, array $args): Response
    {
        try {
            $itemId = (int) $args['itemId'];

            // Verify item exists
            $item = Item::findById($itemId);
            if (!$item) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Item not found',
                ]));
                return $response->withStatus(404);
            }

            $data = $request->getParsedBody();

            $errors = self::validate($data ?? []);

            if (!empty($errors)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => implode(', ', $errors),
                ]));
                return $response->withStatus(400);
            }

            $data['item_id'] = $itemId;
            $offer = Offer::create($data);

            // A pending item with a registered offer is now "found"
            if ($item['status'] === 'pending') {
                Item::update($itemId, ['status' => 'found']);
            }

            self::invalidateCaches($itemId, (int) $item['batch_id']);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $offer,
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
     * DELETE /api/offers/{id} — Delete an offer.
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $existing = Offer::findById($id);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Offer not found',
                ]));
                return $response->withStatus(404);
            }

            Offer::delete($id);

            $item = Item::findById((int) $existing['item_id']);
            self::invalidateCaches((int) $existing['item_id'], $item ? (int) $item['batch_id'] : null);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => ['message' => 'Offer deleted successfully'],
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
     * PUT /api/offers/{id} — Update an offer.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $existing = Offer::findById($id);

            if (!$existing) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Offer not found',
                ]));
                return $response->withStatus(404);
            }

            $data = $request->getParsedBody() ?? [];
            $errors = self::validate($data);

            if (!empty($errors)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => implode(', ', $errors),
                ]));
                return $response->withStatus(400);
            }

            $offer = Offer::update($id, $data);

            $item = Item::findById((int) $existing['item_id']);
            self::invalidateCaches((int) $existing['item_id'], $item ? (int) $item['batch_id'] : null);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $offer,
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
     * Validate offer payload. Returns a list of error messages.
     */
    private static function validate(array $data): array
    {
        $errors = [];
        if (empty($data['ecommerce'])) {
            $errors[] = 'ecommerce is required';
        }
        if (!isset($data['price']) || !is_numeric($data['price']) || (float) $data['price'] < 0) {
            $errors[] = 'price is required and must be a non-negative number';
        }
        if (empty($data['link']) || !filter_var($data['link'], FILTER_VALIDATE_URL)) {
            $errors[] = 'link is required and must be a valid URL';
        }
        return $errors;
    }

    /**
     * Offers affect item lists (best price, status), batch summaries and the dashboard.
     */
    private static function invalidateCaches(int $itemId, ?int $batchId): void
    {
        $cache = CacheService::getInstance();
        $cache->delete("item:{$itemId}:offers");
        if ($batchId !== null) {
            $cache->delete("batch:{$batchId}:items");
        }
        $cache->delete('batches:list');
        $cache->deleteByPattern('dashboard:*');
    }
}
