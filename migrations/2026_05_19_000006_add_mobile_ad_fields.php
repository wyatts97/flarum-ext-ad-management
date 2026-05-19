<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('advertisements', function (Blueprint $table) {
            $table->text('mobile_content')->nullable()->after('content');
            $table->string('mobile_image_url', 500)->nullable()->after('image_url');
            $table->string('mobile_link_url', 500)->nullable()->after('link_url');
            $table->string('mobile_alt_text', 255)->nullable()->after('alt_text');
            $table->unsignedInteger('mobile_width')->nullable()->after('width');
            $table->unsignedInteger('mobile_height')->nullable()->after('height');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('advertisements', function (Blueprint $table) {
            $table->dropColumn([
                'mobile_content',
                'mobile_image_url',
                'mobile_link_url',
                'mobile_alt_text',
                'mobile_width',
                'mobile_height',
            ]);
        });
    },
];
