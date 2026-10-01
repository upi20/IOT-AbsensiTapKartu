<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Request API di tes tidak boleh tercatat ke log sungguhan di storage/logs.
     * Tanpa ini log lokal tercampur ratusan request palsu.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.api_log.path' => storage_path('framework/testing/logs/api.log')]);
        app('log')->forgetChannel('api_log');
    }
}
