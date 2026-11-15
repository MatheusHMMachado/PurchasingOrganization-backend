<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Accepts only requests forwarded by the frontend proxy, which adds the
 * X-Internal-Token header. Disabled when INTERNAL_TOKEN is not set (local dev).
 *
 * The backend has no public domain, so this is a second layer: it also blocks
 * other services in the Railway project, or a public domain added by mistake.
 */
class InternalTokenMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $expected = $_ENV['INTERNAL_TOKEN'] ?? '';
        $path = $request->getUri()->getPath();

        if ($expected === '' || $path === '/api/health') {
            return $handler->handle($request);
        }

        if (!hash_equals($expected, $request->getHeaderLine('X-Internal-Token'))) {
            $response = new \Slim\Psr7\Response(403);
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Forbidden']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
