<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\AuthService;
use App\Support\Errors;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Login accounts. Any logged-in user can manage accounts (there are no roles: all users see
 * the same data), but every change requires the acting user's own password in
 * "current_password", so an unattended open session cannot be used to take over accounts.
 */
class UserController
{
    /** GET /api/users */
    public function index(Request $request, Response $response): Response
    {
        try {
            return AuthController::json($response, ['success' => true, 'data' => User::all()]);
        } catch (\Throwable $e) {
            return AuthController::json($response, ['success' => false, 'error' => Errors::report($e)], 500);
        }
    }

    /** POST /api/users — {username, password, current_password} */
    public function store(Request $request, Response $response): Response
    {
        try {
            $session = $request->getAttribute('session');
            $data = (array) ($request->getParsedBody() ?? []);

            if ($denied = AuthController::reauthenticate($request, $response, (int) $session['user_id'], $data['current_password'] ?? null)) {
                return $denied;
            }
            $username = is_string($data['username'] ?? null) ? trim($data['username']) : null;
            $errors = array_merge(Validator::username($username), Validator::password($data['password'] ?? null));
            if ($errors) {
                return Validator::badRequest($response, $errors);
            }
            if (User::usernameTaken($username)) {
                return Validator::badRequest($response, ['username already exists']);
            }

            $user = User::create($username, password_hash($data['password'], PASSWORD_DEFAULT));
            return AuthController::json($response, ['success' => true, 'data' => $user], 201);
        } catch (\Throwable $e) {
            return AuthController::json($response, ['success' => false, 'error' => Errors::report($e)], 500);
        }
    }

    /**
     * PUT /api/users/{id} — {username?, password?, current_password}
     * A new password logs that user out everywhere (except this device, if it is yourself).
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $session = $request->getAttribute('session');
            $data = (array) ($request->getParsedBody() ?? []);
            $id = (int) $args['id'];

            if ($denied = AuthController::reauthenticate($request, $response, (int) $session['user_id'], $data['current_password'] ?? null)) {
                return $denied;
            }
            if (!User::findById($id)) {
                return AuthController::json($response, ['success' => false, 'error' => 'User not found'], 404);
            }

            $username = array_key_exists('username', $data) ? (is_string($data['username']) ? trim($data['username']) : '') : null;
            $password = array_key_exists('password', $data) && $data['password'] !== '' ? $data['password'] : null;

            $errors = [];
            if ($username !== null) {
                $errors = array_merge($errors, Validator::username($username));
            }
            if ($password !== null) {
                $errors = array_merge($errors, Validator::password($password));
            }
            if ($username === null && $password === null) {
                $errors[] = 'nothing to update';
            }
            if ($errors) {
                return Validator::badRequest($response, $errors);
            }
            if ($username !== null && User::usernameTaken($username, $id)) {
                return Validator::badRequest($response, ['username already exists']);
            }

            $user = User::update($id, $username, $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null);
            if ($password !== null) {
                $keep = $id === (int) $session['user_id'] ? (int) $session['id'] : null;
                AuthService::destroyUserSessions($id, $keep);
            }
            return AuthController::json($response, ['success' => true, 'data' => $user]);
        } catch (\Throwable $e) {
            return AuthController::json($response, ['success' => false, 'error' => Errors::report($e)], 500);
        }
    }

    /** DELETE /api/users/{id} — body {current_password}. Not yourself, not the last user. */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $session = $request->getAttribute('session');
            $data = (array) ($request->getParsedBody() ?? []);
            $id = (int) $args['id'];

            if ($denied = AuthController::reauthenticate($request, $response, (int) $session['user_id'], $data['current_password'] ?? null)) {
                return $denied;
            }
            if ($id === (int) $session['user_id']) {
                return Validator::badRequest($response, ['you cannot delete your own user']);
            }
            if (!User::findById($id)) {
                return AuthController::json($response, ['success' => false, 'error' => 'User not found'], 404);
            }
            if (User::count() <= 1) {
                return Validator::badRequest($response, ['the last user cannot be deleted']);
            }

            User::delete($id); // its sessions go with it (ON DELETE CASCADE)
            return AuthController::json($response, ['success' => true, 'data' => null]);
        } catch (\Throwable $e) {
            return AuthController::json($response, ['success' => false, 'error' => Errors::report($e)], 500);
        }
    }
}
