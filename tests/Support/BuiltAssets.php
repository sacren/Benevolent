<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Foundation\Vite;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Makes a browser test read the *built* front end rather than a dev server.
 *
 * This is the mechanism behind D-12, and it exists because of the blocker that
 * ended the first attempt at browser coverage. While anyone is running Vite,
 * `public/hot` exists and Laravel points every script tag at that dev server --
 * `http://laravel.local:17042/resources/js/app.ts` and friends. The browser
 * driving these tests runs inside the container and cannot reach that address,
 * so Vue never mounts, `<div id="app">` comes back empty, and a test asserting
 * "the page rendered" is red for infrastructure and green for nothing.
 *
 * **The escape is to change where Laravel looks for the hot file, not to move
 * the file.** `Vite::useHotFile()` sets the path, and `isRunningHot()` is no
 * more than `is_file()` on it, so pointing it at a path that cannot exist puts
 * Vite into manifest mode for this test alone. Nothing on disk is touched: the
 * developer's dev server keeps running, `public/hot` is neither deleted nor
 * restored, and there is no window in which a crashed test leaves the
 * application pointing somewhere wrong.
 *
 * **The rejected alternative is the one worth naming.** Skipping browser tests
 * whenever `hot` is present was the obvious answer and is the bad one: the
 * guard would fall silent exactly while someone is changing the front end,
 * which is the only time it has anything to say. That is a tripwire's inverse
 * and it trains people to ignore a skip.
 *
 * So this class never skips. If the built assets are missing or stale it fails,
 * loudly, naming the command that fixes it.
 */
final class BuiltAssets
{
    /**
     * The directories whose contents Vite bundles.
     *
     * Blade is deliberately absent: `resources/views` is rendered by PHP at
     * request time and is never part of a build, so including it would age the
     * manifest against a file that cannot make it stale.
     */
    private const array SOURCE_DIRECTORIES = ['resources/js', 'resources/css'];

    /**
     * Point Vite at the build output, and refuse to run if it is not usable.
     */
    public static function serveFromBuild(): void
    {
        self::assertManifestExists();
        self::assertManifestIsNotStale();

        // Any path that cannot be a file will do; this one is inside the
        // framework's own testing directory and names itself, so anybody who
        // finds it in a stack trace learns why it is there.
        app(Vite::class)->useHotFile(
            storage_path('framework/testing/vite-hot-disabled-for-browser-tests'),
        );
    }

    /**
     * The manifest is what Vite writes at the end of a build, so its absence
     * means no build has ever run in this checkout.
     */
    private static function assertManifestExists(): void
    {
        if (is_file(self::manifestPath())) {
            return;
        }

        Assert::fail(implode(PHP_EOL, [
            'Browser tests need built front-end assets, and there are none.',
            '',
            'Expected: '.self::manifestPath(),
            '',
            'Build them with:  ./vendor/bin/sail npm run build',
            '',
            'These tests deliberately do not skip. A browser test that quietly',
            'opts out when the front end is unbuilt is a guard that goes silent',
            'exactly when the front end is being changed.',
        ]));
    }

    /**
     * A build old enough to predate the sources it was made from is worse than
     * no build at all, because the page still renders and the test still
     * reports on something -- just not on the code in the working tree.
     *
     * Two distinct failures collapse into this one check, which is why it is
     * worth the directory walk. A page added since the last build is absent
     * from the manifest, and Laravel raises a ViteException deep inside the
     * test's own HTTP server, where it surfaces as a 500 the browser renders
     * and the assertion reports as "could not see the heading" -- true, and
     * useless. A page merely *edited* since the last build is worse still: it
     * is present in the manifest, nothing raises anything, and the test passes
     * or fails against JavaScript nobody is running any more.
     */
    private static function assertManifestIsNotStale(): void
    {
        $manifestModified = (int) filemtime(self::manifestPath());

        $newest = self::newestSourceFile();

        if (! $newest instanceof SplFileInfo || $newest->getMTime() <= $manifestModified) {
            return;
        }

        Assert::fail(implode(PHP_EOL, [
            'The built front-end assets are older than the source they were built from,',
            'so these tests would be reporting on JavaScript that is no longer in the tree.',
            '',
            'Newest source: '.$newest->getPathname(),
            '               '.date('Y-m-d H:i:s', $newest->getMTime()),
            'Built assets:  '.self::manifestPath(),
            '               '.date('Y-m-d H:i:s', $manifestModified),
            '',
            'Rebuild with:  ./vendor/bin/sail npm run build',
        ]));
    }

    /**
     * The most recently modified file Vite would have bundled, or null if there
     * are somehow no sources at all.
     */
    private static function newestSourceFile(): ?SplFileInfo
    {
        $newest = null;

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            $path = base_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if (! $newest instanceof SplFileInfo || $file->getMTime() > $newest->getMTime()) {
                    $newest = clone $file;
                }
            }
        }

        return $newest;
    }

    private static function manifestPath(): string
    {
        return public_path('build/manifest.json');
    }
}
