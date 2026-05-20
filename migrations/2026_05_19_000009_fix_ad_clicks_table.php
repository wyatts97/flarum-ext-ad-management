<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return [
    'up' => function (Builder $schema) {
        // Fix ad_clicks table structure if it was corrupted by the bad migration
        $connection = $schema->getConnection();
        
        // Check if ad_clicks table exists
        if (!$schema->hasTable('ad_clicks')) {
            return;
        }
        
        // Check if ad_id column exists using Schema facade
        if (!Schema::hasColumn('ad_clicks', 'ad_id')) {
            // Add the ad_id column if it was dropped by the bad migration
            $schema->table('ad_clicks', function (Blueprint $table) {
                $table->unsignedInteger('ad_id')->nullable()->after('id');
                $table->index('ad_id');
            });
        }
    },

    'down' => function (Builder $schema) {
        // No down migration needed
    },
];
