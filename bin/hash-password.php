<?php

/*
 * Generates the value for AUTH_PASSWORD_HASH.
 *
 *   php bin/hash-password.php
 *
 * Type the password when asked (it is read from the terminal, not passed as an argument,
 * so it does not end up in the shell history). Paste the printed hash into:
 *   - Railway: backend service -> Variables -> AUTH_PASSWORD_HASH (as is)
 *   - local .env: AUTH_PASSWORD_HASH='...'  (single quotes: the hash contains "$")
 */

fwrite(STDERR, 'Senha (min. 10 caracteres): ');
$password = rtrim((string) fgets(STDIN), "\r\n");

if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Senha muito curta: use pelo menos 10 caracteres.\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
