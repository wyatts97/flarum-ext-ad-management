<?php

namespace Ralkage\AdManagement\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Serializer;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Ralkage\AdManagement\Model\Ad;
use Ralkage\AdManagement\Model\AdZone;
use Ralkage\AdManagement\Service\AdService;
use Ralkage\AdManagement\Service\ImageService;

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
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['zone', 'owner'])
                ->query(function ($query, \Tobyz\JsonApiServer\Context $context) {
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
                })
                ->paginate(20, 200),
            Endpoint\Create::make()
                ->authenticated()
                ->visible(function (Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || $actor->hasPermission('ralkage-ad-management.submitAd');
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
                ->admin(),
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
                    $serializer = new Serializer($context);

                    foreach ($ads as $ad) {
                        $resource = $context->collection->resource($this->type());
                        $serializer->addPrimary($resource, $ad, ['zone' => []]);
                    }

                    [$primary, $included] = $serializer->serialize();

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
            Schema\Str::make('imageUrl')
                ->nullable()
                ->writable(),
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
            $model->max_image_changes = (int) $this->settings->get('ralkage-ad-management.default_max_image_changes', 5);
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
        $body = $context->body();
        $data = $body['data']['attributes'] ?? [];

        // Handle pending image action (admin only)
        if ($isAdmin && isset($data['pending_image_action'])) {
            $action = $data['pending_image_action'];
            if ($action === 'approve' && $model->pending_image_url) {
                if ($model->image_url) {
                    $this->imageService->deleteCompressedImage($model->image_url);
                }
                $model->image_url = $model->pending_image_url;
                $model->pending_image_url = null;
            } elseif ($action === 'reject') {
                if ($model->pending_image_url) {
                    $this->imageService->deleteCompressedImage($model->pending_image_url);
                }
                $model->pending_image_url = null;
            }
        }

        // Handle image URL changes with processing
        if (isset($data['image_url']) && $data['image_url'] !== $model->getOriginal('image_url')) {
            $newImageUrl = $data['image_url'];
            if ($newImageUrl && $model->type === 'image') {
                $this->imageService->validateImageUrl($newImageUrl);
                $zone = $model->zone_id ? AdZone::find($model->zone_id) : null;
                $processedUrl = $this->imageService->processImage(
                    $newImageUrl,
                    $zone ? $zone->max_width : null,
                    $zone ? $zone->max_height : null
                );

                if (!$isAdmin && (bool) $this->settings->get('ralkage-ad-management.require_image_approval', false)) {
                    if ($model->pending_image_url) {
                        $this->imageService->deleteCompressedImage($model->pending_image_url);
                    }
                    $model->pending_image_url = $processedUrl;
                    $model->image_changes_count++;
                } else {
                    if ($model->image_url) {
                        $this->imageService->deleteCompressedImage($model->image_url);
                    }
                    $model->image_url = $processedUrl;
                    $model->image_changes_count++;
                }
            } else {
                $model->image_url = $newImageUrl;
            }
        }

        // Validate link URL
        if (isset($data['link_url'])) {
            $this->validateLinkUrl($data['link_url']);
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

        // Check image change limit for non-admins
        $body = $context->body();
        $data = $body['data']['attributes'] ?? [];
        if (!$isAdmin && isset($data['image_url']) && $data['image_url'] !== $model->getOriginal('image_url')) {
            if ($model->max_image_changes !== null && $model->image_changes_count >= $model->max_image_changes) {
                throw new ValidationException([
                    'image_url' => 'You have reached the maximum number of image changes for this ad.',
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
