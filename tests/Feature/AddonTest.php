<?php

declare(strict_types=1);

use Statamic\Facades\Addon;

it('registers itself with Statamic', function () {
    $addon = Addon::get('bpmore/statamic-lifecycle');

    expect($addon)->not->toBeNull()
        // The repository is named for the platform; the product is not. The
        // slug is what names the config file, the view namespace and the
        // commands, so it has to be the product's name.
        ->and($addon->slug())->toBe('lifecycle');

    // The display name is deliberately not asserted here. Testbench builds a
    // cut-down manifest for the addon under test — id, slug, version,
    // namespace, autoload, provider and nothing else — so extra.statamic.name
    // is only read on a real install.
});

it('ships a Vite manifest where the control panel looks for it', function () {
    // Statamic's AddonServiceProvider::registerVite publishes
    // <publicDirectory>/<buildDirectory>/ and points Laravel's Vite at
    // vendor/<package>/<buildDirectory>. Laravel then reads
    // <buildDirectory>/manifest.json. Vite 6 writes .vite/manifest.json unless
    // told otherwise, and the mismatch throws "Vite manifest not found" on
    // every control panel page — not just this addon's.
    $build = repoPath('resources/dist/build');

    expect(is_file($build.'/manifest.json'))
        ->toBeTrue('no manifest.json in resources/dist/build — run `npm run build`');

    $manifest = json_decode((string) file_get_contents($build.'/manifest.json'), true);

    expect($manifest)->toBeArray()->not->toBeEmpty()
        ->and($manifest)->toHaveKey('resources/js/cp.js');

    foreach ($manifest as $entry) {
        if (isset($entry['file'])) {
            expect(is_file($build.'/'.$entry['file']))
                ->toBeTrue("manifest names {$entry['file']}, which is not in the published build");
        }
    }
});
