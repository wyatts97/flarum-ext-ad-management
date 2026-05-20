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
        
        // Check if ad_id column exists by querying the database
        try {
            $columnExists = $connection->select(
                "SELECT COUNT(*) as count FROM information_schema.columns 
                 WHERE table_schema = DATABASE() 
                 AND table_name = 'ad_clicks' 
                 AND column_name = 'ad_id'"
            );
            
            if (empty($columnExists) || $columnExists[0]->count == 0) {
                // Add the ad_id column if it was dropped by the bad migration
                $schema->table('ad_clicks', function (Blueprint $table) {
                    $table->unsignedInteger('ad_id')->nullable()->after('id');
                    $table->index('ad_id');
                });
            }
        } catch (\Exception $e) {
            // If checking fails, try to add the column anyway (it will fail if it exists)
            try {
                $schema->table('ad_clicks', function (Blueprint $table) {
                    $table->unsignedInteger('ad_id')->nullable()->after('id');
                    $table->index('ad_id');
                });
            } catch (\Exception $e2) {
                // Column probably already exists, ignore error
            }
        }
    },

    'down' => function (Builder $schema) {
        // No down migration needed
    },
];
