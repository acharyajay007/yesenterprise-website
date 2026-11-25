<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentTest extends TestCase
{
    public function test_robots_is_routed_before_the_existing_static_file_can_bypass_launch_controls(): void
    {
        $rules = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('RewriteRule ^robots\.txt$ index.php [L]', $rules);
        $this->assertLessThan(strpos($rules, 'RewriteCond %{REQUEST_FILENAME} !-f'), strpos($rules, 'RewriteRule ^robots\.txt$'));
        config(['website.indexable' => false]);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        config(['website.indexable' => true, 'app.url' => 'https://example.invalid']);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: ');
    }

    public function test_mysql_certificate_verification_is_enabled_for_remote_deployment(): void
    {
        $source = file_get_contents(config_path('database.php'));
        $this->assertSame(2, substr_count($source, "Mysql::ATTR_SSL_VERIFY_SERVER_CERT => env('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', true)"));
        $this->assertStringContainsString('<FilesMatch "^\.deploy-">', file_get_contents(public_path('.htaccess')));
    }
}
