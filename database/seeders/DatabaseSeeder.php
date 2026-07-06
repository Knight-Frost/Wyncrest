<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder — the single entry point for `db:seed` / `migrate:fresh --seed`.
 *
 * It resolves the seed MODE and dispatches to exactly one of:
 *   - DevelopmentSeeder : rich, realistic demo graph (local/demo only)
 *   - ProductionSeeder  : safe baseline (reference data + optional env admin)
 *
 * Mode resolution:
 *   1. config('seed.mode')  (WYNCREST_SEED_MODE env, NEXUS_SEED_MODE legacy), if set
 *   2. else: production APP_ENV => 'production', otherwise 'development'
 *
 * Switch modes without touching code:
 *   php artisan migrate:fresh --seed                            # auto (dev locally)
 *   WYNCREST_SEED_MODE=development php artisan migrate:fresh --seed
 *   WYNCREST_SEED_MODE=production  php artisan db:seed           # safe baseline
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $mode = self::resolveMode();

        $this->command?->getOutput()->writeln(
            "<info>Seeding in <comment>{$mode}</comment> mode</info> (set WYNCREST_SEED_MODE to override)."
        );

        match ($mode) {
            'production' => $this->call(ProductionSeeder::class),
            default => $this->call(DevelopmentSeeder::class),
        };
    }

    /**
     * Resolve the active seed mode: explicit config wins, else infer from env.
     */
    public static function resolveMode(): string
    {
        $configured = config('seed.mode');

        if (is_string($configured) && $configured !== '') {
            $normalized = strtolower($configured);

            if (! in_array($normalized, ['development', 'production'], true)) {
                throw new \RuntimeException(
                    "Invalid seed mode \"{$configured}\": expected \"development\" or \"production\"."
                );
            }

            return $normalized;
        }

        return app()->environment('production') ? 'production' : 'development';
    }
}
