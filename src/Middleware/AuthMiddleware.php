<?php

namespace App\Middleware;

use App\Controllers\AuthController;
use App\Services\AuthService;
use App\Support\Errors;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Every /api route needs a valid session cookie, except the health check and the login
 * itself. The session row is passed to the routes as the "session" request attribute.
 */
class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_ROUTES = [
        'GET /api/health',
        'POST /api/auth/login',
        'POST /api/auth/logout',
    ];

    public function process(Request $request, RequestHandler $handler): Response
    {
        $route = $request->getMethod() . ' ' . $request->getUri()->getPath();
        if ($request->getMethod() === 'OPTIONS' || in_array($route, self::PUBLIC_ROUTES, true)) {
            return $handler->handle($request);
        }

        try {
            [$session, $renewed] = AuthService::resolve($request->getCookieParams()[AuthService::COOKIE] ?? null);
        } catch (\Throwable $e) {
            $response = new \Slim\Psr7\Response(500);
            $response->getBody()->write(json_encode(['success' => false, 'error' => Errors::report($e)]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        if (!$session) {
            $response = new \Slim\Psr7\Response(401);
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Sessão expirada ou inexistente. Entre novamente.',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Set-Cookie', AuthService::cookie(null, 'web', AuthController::secure($request)));
        }

        $response = $handler->handle($request->withAttribute('session', $session));

        // PWA session renewed: extend the cookie too, so the installed app stays logged in
        if ($renewed) {
            $token = $request->getCookieParams()[AuthService::COOKIE];
            $response = $response->withAddedHeader(
                'Set-Cookie',
                AuthService::cookie($token, 'pwa', AuthController::secure($request))
            );
        }
        return $response;
    }
}
