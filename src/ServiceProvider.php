<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => ['resources/js/cp.js'],
        'publicDirectory' => 'resources/dist',
    ];

    // The product is "Lifecycle" everywhere a person sees it — views, config,
    // commands — never the repository name.
    protected $viewNamespace = 'lifecycle';

    public function bootAddon(): void
    {
        //
    }
}
