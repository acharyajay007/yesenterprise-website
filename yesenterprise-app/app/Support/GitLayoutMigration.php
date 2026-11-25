<?php

namespace App\Support;

use Dotenv\Dotenv;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class GitLayoutMigration
{
    private string $legacy;

    private string $target;

    private string $directory;

    private bool $staging;

    public function __construct(private readonly string $public)
    {
        $this->staging = basename($public) === 'yesenterprise-stage' && basename(dirname($public)) === 'public_html';
        if ((! $this->staging && basename($public) !== 'public_html') || is_link($public)) {
            throw new RuntimeException('Migration is restricted to the live public_html directory.');
        }
        $this->legacy = ($this->staging ? dirname(dirname($public)) : dirname($public)).'/yesenterprise-app';
        $this->target = $public.'/yesenterprise-app';
        $this->directory = ($this->staging ? $this->target : $this->legacy).'/storage/app/private/git-layout-migration';
        foreach ([$this->legacy, $this->target] as $path) {
            $this->noLinks($path);
            if (! is_dir($path) || is_link($path)) {
                throw new RuntimeException('Both application directories must exist without symlinks.');
            }
        }
        if (trim($this->read($this->target.'/.htaccess')) !== 'Require all denied'
            || ! str_contains($this->read($public.'/.htaccess'), 'RewriteRule ^yesenterprise-app(?:/|$) - [F,L,NC]')) {
            throw new RuntimeException('Application protection is missing.');
        }
    }

    public function dispatch(string $body, string $timestamp, string $nonce, string $signature): array
    {
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || ! preg_match('/^[a-f0-9]{32}$/D', $nonce) || strlen($body) > 2048) {
            throw new RuntimeException('Invalid or expired migration authorization.');
        }
        $environment = Dotenv::parse($this->read($this->legacy.'/.env'));
        $key = $environment['APP_KEY'] ?? '';
        if (! str_starts_with($key, 'base64:') || strlen($key) < 40) {
            throw new RuntimeException('The existing production application key is unavailable.');
        }
        $derived = hash_hmac('sha256', 'yesenterprise:git-layout:v1', $key, true);
        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, $derived);
        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid migration authorization.');
        }
        $request = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($request) || array_keys($request) !== ['action']
            || ! in_array($request['action'], ['status', 'begin', 'copy', 'verify', 'finish', 'cancel', 'stage-init', 'stage-check'], true)) {
            throw new RuntimeException('Unsupported migration request.');
        }
        if ($this->staging !== in_array($request['action'], ['stage-init', 'stage-check'], true)) {
            throw new RuntimeException('Staging permits only nonsecret retention probes; live migration actions are refused.');
        }
        $this->mkdir($this->directory);
        if (is_link($this->directory.'/migration.lock')) {
            throw new RuntimeException('The migration lock must not be a symlink.');
        }
        $lock = fopen($this->directory.'/migration.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another migration request holds the lock.');
        }
        try {
            $nonces = is_file($this->directory.'/nonces.json')
                ? json_decode($this->read($this->directory.'/nonces.json'), true, 512, JSON_THROW_ON_ERROR) : [];
            $nonces = array_filter($nonces, fn (int $used): bool => $used >= time() - 300);
            if (isset($nonces[$nonce])) {
                throw new RuntimeException('Migration authorization has already been used.');
            }
            $nonces[$nonce] = time();
            $this->write($this->directory.'/nonces.json', json_encode($nonces, JSON_THROW_ON_ERROR));
            $state = is_file($this->directory.'/state.json')
                ? json_decode($this->read($this->directory.'/state.json'), true, 512, JSON_THROW_ON_ERROR) : null;

            return match ($request['action']) {
                'status' => ['phase' => $state['phase'] ?? 'not_started', 'copied' => $state['copied'] ?? 0,
                    'files' => isset($state['files']) ? count($state['files']) : 0,
                    'ready_after' => $state['ready_after'] ?? 0],
                'begin' => $this->begin($state),
                'copy' => $this->copyBatch($this->state($state)),
                'verify' => $this->verify($this->state($state)),
                'finish' => $this->finish($this->state($state)),
                'cancel' => $this->cancel($this->state($state)),
                'stage-init' => $this->stageProbe(true),
                'stage-check' => $this->stageProbe(false),
            };
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function stageProbe(bool $initialize): array
    {
        $proof = $this->directory.'/stage-proof.json';
        if ($initialize) {
            if (is_file($proof)) {
                throw new RuntimeException('Staging retention probes already exist; verify rather than overwrite them.');
            }
            $paths = ['yesenterprise-app/.env', 'yesenterprise-app/storage/app/private/git-layout-retention-test.txt',
                'media/uploads/git-layout-retention-test.txt'];
            $hashes = [];
            foreach ($paths as $path) {
                $file = $this->public.'/'.$path;
                if (! is_file($file)) {
                    $this->write($file, $path === 'yesenterprise-app/.env'
                        ? "APP_DEBUG=false\nDEPLOYMENT_RETENTION_TEST=git-only-stage\n" : 'GIT_ONLY_RETENTION_PROBE');
                } elseif ($path !== 'yesenterprise-app/.env') {
                    throw new RuntimeException('A staging probe destination already exists; refusing to overwrite it.');
                }
                $hashes[$path] = $this->hash($file);
            }
            $this->write($proof, json_encode($hashes, JSON_THROW_ON_ERROR));
        }
        $hashes = json_decode($this->read($proof), true, 512, JSON_THROW_ON_ERROR);
        foreach ($hashes as $path => $hash) {
            if ($this->hash($this->public.'/'.$path) !== $hash) {
                throw new RuntimeException('Staging runtime preservation failed; do not deploy into the live directory.');
            }
        }

        return ['phase' => 'stage-retained', 'environment_storage_uploads_unchanged' => true];
    }

    private function begin(?array $state): array
    {
        if ($state !== null && $state['phase'] !== 'cancelled') {
            throw new RuntimeException('A migration already exists; inspect or resume its state.');
        }
        $previous = [];
        if ($state !== null) {
            foreach (array_slice($state['files'], 0, $state['copied']) as $entry) {
                if ($entry['area'] === 'private') {
                    $previous[$entry['path']] = $entry['hash'];
                }
            }
            $this->write($this->directory.'/cancelled-'.$state['token'].'.json', json_encode($state, JSON_THROW_ON_ERROR));
        }
        if (! str_contains($this->read($this->public.'/index.php'), '../yesenterprise-app/storage/framework/maintenance.php')) {
            throw new RuntimeException('The bootstrap entry point must still load the original application.');
        }
        foreach ([$this->legacy, $this->target] as $root) {
            if (is_file($root.'/storage/framework/maintenance.php') || is_file($root.'/storage/framework/down')) {
                throw new RuntimeException('An existing maintenance state must not be overwritten.');
            }
        }
        $execution = (int) ini_get('max_execution_time');
        if ($execution < 1 || $execution > 600) {
            throw new RuntimeException('A bounded hosting request timeout is required before draining active requests.');
        }
        $token = bin2hex(random_bytes(16));
        $marker = "<?php\n// Git migration ".$token."\nhttp_response_code(503);\nheader('Retry-After: 60');\necho 'Website update in progress';\nexit;\n";
        $this->write($this->directory.'/index-before.php', $this->read($this->public.'/index.php'));
        $state = ['phase' => 'initializing', 'marker' => $marker, 'copied' => 0, 'files' => [],
            'token' => $token, 'previous' => $previous, 'ready_after' => time() + max(30, $execution)];
        $this->save($state);
        foreach ([$this->legacy, $this->target] as $root) {
            $this->write($root.'/storage/framework/maintenance.php', $marker);
        }
        $state['phase'] = 'draining';
        $this->save($state);

        return ['phase' => 'draining', 'ready_after' => $state['ready_after']];
    }

    private function copyBatch(array $state): array
    {
        $this->markers($state);
        if ($state['phase'] === 'draining') {
            if (time() < $state['ready_after']) {
                throw new RuntimeException('Active requests must finish before taking the runtime snapshot.');
            }
            $state['files'][] = $this->entry('private', '.env');
            foreach (['private' => $this->legacy.'/storage', 'public' => $this->public.'/media/uploads'] as $area => $root) {
                $this->noLinks($root);
                if (! is_dir($root)) {
                    if ($area === 'private') {
                        throw new RuntimeException('Original application storage is missing.');
                    }

                    continue;
                }
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink()) {
                        throw new RuntimeException('Runtime symlinks require a separate migration.');
                    }
                    if (! $file->isFile()) {
                        continue;
                    }
                    $relative = ($area === 'private' ? 'storage/' : 'media/uploads/').substr($file->getPathname(), strlen($root) + 1);
                    if (str_starts_with($relative, 'storage/app/private/git-layout-migration/')
                        || $relative === 'storage/framework/maintenance.php') {
                        continue;
                    }
                    $state['files'][] = $this->entry($area, $relative);
                }
            }
            $state['phase'] = 'copying';
            $this->save($state);
        }
        if ($state['phase'] !== 'copying') {
            throw new RuntimeException('The migration is not in its copy phase.');
        }
        $started = microtime(true);
        $limit = min(count($state['files']), $state['copied'] + 50);
        while ($state['copied'] < $limit) {
            $entry = $state['files'][$state['copied']];
            $source = $this->path($entry['area'] === 'private' ? $this->legacy : $this->public, $entry['path']);
            $backup = $this->path($this->directory.'/backup/'.$state['token'].'/'.$entry['area'], $entry['path']);
            $this->copy($source, $backup, $entry['hash']);
            if ($entry['area'] === 'private') {
                $this->copy($source, $this->path($this->target, $entry['path']), $entry['hash'], $state['previous'][$entry['path']] ?? null);
            }
            $state['copied']++;
            $this->save($state);
            if (microtime(true) - $started >= 8) {
                break;
            }
        }
        if ($state['copied'] === count($state['files'])) {
            foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
                $this->mkdir($this->target.'/storage/'.$folder);
            }
            $state['phase'] = 'copied';
            $this->save($state);
        }

        return ['phase' => $state['phase'], 'copied' => $state['copied'], 'files' => count($state['files'])];
    }

    private function verify(array $state, bool $cutover = false): array
    {
        $this->markers($state);
        if (! in_array($state['phase'], ['copied', 'verifying', 'ready', 'cutover_verifying'], true)) {
            throw new RuntimeException('Finish copying before verifying the migration.');
        }
        $phase = $cutover ? 'cutover_verifying' : 'verifying';
        if ($state['phase'] !== $phase) {
            $state['verified'] = 0;
            $state['phase'] = $phase;
            $this->save($state);
        }
        $started = microtime(true);
        $limit = min(count($state['files']), $state['verified'] + 50);
        while ($state['verified'] < $limit) {
            $entry = $state['files'][$state['verified']];
            $roots = [$entry['area'] === 'private' ? $this->legacy : $this->public,
                $this->directory.'/backup/'.$state['token'].'/'.$entry['area']];
            if ($entry['area'] === 'private') {
                $roots[] = $this->target;
            }
            foreach ($roots as $root) {
                if ($this->hash($this->path($root, $entry['path'])) !== $entry['hash']) {
                    throw new RuntimeException('Runtime or backup verification failed; keep maintenance active.');
                }
            }
            $state['verified']++;
            $this->save($state);
            if (microtime(true) - $started >= 8) {
                break;
            }
        }
        if ($state['verified'] === count($state['files'])) {
            $state['phase'] = $cutover ? 'cutover_ready' : 'ready';
        }
        $this->save($state);

        return ['phase' => $state['phase'], 'verified_files' => $state['verified'],
            'backups_verified' => $state['verified'] === count($state['files'])];
    }

    private function finish(array $state): array
    {
        if (! str_contains($this->read($this->public.'/index.php'), "__DIR__.'/yesenterprise-app/storage/framework/maintenance.php'")) {
            throw new RuntimeException('Deploy the production entry point before completing cutover.');
        }
        if (! in_array($state['phase'], ['ready', 'cutover_verifying', 'cutover_ready', 'finishing', 'finished'], true)) {
            throw new RuntimeException('Verify the runtime transfer before production cutover.');
        }
        if (in_array($state['phase'], ['ready', 'cutover_verifying'], true)) {
            $result = $this->verify($state, true);
            if ($result['phase'] !== 'cutover_ready') {
                return $result;
            }
            $state = json_decode($this->read($this->directory.'/state.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (['config.php', 'routes-v7.php'] as $cache) {
            $path = $this->target.'/bootstrap/cache/'.$cache;
            $this->noLinks($path);
            if (is_link($path) || (is_file($path) && ! unlink($path))) {
                throw new RuntimeException('Could not remove the stale server configuration cache.');
            }
        }
        $state['phase'] = 'finishing';
        $this->save($state);
        $this->removeMarkers($state);
        $state['phase'] = 'finished';
        $this->save($state);
        $this->write($this->target.'/storage/app/private/git-layout-migration-complete.json', '{"complete":true}');

        return ['phase' => 'finished', 'existing_key_and_storage_preserved' => true];
    }

    private function cancel(array $state): array
    {
        if ($state['phase'] === 'finished') {
            throw new RuntimeException('Completed migrations require a data-aware rollback.');
        }
        $index = $this->read($this->public.'/index.php');
        if (! str_contains($index, 'yesenterprise-app/storage/framework/maintenance.php')) {
            throw new RuntimeException('The live entry point changed outside this migration.');
        }
        $this->write($this->public.'/index.php', $this->read($this->directory.'/index-before.php'));
        $this->removeMarkers($state);
        $state['phase'] = 'cancelled';
        $this->save($state);

        return ['phase' => 'cancelled', 'original_application_restored' => true];
    }

    private function entry(string $area, string $relative): array
    {
        return ['area' => $area, 'path' => $relative,
            'hash' => $this->hash($this->path($area === 'private' ? $this->legacy : $this->public, $relative))];
    }

    private function path(string $root, string $relative): string
    {
        if ($relative !== '.env' && ! str_starts_with($relative, 'storage/') && ! str_starts_with($relative, 'media/uploads/')) {
            throw new RuntimeException('A migration path is outside the supported runtime areas.');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\') || str_contains($part, ':')) {
                throw new RuntimeException('Unsafe migration path.');
            }
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('A migration destination or source contains a symlink.');
            }
        }

        return $path;
    }

    private function markers(array $state): void
    {
        foreach ([$this->legacy, $this->target] as $root) {
            if ($this->read($root.'/storage/framework/maintenance.php') !== $state['marker']) {
                throw new RuntimeException('Maintenance ownership changed; refusing to continue.');
            }
        }
    }

    private function removeMarkers(array $state): void
    {
        foreach ([$this->legacy, $this->target] as $root) {
            $path = $root.'/storage/framework/maintenance.php';
            $this->noLinks($path);
            if (is_file($path) && $this->read($path) !== $state['marker']) {
                throw new RuntimeException('Maintenance ownership changed; refusing to remove it.');
            }
        }
        foreach ([$this->legacy, $this->target] as $root) {
            $path = $root.'/storage/framework/maintenance.php';
            if (is_link($path) || (is_file($path) && ! unlink($path))) {
                throw new RuntimeException('Could not remove the migration maintenance marker.');
            }
        }
    }

    private function state(?array $state): array
    {
        if ($state === null) {
            throw new RuntimeException('Start the migration before requesting this operation.');
        }

        return $state;
    }

    private function save(array $state): void
    {
        $this->write($this->directory.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function mkdir(string $path): void
    {
        $this->noLinks($path);
        if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true))) {
            throw new RuntimeException('Could not create a safe migration directory.');
        }
    }

    private function read(string $path): string
    {
        $this->noLinks($path);
        if (is_link($path) || ! is_file($path) || ($content = file_get_contents($path)) === false) {
            throw new RuntimeException('A required migration file is missing or unreadable.');
        }

        return $content;
    }

    private function hash(string $path): string
    {
        $this->noLinks($path);
        if (is_link($path) || ! is_file($path) || ($hash = hash_file('sha256', $path)) === false) {
            throw new RuntimeException('Could not verify a migration file.');
        }

        return $hash;
    }

    private function copy(string $source, string $target, string $hash, ?string $previous = null): void
    {
        if ($this->hash($source) !== $hash) {
            throw new RuntimeException('Source runtime changed after the maintenance snapshot.');
        }
        $this->mkdir(dirname($target));
        if (is_file($target) && ! in_array($this->hash($target), [$hash, $previous], true)) {
            throw new RuntimeException('Existing destination runtime differs; refusing to overwrite it.');
        }
        $temporary = dirname($target).'/.deploy-'.bin2hex(random_bytes(8));
        if (! copy($source, $temporary) || ! chmod($temporary, 0600)
            || $this->hash($temporary) !== $hash || ! rename($temporary, $target)) {
            throw new RuntimeException('A runtime backup or copy failed verification.');
        }
    }

    private function write(string $path, string $content): void
    {
        $this->noLinks($path);
        $this->mkdir(dirname($path));
        $temporary = dirname($path).'/.deploy-'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
            || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not persist the migration checkpoint.');
        }
    }

    private function noLinks(string $path): void
    {
        while ($path !== dirname($path)) {
            if (is_link($path)) {
                throw new RuntimeException('Migration paths must not contain symlinks.');
            }
            $path = dirname($path);
        }
    }
}
