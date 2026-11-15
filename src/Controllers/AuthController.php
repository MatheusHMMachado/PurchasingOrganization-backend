<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Errors;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    /** Failed logins allowed per client in LOGIN_WINDOW seconds */
    private const MAX_FAILURES = 5;
    private const LOGIN_WINDOW = 900; // 15 minutes

    /**
     * POST /api/auth/login — {username, password, client: "web"|"pwa"}
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = (array) ($request->getParsedBody() ?? []);
            $username = is_string($data['username'] ?? null) ? trim($data['username']) : '';
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';
            $client = ($data['client'] ?? 'web') === 'pwa' ? 'pwa' : 'web';

            if ($username === '' || $password === '' || strlen($username) > 100 || strlen($password) > 200) {
                return self::json($response, ['success' => false, 'error' => 'Informe usuário e senha.'], 400);
            }

            // Brute force protection: lock a client out after repeated failures
            $clientHash = self::clientId($request);
            if (AuthService::recentFailures($clientHash, self::LOGIN_WINDOW) >= self::MAX_FAILURES) {
                return self::json($response, [
                    'success' => false,
                    'error' => 'Muitas tentativas de login. Aguarde 15 minutos e tente novamente.',
                ], 429)->withHeader('Retry-After', (string) self::LOGIN_WINDOW);
            }

            $user = AuthService::verifyCredentials($username, $password);
            if (!$user) {
                AuthService::recordFailure($clientHash);
                usleep(random_int(300000, 700000)); // slows down guessing, hides timing
                return self::json($response, ['success' => false, 'error' => 'Usuário ou senha inválidos.'], 401);
            }

            AuthService::clearFailures($clientHash);
            [$token, $session] = AuthService::createSession((int) $user['id'], $client, $request->getHeaderLine('User-Agent'));

            return self::json($response, ['success' => true, 'data' => AuthService::describe($session)])
                ->withHeader('Set-Cookie', AuthService::cookie($token, $client, self::secure($request)));
        } catch (\Throwable $e) {
            return self::json($response, ['success' => false, 'error' => Errors::report($e)], 500);
        }
    }

    /**
     * POST /api/auth/logout — ends the current session.
     */
    public function logout(Request $request, Response $response): Response
    {
        try {
            AuthService::destroy($request->getCookieParams()[AuthService::COOKIE] ?? null);
        } catch (\Throwable $e) {
            Errors::report($e); // the cookie is cleared anyway
        }
        return self::json($response, ['success' => true, 'data' => null])
            ->withHeader('Set-Cookie', AuthService::cookie(null, 'web', self::secure($request)));
    }

    /**
     * GET /api/auth/session — current session (the auth middleware already validated it).
     */
    public function session(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        return self::json($response, ['success' => true, 'data' => AuthService::describe($session)]);
    }

    /**
     * PUT /api/auth/password — {current_password, new_password}
     * Changes the logged-in user's password. Other devices of this user are logged out;
     * this one stays logged in.
     */
    public function changePassword(Request $request, Response $response): Response
    {
        try {
            $session = $request->getAttribute('session');
            $data = (array) ($request->getParsedBody() ?? []);
            $userId = (int) $session['user_id'];

            if ($denied = self::reauthenticate($request, $response, $userId, $data['current_password'] ?? null)) {
                return $denied;
            }
            if ($errors = \App\Support\Validator::password($data['new_password'] ?? null)) {
                return \App\Support\Validator::badRequest($response, $errors);
            }

            \App\Models\User::update($userId, null, password_hash($data['new_password'], PASSWORD_DEFAULT));
            AuthService::destroyUserSessions($userId, (int) $session['id']);

            return self::json($response, ['success' => true, 'data' => null]);
        } catch (\Throwable $e) {
            return self::json($response, ['success' => false, 'error' => Errors::report($e)], 500);
        }
    }

    /**
     * Confirms the acting user's current password before a sensitive change. Returns an
     * error response, or null when the password is right. Wrong answers count as failed
     * logins, so a stolen session cannot be used to guess the password.
     */
    public static function reauthenticate(Request $request, Response $response, int $userId, mixed $password): ?Response
    {
        $clientHash = self::clientId($request);
        if (AuthService::recentFailures($clientHash, self::LOGIN_WINDOW) >= self::MAX_FAILURES) {
            return self::json($response, [
                'success' => false,
                'error' => 'Muitas tentativas com senha errada. Aguarde 15 minutos.',
            ], 429)->withHeader('Retry-After', (string) self::LOGIN_WINDOW);
        }
        if (!is_string($password) || $password === '' || !AuthService::verifyPassword($userId, $password)) {
            AuthService::recordFailure($clientHash);
            return self::json($response, ['success' => false, 'error' => 'Sua senha atual está incorreta.'], 403);
        }
        return null;
    }

    /** Cookies get the Secure flag in production or when the original request was HTTPS. */
    public static function secure(Request $request): bool
    {
        return ($_ENV['APP_ENV'] ?? '') === 'production'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }

    private static function clientId(Request $request): string
    {
        $ip = trim($request->getHeaderLine('X-Client-IP'));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        }
        return hash('sha256', $ip);
    }

    public static function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($body));
        return $response->withStatus($status);
    }
}
