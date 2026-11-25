<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$mode = $argv[1] ?? '';
$base = '/release/app';
if ($mode === 'package') {
    foreach (['private' => $base, 'public' => $base.'/public'] as $name => $root) {
        $zip = new ZipArchive;
        if ($zip->open('/release/'.$name.'.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create deployment package.');
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $top = explode('/', $relative)[0];
            if ($file->isLink()) {
                throw new RuntimeException('Unexpected symlink in deployment source.');
            }
            if ($name === 'private' && in_array($top, ['.env', 'public', 'storage'], true)) {
                continue;
            }
            if ($name === 'public' && str_starts_with($relative, 'media/uploads/')) {
                continue;
            }
            $zip->addFile($file->getPathname(), $relative);
        }
        if (! $zip->close()) {
            throw new RuntimeException('Could not finish deployment package.');
        }
    }
    echo json_encode(['packaged' => true], JSON_THROW_ON_ERROR).PHP_EOL;

    exit;
}

if (! in_array($mode, ['plan', 'migrate'], true)) {
    throw new RuntimeException('Unknown deployment operation.');
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$cipher = $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
if (! $cipher || ! $cipher->Value || ! ($connection->getConfig('options')[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? false)) {
    throw new RuntimeException('Remote migrations require verified MySQL TLS.');
}
if (! Schema::hasTable('migrations')) {
    throw new RuntimeException('Install the initial database first; this script never initializes or resets a live database.');
}
$migrator = $app->make('migrator');
$files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
$ran = $migrator->getRepository()->getRan();
$unknown = array_values(array_diff($ran, array_keys($files)));
if ($unknown) {
    throw new RuntimeException('Remote migrations are newer/different than this checkout; refusing a possible downgrade.');
}
$pending = array_values(array_diff(array_keys($files), $ran));
$counts = fn (): array => collect(['users', 'products', 'submissions'])->mapWithKeys(
    fn (string $table): array => [$table => $connection->table($table)->count()],
)->all();
$before = $counts();
if ($mode === 'migrate') {
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Remote database migration failed.');
    }
    if ($before !== $counts()) {
        throw new RuntimeException('Migration changed critical record counts; review before reopening the site.');
    }
}
echo json_encode(['pending' => $pending, 'counts' => $counts(), 'verified_tls' => true], JSON_THROW_ON_ERROR).PHP_EOL;
