<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $directory = sys_get_temp_dir().'/yesenterprise-test-views-'.getmypid();
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $_ENV['VIEW_COMPILED_PATH'] = $directory;
        $_SERVER['VIEW_COMPILED_PATH'] = $directory;

        return parent::createApplication();
    }
}
