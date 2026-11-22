<?php

namespace Daun\StatamicTreeSync\Tests;

use Daun\StatamicTreeSync\ServiceProvider;
use Statamic\Facades\Site;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.system.multisite' => true, 'statamic.editions.pro' => true]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'url' => '/', 'locale' => 'de_AT'],
            'en' => ['name' => 'English', 'url' => '/en/', 'locale' => 'en_GB'],
            'fr' => ['name' => 'Français', 'url' => '/fr/', 'locale' => 'fr_FR'],
        ]);
    }
}
