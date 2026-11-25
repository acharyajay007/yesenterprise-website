<?php

namespace Tests\Feature;

use App\Support\GitLayoutMigration;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class GitLayoutMigrationTest extends TestCase
{
    private string $root;

    private string $public;

    private string $legacy;

    private string $key;

    private string $timeout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/yes-git-'.bin2hex(random_bytes(8));
        $this->public = $this->root.'/public_html';
        $this->legacy = $this->root.'/yesenterprise-app';
        $this->key = 'base64:'.base64_encode(str_repeat('x', 32));
        $this->timeout = ini_get('max_execution_time');
        ini_set('max_execution_time', '1');
        $this->writeFixture($this->legacy.'/.env', 'APP_KEY='.$this->key."\nAPP_DEBUG=false\n");
        $this->writeFixture($this->legacy.'/storage/app/private/resume.pdf', 'private resume fixture');
        $this->writeFixture($this->legacy.'/storage/framework/sessions/session', 'private session fixture');
        $this->writeFixture($this->public.'/yesenterprise-app/.htaccess', "Require all denied\n");
        $this->writeFixture($this->public.'/.htaccess', file_get_contents(public_path('.htaccess')));
        $this->writeFixture($this->public.'/index.php', '<?php // ../yesenterprise-app/storage/framework/maintenance.php');
        $this->writeFixture($this->public.'/media/uploads/owner.webp', 'owner photo fixture');
    }

    protected function tearDown(): void
    {
        ini_set('max_execution_time', $this->timeout);
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_invalid_authorization_cannot_create_checkpoints_or_maintenance(): void
    {
        try {
            (new GitLayoutMigration($this->public))->dispatch('{"action":"begin"}', (string) time(), str_repeat('a', 32), 'wrong');
            $this->fail('Invalid authorization was accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Invalid migration authorization.', $error->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->stateDirectory());
        $this->assertFileDoesNotExist($this->legacy.'/storage/framework/maintenance.php');
    }

    public function test_valid_authorization_is_one_time_and_status_reveals_no_environment(): void
    {
        $body = '{"action":"status"}';
        $time = (string) time();
        $nonce = str_repeat('a', 32);
        $signature = $this->signature($body, $time, $nonce);
        $migration = new GitLayoutMigration($this->public);
        $result = $migration->dispatch($body, $time, $nonce, $signature);
        $this->assertSame('not_started', $result['phase']);
        $this->assertStringNotContainsString($this->key, json_encode($result));
        $this->expectExceptionMessage('Migration authorization has already been used.');
        $migration->dispatch($body, $time, $nonce, $signature);
    }

    public function test_expired_authorization_is_rejected(): void
    {
        $this->expectExceptionMessage('Invalid or expired migration authorization.');
        (new GitLayoutMigration($this->public))->dispatch('{"action":"status"}', (string) (time() - 301), str_repeat('b', 32), 'wrong');
    }

    public function test_staging_cannot_migrate_the_real_application(): void
    {
        $stage = $this->public.'/yesenterprise-stage';
        $this->writeFixture($stage.'/yesenterprise-app/.htaccess', "Require all denied\n");
        $this->writeFixture($stage.'/.htaccess', file_get_contents(public_path('.htaccess')));
        $body = '{"action":"begin"}';
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $this->expectExceptionMessage('Staging permits only nonsecret retention probes; live migration actions are refused.');
        (new GitLayoutMigration($stage))->dispatch($body, $time, $nonce, $this->signature($body, $time, $nonce));
    }

    public function test_staging_probes_are_created_without_copying_production_secrets(): void
    {
        $stage = $this->public.'/yesenterprise-stage';
        $this->writeFixture($stage.'/yesenterprise-app/.htaccess', "Require all denied\n");
        $this->writeFixture($stage.'/.htaccess', file_get_contents(public_path('.htaccess')));
        foreach (['stage-init', 'stage-check'] as $action) {
            $body = json_encode(['action' => $action]);
            $time = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $result = (new GitLayoutMigration($stage))->dispatch($body, $time, $nonce, $this->signature($body, $time, $nonce));
            $this->assertTrue($result['environment_storage_uploads_unchanged']);
        }
        $this->assertStringNotContainsString($this->key, file_get_contents($stage.'/yesenterprise-app/.env'));
        $this->assertSame('GIT_ONLY_RETENTION_PROBE', file_get_contents($stage.'/media/uploads/git-layout-retention-test.txt'));
        $this->assertFileDoesNotExist($this->legacy.'/storage/framework/maintenance.php');
    }

    public function test_existing_maintenance_is_not_overwritten(): void
    {
        $this->writeFixture($this->legacy.'/storage/framework/maintenance.php', 'another owner');
        $this->expectExceptionMessage('An existing maintenance state must not be overwritten.');
        $this->operation('begin');
    }

    public function test_copy_waits_for_active_requests(): void
    {
        $this->operation('begin');
        $this->expectExceptionMessage('Active requests must finish before taking the runtime snapshot.');
        $this->operation('copy');
    }

    public function test_runtime_backups_and_cutover_preserve_key_sessions_resumes_and_uploads(): void
    {
        $original = file_get_contents($this->legacy.'/.env');
        $this->prepare();
        $this->assertSame($original, file_get_contents($this->public.'/yesenterprise-app/.env'));
        $this->assertSame('private resume fixture', file_get_contents($this->public.'/yesenterprise-app/storage/app/private/resume.pdf'));
        $this->assertSame('private session fixture', file_get_contents($this->public.'/yesenterprise-app/storage/framework/sessions/session'));
        $state = json_decode(file_get_contents($this->stateDirectory().'/state.json'), true);
        $backup = $this->stateDirectory().'/backup/'.$state['token'];
        $this->assertSame($original, file_get_contents($backup.'/private/.env'));
        $this->assertSame('owner photo fixture', file_get_contents($backup.'/public/media/uploads/owner.webp'));
        $this->writeFixture($this->public.'/index.php', "<?php // __DIR__.'/yesenterprise-app/storage/framework/maintenance.php'");
        $this->writeFixture($this->public.'/yesenterprise-app/bootstrap/cache/config.php', '<?php // stale');
        $this->writeFixture($this->public.'/yesenterprise-app/bootstrap/cache/packages.php', '<?php // provider');
        $this->assertSame('finished', $this->operation('finish')['phase']);
        $this->assertFileDoesNotExist($this->public.'/yesenterprise-app/bootstrap/cache/config.php');
        $this->assertFileExists($this->public.'/yesenterprise-app/bootstrap/cache/packages.php');
        $this->assertFileExists($this->public.'/yesenterprise-app/storage/app/private/git-layout-migration-complete.json');
        $this->assertFileDoesNotExist($this->legacy.'/storage/framework/maintenance.php');
        $this->assertFileDoesNotExist($this->public.'/yesenterprise-app/storage/framework/maintenance.php');
        $this->assertSame($original, file_get_contents($this->legacy.'/.env'));
    }

    public function test_cutover_reverifies_runtime_after_git_deployment(): void
    {
        $this->prepare();
        $this->writeFixture($this->public.'/index.php', "<?php // __DIR__.'/yesenterprise-app/storage/framework/maintenance.php'");
        $this->writeFixture($this->public.'/yesenterprise-app/storage/app/private/resume.pdf', 'changed');
        $this->expectExceptionMessage('Runtime or backup verification failed; keep maintenance active.');
        $this->operation('finish');
    }

    public function test_cancel_restores_the_legacy_entry_point_and_retains_backups(): void
    {
        $this->prepare();
        $this->writeFixture($this->public.'/index.php', "<?php // __DIR__.'/yesenterprise-app/storage/framework/maintenance.php'");
        $this->assertSame('cancelled', $this->operation('cancel')['phase']);
        $this->assertStringContainsString('../yesenterprise-app/', file_get_contents($this->public.'/index.php'));
        $this->assertDirectoryExists($this->stateDirectory().'/backup');
        $this->assertFileDoesNotExist($this->legacy.'/storage/framework/maintenance.php');
        $this->assertSame('draining', $this->operation('begin')['phase']);
    }

    public function test_runtime_symlinks_are_rejected_without_following_them(): void
    {
        $this->operation('begin');
        $this->drain();
        $this->writeFixture($this->root.'/external.txt', 'must remain untouched');
        symlink($this->root.'/external.txt', $this->legacy.'/storage/app/private/link');
        $this->expectExceptionMessage('Runtime symlinks require a separate migration.');
        $this->operation('copy');
    }

    public function test_copy_and_verification_are_batched_and_resumable(): void
    {
        for ($i = 0; $i < 110; $i++) {
            $this->writeFixture($this->legacy.'/storage/framework/views/'.$i.'.php', 'view fixture '.$i);
        }
        $this->operation('begin');
        $this->drain();
        $first = $this->operation('copy');
        $this->assertSame('copying', $first['phase']);
        $this->assertSame(50, $first['copied']);
        while (($result = $this->operation('copy'))['phase'] === 'copying') {
            $this->assertGreaterThan($first['copied'], $result['copied']);
            $first = $result;
        }
        $this->assertSame('copied', $result['phase']);
        $this->assertSame('verifying', $this->operation('verify')['phase']);
        while (($result = $this->operation('verify'))['phase'] === 'verifying') {
            $this->assertLessThan($first['files'], $result['verified_files']);
        }
        $this->assertSame('ready', $result['phase']);
    }

    private function prepare(): void
    {
        $this->operation('begin');
        $this->drain();
        $this->assertSame('copied', $this->operation('copy')['phase']);
        $this->assertSame('ready', $this->operation('verify')['phase']);
    }

    private function drain(): void
    {
        $file = $this->stateDirectory().'/state.json';
        $state = json_decode(file_get_contents($file), true);
        $state['ready_after'] = time() - 1;
        file_put_contents($file, json_encode($state));
    }

    private function operation(string $action): array
    {
        $body = json_encode(['action' => $action], JSON_THROW_ON_ERROR);
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return (new GitLayoutMigration($this->public))->dispatch($body, $time, $nonce, $this->signature($body, $time, $nonce));
    }

    private function signature(string $body, string $time, string $nonce): string
    {
        $key = hash_hmac('sha256', 'yesenterprise:git-layout:v1', $this->key, true);

        return hash_hmac('sha256', $time."\n".$nonce."\n".$body, $key);
    }

    private function stateDirectory(): string
    {
        return $this->legacy.'/storage/app/private/git-layout-migration';
    }

    private function writeFixture(string $path, string $content): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);
    }
}
