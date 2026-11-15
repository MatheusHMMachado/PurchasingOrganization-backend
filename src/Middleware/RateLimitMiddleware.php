<?php

namespace App\Middleware;

use App\Services\CacheService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Per-client request limits (fixed 1-minute window, counters in Redis):
 *   - any request:          RATE_LIMIT_PER_MINUTE        (default 300)
 *   - POST / PUT / DELETE:  RATE_LIMIT_WRITES_PER_MINUTE (default 60)
 *
 * The client is identified by X-Client-IP, set by the frontend proxy (Caddy overwrites
 * whatever the browser sent). Without Redis the limit is skipped (fail open), so a cache
 * outage never takes the API down. The health check is never limited.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($request->getUri()->getPath() === '/api/health' || $request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $client = $this->clientId($request);
        $window = intdiv(time(), 60);
        $cache = CacheService::getInstance();

        $limits = [['all', (int) ($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 300)]];
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $limits[] = ['write', (int) ($_ENV['RATE_LIMIT_WRITES_PER_MINUTE'] ?? 60)];
        }

        foreach ($limits as [$bucket, $max]) {
            if ($max <= 0) {
                continue; // 0 disables that limit
            }
            $count = $cache->increment("ratelimit:{$bucket}:{$client}:{$window}", 90);
            if ($count !== null && $count > $max) {
                $retryAfter = 60 - (time() % 60);
                $response = new \Slim\Psr7\Response(429);
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Muitas requisições. Aguarde alguns segundos e tente novamente.',
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('Retry-After', (string) $retryAfter);
            }
        }

        return $handler->handle($request);
    }

    private function clientId(Request $request): string
    {
        $ip = trim($request->getHeaderLine('X-Client-IP'));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        }
        return hash('sha256', $ip); // no raw IPs stored in Redis
    }
}
