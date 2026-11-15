<?php

/*
 * Creates a login account, or resets the password of an existing one.
 * Useful for the first user, or when every password was forgotten.
 *
 *   php bin/create-user.php
 *
 * Uses the database settings of .env (or the environment variables in a container).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\User;
use App\Services\AuthService;
use App\Support\Validator;

$_ENV = array_merge(getenv(), $_ENV);
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

function ask(string $label): string
{
    fwrite(STDERR, $label);
    return rtrim((string) fgets(STDIN), "\r\n");
}

$username = trim(ask('Usuario: '));
$password = ask('Senha (min. 10 caracteres): ');

$errors = array_merge(Validator::username($username), Validator::password($password));
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = User::findByUsername($username);

if ($existing) {
    User::update((int) $existing['id'], null, $hash);
    AuthService::destroyUserSessions((int) $existing['id']);
    echo "Senha de '{$existing['username']}' redefinida. As sessoes abertas desse usuario foram encerradas.\n";
} else {
    User::create($username, $hash);
    echo "Usuario '{$username}' criado.\n";
}
