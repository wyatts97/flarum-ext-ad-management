<?php

namespace wyatts97\AdManagement;

use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use wyatts97\AdManagement\Api\Controller;
use wyatts97\AdManagement\Api\Resource\AdResource;
use wyatts97\AdManagement\Api\Resource\AdZoneResource;
use s9e\TextFormatter\Configurator;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/ads', 'ads'),

    new Extend\Locales(__DIR__.'/locale'),

    // Post shortcode: {myadvertisements[zone_name]} → AdZonePlaceholder div
    (new Extend\Formatter)
        ->configure(function (Configurator $configurator) {
            $tag = $configurator->tags->add('ADZONE');
            $tag->rules->autoClose();
            $tag->attributes->add('zone');
            $tag->template = '<div class="AdZonePlaceholder" data-zone="{@zone}"></div>';
            $configurator->BBCodes->add('ADZONE', ['defaultAttribute' => 'zone']);
        })
        ->parse(function ($parser, $context, string $text): string {
            return preg_replace(
                '/\{myadvertisements\[([a-z][a-z0-9_-]*)\]\}/i',
                '[adzone=$1]',
                $text
            ) ?? $text;
        }),

    (new Extend\Settings())
        ->default('wyatts97-ad-management.between_posts_interval', 5)
        ->default('wyatts97-ad-management.show_sponsored_label', true)
        ->default('wyatts97-ad-management.sponsored_label_text', '')
        ->default('wyatts97-ad-management.default_max_image_changes', 5)
        ->default('wyatts97-ad-management.track_impressions', true)
        ->default('wyatts97-ad-management.track_clicks', true)
        ->default('wyatts97-ad-management.hide_ads_for_groups', '') // deprecated, kept for backwards compat
        ->default('wyatts97-ad-management.adsense_publisher_id', '')
        ->default('wyatts97-ad-management.allowed_image_formats', 'jpg,jpeg,png,webp,gif')
        ->default('wyatts97-ad-management.enable_compression', false)
        ->default('wyatts97-ad-management.compression_quality', 85)
        ->default('wyatts97-ad-management.compression_method', 'gd')
        ->default('wyatts97-ad-management.require_image_approval', false)
        ->default('wyatts97-ad-management.expiration_reminder_days', 7)
        ->default('wyatts97-ad-management.send_performance_reports', false)
        ->default('wyatts97-ad-management.expiration_subject_template', '')
        ->default('wyatts97-ad-management.expiration_body_template', '')
        ->default('wyatts97-ad-management.performance_subject_template', '')
        ->default('wyatts97-ad-management.performance_body_template', '')
        ->serializeToForum('adsBetweenPostsInterval', 'wyatts97-ad-management.between_posts_interval', 'intval')
        ->serializeToForum('adsTrackImpressions', 'wyatts97-ad-management.track_impressions', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('adsTrackClicks', 'wyatts97-ad-management.track_clicks', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('adsShowSponsoredLabel', 'wyatts97-ad-management.show_sponsored_label', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('adsSponsoredLabelText', 'wyatts97-ad-management.sponsored_label_text'),

    // API resources for ad management (Flarum 2.0+)
    new Extend\ApiResource(AdResource::class),
    new Extend\ApiResource(AdZoneResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(function () {
            return [
                Schema\Boolean::make('canManageAds')
                    ->get(function ($forum, $context) {
                        return $context->getActor()->isAdmin();
                    }),
                Schema\Boolean::make('canViewOwnAds')
                    ->get(function ($forum, $context) {
                        return ! $context->getActor()->isGuest();
                    }),
                Schema\Boolean::make('canSubmitAds')
                    ->get(function ($forum, $context) {
                        $actor = $context->getActor();
                        return ! $actor->isGuest() && ($actor->isAdmin() || $actor->hasPermission('wyatts97-ad-management.submitAd'));
                    }),
                Schema\Boolean::make('adsHidden')
                    ->get(function ($forum, $context) {
                        $actor = $context->getActor();
                        return ! $actor->isGuest() && $actor->hasPermission('wyatts97-ad-management.noAds');
                    }),
            ];
        }),

    // Tracking endpoints (custom routes)
    (new Extend\Routes('api'))
        ->post('/ad-track/click', 'ad-track.click', Controller\TrackAdClickController::class)
        ->post('/ad-track/impression', 'ad-track.impression', Controller\TrackAdImpressionController::class),

    // Console commands
    (new Extend\Console())
        ->command(Command\SendAdNotificationsCommand::class)
        ->command(Command\PurgeAdClicksCommand::class),
];
