<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Health\AppleHealthImporter;
use Illuminate\Console\Command;

/**
 * Import an Apple Health export from the CLI — handy for testing the importer without
 * going through the upload form, and for re-importing a saved export.
 *
 *   php artisan apple-health:import --fixture            # use the bundled sample XML
 *   php artisan apple-health:import /path/to/export.zip  # a real export
 *   php artisan apple-health:import /path/to/export.xml  # an already-unzipped export
 *   php artisan apple-health:import --fixture --profile=1
 *
 * With no --profile it targets the first profile in the DB.
 */
class ImportAppleHealth extends Command
{
    protected $signature = 'apple-health:import
        {path? : path to an export.zip or export.xml (omit with --fixture)}
        {--fixture : import the bundled sample at database/fixtures/apple_health_sample.xml}
        {--profile= : profile id to import into (defaults to the first profile)}';

    protected $description = 'Import an Apple Health export (zip/xml) into Titan for a profile.';

    public function handle(AppleHealthImporter $importer): int
    {
        $profile = $this->option('profile')
            ? Profile::find($this->option('profile'))
            : Profile::query()->orderBy('id')->first();

        if (! $profile) {
            $this->error('No profile found. Pass --profile or seed one first.');

            return self::FAILURE;
        }

        $path = $this->option('fixture')
            ? database_path('fixtures/apple_health_sample.xml')
            : (string) $this->argument('path');

        if ($path === '' || ! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Importing {$path} into profile #{$profile->id}…");

        $summary = str_ends_with(strtolower($path), '.xml')
            ? $importer->importXml($profile, $path)
            : $importer->importZip($profile, $path);

        if ($summary['error']) {
            $this->error($summary['error']);

            return self::FAILURE;
        }

        $this->table(
            ['recovery', 'sleep', 'body', 'workouts', 'samples', 'from', 'to'],
            [[
                $summary['recovery'], $summary['sleep'], $summary['body'], $summary['workouts'],
                $summary['samples'], $summary['date_from'] ?? '—', $summary['date_to'] ?? '—',
            ]],
        );

        $this->info('Done.');

        return self::SUCCESS;
    }
}
