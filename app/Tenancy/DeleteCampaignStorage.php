<?php

declare(strict_types=1);

namespace App\Tenancy;

use FilesystemIterator;
use Illuminate\Contracts\Foundation\Application;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Removes a deleted campaign's files along with its database.
 *
 * The filesystem bootstrapper gives each campaign its own storage tree, so a
 * campaign that has ever had a list imported into it owns a directory holding
 * the uploaded file -- names, addresses and postcodes, in the clear, on disk.
 * Deleting the campaign already deletes its database (Stancl's DeleteDatabase,
 * in the same pipeline as this), and until this existed it deleted nothing
 * else: the campaign's people would survive the campaign indefinitely, in a
 * directory named after a campaign that no longer exists and which nothing
 * would ever look at again.
 *
 * That is worth stating as a data-protection fact rather than as tidiness. The
 * campaign's own database is the thing everyone thinks of as holding supporter
 * data; the uploaded file holds the same people and is not a database at all,
 * so it is invisible to every instinct about where personal data lives.
 *
 * Deliberately paired with DeleteDatabase in the pipeline rather than made a
 * separate listener, so the two halves of "the campaign is gone" cannot be
 * wired independently and one of them quietly not be.
 */
final class DeleteCampaignStorage
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * The application is injected rather than reached through the helper so
     * that the *central* storage path is the one this reads. The path is
     * derived the way the bootstrapper derives it -- suffix plus campaign key
     * -- rather than typed as a literal, so changing the suffix moves this with
     * it instead of leaving every campaign's files behind in silence.
     */
    public function handle(Application $application): void
    {
        $directory = sprintf(
            '%s/%s%s',
            rtrim($application->storagePath(), '/'),
            (string) config('tenancy.filesystem.suffix_base'),
            $this->tenant->getTenantKey(),
        );

        $this->remove($directory);
    }

    /**
     * A campaign that never stored a file has no directory, which is the
     * ordinary case rather than a failure.
     */
    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
