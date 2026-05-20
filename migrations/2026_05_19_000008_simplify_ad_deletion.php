<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Drop the problematic foreign key constraint entirely
        $schema->table('ad_clicks', function (Blueprint $table) {
            // Drop the foreign key column entirely to avoid constraint issues
            $table->dropColumn('ad_id');
        });
        
        // Add a simple integer column without foreign key constraint
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->unsignedInteger('ad_id')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        // Revert the changes
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->dropColumn('ad_id');
        });
        
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->unsignedInteger('ad_id')->nullable();
        });
    },
];