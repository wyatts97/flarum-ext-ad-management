<?php

namespace Ralkage\AdManagement;

use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Ralkage\AdManagement\Api\Controller;
use Ralkage\AdManagement\Api\Resource\AdResource;
use Ralkage\AdManagement\Api\Resource\AdZoneResource;
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

    // Admin extender for Flarum 2.x - register custom page and permissions
    (new Extend\Admin)
        ->route('/ralkage-ad-management', 'ralkage-ad-management')
        ->permission('ralkage-ad-management.submitAd', function ($permission) {
            $permission
                ->type('reply')
                ->label('ralkage-ad-management.admin.permissions.submit_ad')
                ->defaultGroup('member');
        })
        ->permission('ralkage-ad-management.noAds', function ($permission) {
            $permission
                ->type('view')
                ->label('ralkage-ad-management.admin.permissions.no_ads')
                ->defaultGroup(false);
        }),

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
        ->default('ralkage-ad-management.between_posts_interval', 5)
        ->default('ralkage-ad-management.show_sponsored_label', true)
        ->default('ralkage-ad-management.sponsored_label_text', '')
        ->default('ralkage-ad-management.default_max_image_changes', 5)
        ->default('ralkage-ad-management.track_impressions', true)
        ->default('ralkage-ad-management.track_clicks', true)
        ->default('ralkage-ad-management.hide_ads_for_groups', '') // deprecated, kept for backwards compat
        ->default('ralkage-ad-management.adsense_publisher_id', '')
        ->default('ralkage-ad-management.allowed_image_formats', 'jpg,jpeg,png,webp,gif')
        ->default('ralkage-ad-management.enable_compression', false)
        ->default('ralkage-ad-management.compression_quality', 85)
        ->default('ralkage-ad-management.compression_method', 'gd')
        ->default('ralkage-ad-management.require_image_approval', false)
        ->default('ralkage-ad-management.expiration_reminder_days', 7)
        ->default('ralkage-ad-management.send_performance_reports', false)
        ->default('ralkage-ad-management.expiration_subject_template', '')
        ->default('ralkage-ad-management.expiration_body_template', '')
        ->default('ralkage-ad-management.performance_subject_template', '')
        ->default('ralkage-ad-management.performance_body_template', '')
        ->serializeToForum('adsBetweenPostsInterval', 'ralkage-ad-management.between_posts_interval', 'intval')
        ->serializeToForum('adsTrackImpressions', 'ralkage-ad-management.track_impressions', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('adsTrackClicks', 'ralkage-ad-management.track_clicks', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('adsShowSponsoredLabel', 'ralkage-ad-management.show_sponsored_label', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('adsSponsoredLabelText', 'ralkage-ad-management.sponsored_label_text'),

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
                        return ! $actor->isGuest() && ($actor->isAdmin() || $actor->hasPermission('ralkage-ad-management.submitAd'));
                    }),
                Schema\Boolean::make('adsHidden')
                    ->get(function ($forum, $context) {
                        $actor = $context->getActor();
                        return ! $actor->isGuest() && $actor->hasPermission('ralkage-ad-management.noAds');
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
