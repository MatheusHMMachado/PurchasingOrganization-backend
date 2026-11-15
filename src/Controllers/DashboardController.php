<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    /**
     * GET /api/dashboard — Aggregated dashboard data.
     * Query params: batch_id, item_id, min_price, max_price
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $batchId = $params['batch_id'] ?? null;
            $itemId = $params['item_id'] ?? null;
            $minPrice = $params['min_price'] ?? null;
            $maxPrice = $params['max_price'] ?? null;

            // Cache key based on filter params
            $filterKey = md5(json_encode([
                'batch_id' => $batchId,
                'item_id' => $itemId,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
            ]));

            $cache = CacheService::getInstance();
            $cached = $cache->get("dashboard:filters:{$filterKey}");

            if ($cached !== null) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'data' => $cached,
                ]));
                return $response;
            }

            $pdo = DatabaseService::getInstance()->getConnection();

            // Build WHERE conditions for offers
            $offerConditions = [];
            $offerParams = [];

            if ($batchId !== null) {
                $offerConditions[] = "i.batch_id = :batch_id";
                $offerParams['batch_id'] = (int) $batchId;
            }
            if ($itemId !== null) {
                $offerConditions[] = "o.item_id = :item_id";
                $offerParams['item_id'] = (int) $itemId;
            }
            if ($minPrice !== null) {
                $offerConditions[] = "o.price >= :min_price";
                $offerParams['min_price'] = (float) $minPrice;
            }
            if ($maxPrice !== null) {
                $offerConditions[] = "o.price <= :max_price";
                $offerParams['max_price'] = (float) $maxPrice;
            }

            $offerWhere = '';
            if (!empty($offerConditions)) {
                $offerWhere = 'WHERE ' . implode(' AND ', $offerConditions);
            }

            // --- Summary ---
            $summary = $this->getSummary($pdo, $offerWhere, $offerParams, $batchId, $itemId);

            // --- Price by ecommerce ---
            $priceByEcommerce = $this->getPriceByEcommerce($pdo, $offerWhere, $offerParams);

            // --- Price trend (grouped by day) ---
            $priceTrend = $this->getPriceTrend($pdo, $offerWhere, $offerParams);

            // --- Ecommerce distribution ---
            $ecommerceDistribution = $this->getEcommerceDistribution($pdo, $offerWhere, $offerParams);

            $data = [
                'summary' => $summary,
                'price_by_ecommerce' => $priceByEcommerce,
                'price_trend' => $priceTrend,
                'ecommerce_distribution' => $ecommerceDistribution,
            ];

            $cache->set("dashboard:filters:{$filterKey}", $data, 300);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $data,
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

    private function getSummary(\PDO $pdo, string $offerWhere, array $offerParams, ?string $batchId, ?string $itemId): array
    {
        // Total and active batches
        $batchWhere = '';
        $batchParams = [];
        if ($batchId !== null) {
            $batchWhere = 'WHERE id = :batch_id';
            $batchParams['batch_id'] = (int) $batchId;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM batches {$batchWhere}");
        $stmt->execute($batchParams);
        $totalBatches = (int) $stmt->fetch()['total'];

        $activeBatchWhere = $batchId !== null
            ? "WHERE id = :batch_id AND status = 'active'"
            : "WHERE status = 'active'";
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM batches {$activeBatchWhere}");
        $stmt->execute($batchParams);
        $activeBatches = (int) $stmt->fetch()['total'];

        // Total and pending items
        $itemConditions = [];
        $itemParams = [];
        if ($batchId !== null) {
            $itemConditions[] = "batch_id = :batch_id";
            $itemParams['batch_id'] = (int) $batchId;
        }
        $itemWhere = !empty($itemConditions) ? 'WHERE ' . implode(' AND ', $itemConditions) : '';

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM items {$itemWhere}");
        $stmt->execute($itemParams);
        $totalItems = (int) $stmt->fetch()['total'];

        $pendingItemWhere = !empty($itemConditions)
            ? "WHERE " . implode(' AND ', $itemConditions) . " AND status = 'pending'"
            : "WHERE status = 'pending'";
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM items {$pendingItemWhere}");
        $stmt->execute($itemParams);
        $pendingItems = (int) $stmt->fetch()['total'];

        // Offer stats
        $sql = "SELECT
                    COUNT(*) as total_offers,
                    COALESCE(MIN(o.price), 0) as best_price,
                    COALESCE(MAX(o.price), 0) as worst_price,
                    COALESCE(MAX(o.price) - MIN(o.price), 0) as potential_savings
                FROM offers o
                JOIN items i ON o.item_id = i.id
                {$offerWhere}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($offerParams);
        $offerStats = $stmt->fetch();

        $bestTotal = $this->getBestPriceTotal($pdo, $batchId, $itemId);

        return [
            'total_batches' => $totalBatches,
            'active_batches' => $activeBatches,
            'total_items' => $totalItems,
            'pending_items' => $pendingItems,
            'total_offers' => (int) $offerStats['total_offers'],
            'best_price' => (float) $offerStats['best_price'],
            'worst_price' => (float) $offerStats['worst_price'],
            'potential_savings' => (float) $offerStats['potential_savings'],
            'best_price_total' => $bestTotal['total'],
            'priced_items' => $bestTotal['priced_items'],
        ];
    }

    /**
     * Sum of the best (lowest) price ever registered for each item.
     *
     * Only the batch/item filters apply: the price range filter is ignored on purpose,
     * otherwise the "best" price of an item could be replaced by a worse one inside the range.
     * Items without offers add nothing and are not counted in priced_items.
     *
     * @return array{total: float, priced_items: int}
     */
    private function getBestPriceTotal(\PDO $pdo, ?string $batchId, ?string $itemId): array
    {
        $conditions = [];
        $params = [];
        if ($batchId !== null) {
            $conditions[] = 'i.batch_id = :batch_id';
            $params['batch_id'] = (int) $batchId;
        }
        if ($itemId !== null) {
            $conditions[] = 'o.item_id = :item_id';
            $params['item_id'] = (int) $itemId;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT
                    COALESCE(SUM(best.price), 0) AS total,
                    COUNT(*) AS priced_items
                FROM (
                    SELECT o.item_id, MIN(o.price) AS price
                    FROM offers o
                    JOIN items i ON o.item_id = i.id
                    {$where}
                    GROUP BY o.item_id
                ) best";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'total' => round((float) $row['total'], 2),
            'priced_items' => (int) $row['priced_items'],
        ];
    }

    private function getPriceByEcommerce(\PDO $pdo, string $offerWhere, array $offerParams): array
    {
        $sql = "SELECT
                    o.ecommerce,
                    ROUND(AVG(o.price), 2) as avg_price,
                    MIN(o.price) as min_price,
                    MAX(o.price) as max_price,
                    COUNT(*) as total_offers
                FROM offers o
                JOIN items i ON o.item_id = i.id
                {$offerWhere}
                GROUP BY o.ecommerce
                ORDER BY avg_price ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($offerParams);
        return $stmt->fetchAll();
    }

    private function getPriceTrend(\PDO $pdo, string $offerWhere, array $offerParams): array
    {
        $sql = "SELECT
                    DATE(o.found_at) as date,
                    o.ecommerce,
                    ROUND(AVG(o.price), 2) as avg_price
                FROM offers o
                JOIN items i ON o.item_id = i.id
                {$offerWhere}
                GROUP BY DATE(o.found_at), o.ecommerce
                ORDER BY date ASC, o.ecommerce ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($offerParams);
        return $stmt->fetchAll();
    }

    private function getEcommerceDistribution(\PDO $pdo, string $offerWhere, array $offerParams): array
    {
        // Get total count first
        $totalSql = "SELECT COUNT(*) as total FROM offers o JOIN items i ON o.item_id = i.id {$offerWhere}";
        $stmt = $pdo->prepare($totalSql);
        $stmt->execute($offerParams);
        $total = (int) $stmt->fetch()['total'];

        if ($total === 0) {
            return [];
        }

        $sql = "SELECT
                    o.ecommerce,
                    COUNT(*) as count,
                    ROUND((COUNT(*)::numeric / :total) * 100, 2) as percentage
                FROM offers o
                JOIN items i ON o.item_id = i.id
                {$offerWhere}
                GROUP BY o.ecommerce
                ORDER BY count DESC";

        // Merge total param with existing offerParams
        $allParams = array_merge($offerParams, ['total' => $total]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        return $stmt->fetchAll();
    }
}
