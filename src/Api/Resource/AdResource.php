<?php

namespace wyatts97\AdManagement\Api\Resource;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Serializer;
use Tobyz\JsonApiServer\Context;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use wyatts97\AdManagement\Model\Ad;
use wyatts97\AdManagement\Model\AdZone;
use wyatts97\AdManagement\Service\AdService;
use wyatts97\AdManagement\Service\ImageService;

use function Tobyz\JsonApiServer\json_api_response;

/**
 * @extends AbstractDatabaseResource<Ad>
 */
class AdResource extends AbstractDatabaseResource
{
    public function __construct(
        protected AdService $adService,
        protected ImageService $imageService,
        protected SettingsRepositoryInterface $settings,
        protected Mailer $mailer
    ) {
    }

    public function type(): string
    {
        return 'advertisements';
    }

    public function model(): string
    {
        return Ad::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $actor = $context->getActor();
        if (!$actor->isAdmin()) {
            $query->where('user_id', $actor->id);
        }

        // Apply Index-only filters and default ordering. Flarum disables
        // the json-api-server filters() method on database resources, so we
        // implement filtering here instead of via a query() callback.
        if ($context instanceof Context && $context->listing()) {
            $filters = $context->request->getQueryParams()['filter'] ?? [];

            if ($zoneId = Arr::get($filters, 'zone')) {
                $query->where('zone_id', $zoneId);
            }
            if (Arr::has($filters, 'active')) {
                $query->where('is_active', Arr::get($filters, 'active'));
            }
            if ($status = Arr::get($filters, 'status')) {
                $query->where('status', $status);
            }

            $query->orderByDesc('priority')->orderByDesc('created_at');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['zone', 'owner'])
                ->paginate(20, 200),
            Endpoint\Create::make()
                ->authenticated()
                ->visible(function (Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || $actor->hasPermission('wyatts97-ad-management.submitAd');
                })
                ->defaultInclude(['zone', 'owner']),
            Endpoint\Update::make()
                ->authenticated()
                ->visible(function (Ad $ad, Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || (int) $ad->user_id === (int) $actor->id;
                })
                ->defaultInclude(['zone', 'owner']),
            Endpoint\Delete::make()
                ->authenticated()
                ->visible(function (Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Endpoint\Endpoint::make('active')
                ->route('GET', '/active')
                ->action(function (Context $context) {
                    $actor = $context->getActor();
                    $user = $actor->isGuest() ? null : $actor;
                    $allAds = $this->adService->getAllActiveAds($user);

                    $ads = [];
                    foreach ($allAds as $zoneAds) {
                        foreach ($zoneAds as $ad) {
                            $ads[] = $ad;
                        }
                    }

                    if (!empty($ads)) {
                        $ids = array_map(fn ($ad) => $ad->id, $ads);
                        $ads = Ad::query()->whereIn('id', $ids)->with('zone')->get()->all();
                    }

                    return $ads;
                })
                ->response(function (Context $context, array $ads) {
                    $primary = [];
                    $included = [];
                    $seenZoneIds = [];

                    foreach ($ads as $ad) {
                        $item = [
                            'type' => 'advertisements',
                            'id' => (string) $ad->id,
                            'attributes' => [
                                'name' => $ad->name,
                                'type' => $ad->type,
                                'content' => $ad->content,
                                'imageUrl' => $ad->image_url,
                                'linkUrl' => $ad->link_url,
                                'altText' => $ad->alt_text,
                                'width' => $ad->width,
                                'height' => $ad->height,
                                'isActive' => (bool) $ad->is_active,
                                'status' => $ad->status ?? ($ad->is_active ? 'active' : 'inactive'),
                                'startDate' => $ad->start_date ? $ad->start_date->format(\DateTime::RFC3339) : null,
                                'endDate' => $ad->end_date ? $ad->end_date->format(\DateTime::RFC3339) : null,
                                'priority' => $ad->priority,
                                'impressionsCount' => (int) $ad->impressions_count,
                                'clicksCount' => (int) $ad->clicks_count,
                                'ctr' => $ad->impressions_count > 0 ? round(($ad->clicks_count / $ad->impressions_count) * 100, 2) : 0,
                                'maxImpressions' => $ad->max_impressions,
                                'maxClicks' => $ad->max_clicks,
                                'imageChangesCount' => (int) $ad->image_changes_count,
                                'maxImageChanges' => $ad->max_image_changes,
                                'mobileContent' => $ad->mobile_content,
                                'mobileImageUrl' => $ad->mobile_image_url,
                                'mobileLinkUrl' => $ad->mobile_link_url,
                                'mobileAltText' => $ad->mobile_alt_text,
                                'mobileWidth' => $ad->mobile_width,
                                'mobileHeight' => $ad->mobile_height,
                                'createdAt' => $ad->created_at ? $ad->created_at->format(\DateTime::RFC3339) : null,
                            ],
                        ];

                        if ($ad->zone) {
                            $item['relationships'] = [
                                'zone' => [
                                    'data' => [
                                        'type' => 'ad-zones',
                                        'id' => (string) $ad->zone->id,
                                    ],
                                ],
                            ];

                            if (!isset($seenZoneIds[(string) $ad->zone->id])) {
                                $seenZoneIds[(string) $ad->zone->id] = true;
                                $included[] = [
                                    'type' => 'ad-zones',
                                    'id' => (string) $ad->zone->id,
                                    'attributes' => [
                                        'name' => $ad->zone->name,
                                        'position' => $ad->zone->position,
                                        'displayMode' => $ad->zone->display_mode,
                                        'isDefault' => (bool) $ad->zone->is_default,
                                        'isActive' => (bool) $ad->zone->is_active,
                                        'label' => $ad->zone->label,
                                        'sortOrder' => (int) $ad->zone->sort_order,
                                    ],
                                ];
                            }
                        }

                        $primary[] = $item;
                    }

                    $document = ['data' => $primary];

                    if (count($included)) {
                        $document['included'] = $included;
                    }

                    return json_api_response($document);
                }),
            Endpoint\Endpoint::make('analytics')
                ->route('GET', '/{id}/analytics')
                ->authenticated()
                ->visible(function (Ad $ad, Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || (int) $ad->user_id === (int) $actor->id;
                })
                ->action(function (Context $context) {
                    $period = Arr::get($context->request->getQueryParams(), 'period', '30d');
                    if (!in_array($period, ['7d', '30d', '90d'], true)) {
                        $period = '30d';
                    }

                    return $this->adService->getAnalytics($context->model, $period);
                })
                ->response(function (Context $context, array $analytics) {
                    return json_api_response(['data' => $analytics]);
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->maxLength(255)
                ->writable(),
            Schema\Str::make('type')
                ->in(['image', 'html', 'adsense'])
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('content')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('zoneId')
                ->writable(),
            Schema\Str::make('imageUrl')
                ->nullable()
                ->writable(),
            // Virtual write-only action attribute used by admins to approve or
            // reject the pending image change on an ad. Not persisted.
            Schema\Str::make('pendingImageAction')
                ->writableOnUpdate()
                ->visible(fn () => false)
                ->set(function (Ad $ad, ?string $value, Context $context) {
                    if (!$value || !$context->getActor()->isAdmin()) {
                        return;
                    }
                    if ($value === 'approve' && $ad->pending_image_url) {
                        if ($ad->image_url) {
                            $this->imageService->deleteCompressedImage($ad->image_url);
                        }
                        $ad->image_url = $ad->pending_image_url;
                        $ad->pending_image_url = null;
                    } elseif ($value === 'reject') {
                        if ($ad->pending_image_url) {
                            $this->imageService->deleteCompressedImage($ad->pending_image_url);
                        }
                        $ad->pending_image_url = null;
                    }
                }),
            Schema\Str::make('linkUrl')
                ->nullable()
                ->writable(),
            Schema\Str::make('altText')
                ->nullable()
                ->writable(),
            Schema\Integer::make('width')
                ->nullable()
                ->writable(),
            Schema\Integer::make('height')
                ->nullable()
                ->writable(),
            Schema\Str::make('mobileContent')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('mobileImageUrl')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('mobileLinkUrl')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('mobileAltText')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('mobileWidth')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('mobileHeight')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Boolean::make('isActive')
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('status')
                ->get(function (Ad $ad) {
                    return $ad->status ?? ($ad->is_active ? 'active' : 'inactive');
                })
                ->set(function (Ad $ad, ?string $value, Context $context) {
                    if ($value && in_array($value, ['active', 'inactive', 'rejected'], true)) {
                        $ad->status = $value;
                        $ad->is_active = ($value === 'active');
                    }
                })
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('pendingImageUrl')
                ->nullable()
                ->visible(fn ($model, $context) => $context->getActor()->isAdmin()),
            Schema\DateTime::make('startDate')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\DateTime::make('endDate')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('priority')
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Str::make('groupVisibility')
                ->nullable()
                ->get(function (Ad $ad) {
                    return $ad->group_visibility;
                })
                ->set(function (Ad $ad, $value, Context $context) {
                    $ad->group_visibility = $value;
                })
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('impressionsCount'),
            Schema\Integer::make('clicksCount'),
            Schema\Str::make('ctr')
                ->get(function (Ad $ad) {
                    if ($ad->impressions_count > 0) {
                        return round(($ad->clicks_count / $ad->impressions_count) * 100, 2);
                    }
                    return 0;
                }),
            Schema\Integer::make('maxImpressions')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('maxClicks')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\Integer::make('imageChangesCount'),
            Schema\Integer::make('maxImageChanges')
                ->nullable()
                ->writable(function (Ad $ad, Context $context) {
                    return $context->getActor()->isAdmin();
                }),
            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('updatedAt'),
            Schema\Relationship\ToOne::make('zone')
                ->includable()
                ->inverse('advertisements')
                ->type('ad-zones'),
            Schema\Relationship\ToOne::make('owner')
                ->includable()
                ->type('users'),
        ];
    }

    public function creating(object $model, Context $context): ?object
    {
        $actor = $context->getActor();
        if (!$actor->isAdmin()) {
            $model->user_id = $actor->id;
            $model->type = 'image';
            $model->is_active = false;
            $model->status = 'pending_review';
            $model->priority = 0;
            $model->max_image_changes = (int) $this->settings->get('wyatts97-ad-management.default_max_image_changes', 5);
        } else {
            $model->user_id = $model->user_id ?? $actor->id;
            $model->status = $model->is_active ? 'active' : 'inactive';
        }

        return $model;
    }

    public function saving(object $model, Context $context): ?object
    {
        $actor = $context->getActor();
        $isAdmin = $actor->isAdmin();

        // Handle image URL changes with processing. By the time saving() runs,
        // the framework has already set $model->image_url from the request via
        // Schema\Str::make('imageUrl')'s default setter, so we detect the
        // change with isDirty() and getOriginal().
        if ($model->isDirty('image_url')) {
            $newImageUrl = $model->image_url;
            $originalImageUrl = $model->getOriginal('image_url');

            if ($newImageUrl && $model->type === 'image') {
                $this->imageService->validateImageUrl($newImageUrl);
                $zone = $model->zone_id ? AdZone::find($model->zone_id) : null;
                $processedUrl = $this->imageService->processImage(
                    $newImageUrl,
                    $zone ? $zone->max_width : null,
                    $zone ? $zone->max_height : null
                );

                if (!$isAdmin && (bool) $this->settings->get('wyatts97-ad-management.require_image_approval', false)) {
                    // Queue for approval: revert image_url and stash the
                    // processed URL in pending_image_url.
                    $model->image_url = $originalImageUrl;
                    if ($model->pending_image_url) {
                        $this->imageService->deleteCompressedImage($model->pending_image_url);
                    }
                    $model->pending_image_url = $processedUrl;
                    $model->image_changes_count++;
                } else {
                    // Apply immediately.
                    if ($originalImageUrl) {
                        $this->imageService->deleteCompressedImage($originalImageUrl);
                    }
                    $model->image_url = $processedUrl;
                    $model->image_changes_count++;
                }
            }
        }

        // Validate link URL when it has been changed.
        if ($model->isDirty('link_url')) {
            $this->validateLinkUrl($model->link_url);
        }

        return $model;
    }

    public function created(object $model, Context $context): ?object
    {
        $actor = $context->getActor();
        if (!$actor->isAdmin() && $model->status === 'pending_review') {
            $this->notifyAdmin($model, $actor->display_name);
        }

        return $model;
    }

    public function updating(object $model, Context $context): ?object
    {
        $actor = $context->getActor();
        $isAdmin = $actor->isAdmin();

        // Non-admins can only edit their own ads
        if (!$isAdmin && (int) $model->user_id !== (int) $actor->id) {
            throw new ValidationException([
                'ad' => 'You do not have permission to edit this ad.',
            ]);
        }

        // Check image change limit for non-admins. By this point the new
        // image_url has already been set by the framework, so we detect the
        // change with isDirty().
        if (!$isAdmin && $model->isDirty('image_url')) {
            if ($model->max_image_changes !== null && $model->image_changes_count >= $model->max_image_changes) {
                throw new ValidationException([
                    'imageUrl' => 'You have reached the maximum number of image changes for this ad.',
                ]);
            }
        }

        return $model;
    }

    private function validateLinkUrl(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException(['link_url' => 'Link URL must use http or https.']);
        }
    }

    private function notifyAdmin(Ad $ad, string $ownerName): void
    {
        $adminEmail = $this->settings->get('mail_from', '');
        if (!$adminEmail) {
            return;
        }

        $forumTitle = $this->settings->get('forum_title', 'Forum');
        $forumUrl = rtrim((string) $this->settings->get('url', ''), '/');
        $adName = $ad->name;

        $body = "Hello,\n\nA new advertisement \"{$adName}\" has been submitted by {$ownerName} and is awaiting review.\n\nTo review it, visit the Ad Management panel:\n{$forumUrl}/admin\n\n{$forumTitle}";
        $subject = "[{$forumTitle}] New ad pending review: \"{$adName}\"";

        try {
            $this->mailer->raw($body, function (Message $message) use ($adminEmail, $subject) {
                $message->to($adminEmail);
                $message->subject($subject);
            });
        } catch (\Exception $e) {
            // Don't fail if the notification email fails
        }
    }
}
