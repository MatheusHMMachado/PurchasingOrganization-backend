<?php

/*
 * Aplica database/migrations/*.sql que ainda nao rodaram, em ordem alfabetica.
 * Executado pelo docker-entrypoint.sh a cada deploy; seguro rodar varias vezes.
 *
 * Bancos criados antes deste script (setup.ps1 / docker compose) ja tem as tabelas:
 * nesse caso a 001 e registrada como aplicada sem rodar de novo.
 */

require __DIR__ . '/../vendor/autoload.php';

$_ENV = array_merge(getenv(), $_ENV);
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_PORT'] ?? '5432',
    $_ENV['DB_NAME'] ?? 'PurchasingOrganization'
);

// O banco pode ainda estar subindo no primeiro deploy
$pdo = null;
for ($attempt = 1; $attempt <= 15; $attempt++) {
    try {
        $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'postgres', $_ENV['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (PDOException $e) {
        fwrite(STDERR, "[migrate] banco indisponivel (tentativa $attempt/15): {$e->getMessage()}\n");
        sleep(2);
    }
}
if (!$pdo) {
    fwrite(STDERR, "[migrate] desistindo: nao foi possivel conectar no banco\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)');

$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    $legacy = $name === '001_create_tables.sql'
        && $pdo->query("SELECT to_regclass('public.batches') IS NOT NULL")->fetchColumn();

    $pdo->beginTransaction();
    try {
        if (!$legacy) {
            $pdo->exec(file_get_contents($file));
        }
        $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        $pdo->commit();
        echo $legacy ? "[migrate] $name ja existia, registrada\n" : "[migrate] $name aplicada\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "[migrate] falha em $name: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "[migrate] banco atualizado\n";
