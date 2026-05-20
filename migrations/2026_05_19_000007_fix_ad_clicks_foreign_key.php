<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Change the foreign key constraint for ad_clicks to set null instead of cascade
        // This prevents 500 errors when deleting ads that have associated clicks
        $schema->table('ad_clicks', function (Blueprint $table) {
            $table->dropForeign(['ad_id']);
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