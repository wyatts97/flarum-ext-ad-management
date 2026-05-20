<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Drop the foreign key constraint if it exists
        $schema->table('ad_clicks', function (Blueprint $table) {
            try {
                $table->dropForeign(['ad_id']);
            } catch (\Exception $e) {
                // Ignore if the foreign key doesn't exist
            }
        });
        
        // Add the new foreign key constraint with cascade deletion
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->foreign('ad_id')->references('id')->on('advertisements')->onDelete('cascade');
        });
    },

    'down' => function (Builder $schema) {
        // Revert the change back to cascade if needed
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->dropForeign(['ad_id']);
            $table->foreign('ad_id')->references('id')->on('advertisements')->onDelete('cascade');
        });
    },
];