<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * The app calls the API on its own origin (/api behind the frontend proxy), so no CORS is
 * needed. Cross-origin access is off unless an origin is listed in CORS_ALLOWED_ORIGINS
 * (comma separated, exact match). This used to be "*", which let any website call the API.
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && in_array($origin, self::allowedOrigins(), true);

        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response($allowed ? 204 : 403);
            return $allowed ? $this->addCorsHeaders($response, $origin) : $response;
        }

        $response = $handler->handle($request);
        return $allowed ? $this->addCorsHeaders($response, $origin) : $response;
    }

    private static function allowedOrigins(): array
    {
        $list = (string) ($_ENV['CORS_ALLOWED_ORIGINS'] ?? '');
        return array_values(array_filter(array_map('trim', explode(',', $list))));
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept')
            ->withHeader('Access-Control-Max-Age', '3600');
    }
}
