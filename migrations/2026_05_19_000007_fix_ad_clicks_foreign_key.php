<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // First, drop the existing foreign key constraint
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->dropForeign(['ad_id']);
        });
        
        // Then add the new foreign key constraint with set null
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->foreign('ad_id')->references('id')->on('advertisements')->onDelete('set null');
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