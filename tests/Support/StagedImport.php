<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\SupporterImport;
use App\Models\User;
use App\Supporters\ColumnMapping;
use App\Supporters\ImportStatus;
use App\Supporters\NameColumnMode;
use App\Supporters\SupporterFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts a file on the current campaign's disk and records an import of it.
 *
 * Both import test files need this and they must agree exactly about it, since
 * one asserts what the reading does and the other asserts which campaign it
 * does it in — and a divergence between two copies would let either be right
 * about a file the other never produces.
 *
 * Behind a class rather than a global helper for the reason Tests\Support\Url
 * records: Pest loads every test file into one process, so a function declared
 * in one is one identically named helper away from a fatal redeclaration that
 * aborts the whole run rather than failing a test.
 *
 * The disk is whichever one SupporterFile names, and in campaign context the
 * filesystem bootstrapper has already rooted it inside that campaign's own
 * storage tree — so calling this in two campaigns writes two files, which is
 * exactly what tests/Tenancy/CampaignSupporterImportTest.php relies on.
 */
final class StagedImport
{
    /**
     * @param  array<string, string|null>|null  $mapping  null leaves the import unmapped
     * @param  string|null  $path  pin the stored path, for a test asserting that one
     *                             relative path names a different file in each campaign
     */
    public static function of(
        string $contents,
        ?array $mapping = null,
        ?User $operator = null,
        ?string $path = null,
    ): SupporterImport {
        $path ??= 'imports/'.Str::random(8).'.csv';

        Storage::disk(SupporterFile::DISK)->put($path, $contents);

        return SupporterImport::query()->forceCreate([
            'operator_id' => $operator?->getKey(),
            'original_filename' => 'supporters.csv',
            'stored_path' => $path,
            'headers' => SupporterFile::headers($path),
            'mapping' => $mapping,
            'status' => $mapping === null ? ImportStatus::AwaitingMapping : ImportStatus::Pending,
        ]);
    }

    /**
     * A file whose source split the name, which most advocacy exports do.
     *
     * @return array<string, string|null>
     */
    public static function splitMapping(): array
    {
        return (new ColumnMapping(
            email: 'Email',
            nameMode: NameColumnMode::Split,
            givenName: 'First',
            familyName: 'Last',
            postcode: 'Postcode',
        ))->toArray();
    }

    /**
     * A file carrying nothing but addresses, which a petition widget produces.
     *
     * @return array<string, string|null>
     */
    public static function addressOnlyMapping(): array
    {
        return (new ColumnMapping(email: 'Email', nameMode: NameColumnMode::None))->toArray();
    }
}
