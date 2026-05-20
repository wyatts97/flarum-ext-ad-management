<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Fix ad_clicks table structure if it was corrupted by the bad migration
        $connection = $schema->getConnection();
        
        // Check if ad_clicks table exists
        if (!$schema->hasTable('ad_clicks')) {
            return;
        }
        
        // Get table columns
        $columns = $connection->getDoctrineSchemaManager()->listTableColumns('ad_clicks');
        $columnNames = array_keys($columns);
        
        // Check if ad_id column exists
        if (!in_array('ad_id', $columnNames)) {
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
