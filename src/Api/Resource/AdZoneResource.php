<?php

namespace Ralkage\AdManagement\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Ralkage\AdManagement\Model\AdZone;

/**
 * @extends AbstractDatabaseResource<AdZone>
 */
class AdZoneResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'ad-zones';
    }

    public function model(): string
    {
        return AdZone::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $query->orderBy('sort_order');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultSort('sortOrder')
                ->paginate(50, 100),
            Endpoint\Create::make()
                ->authenticated()
                ->admin()
                ->defaultInclude(['advertisements']),
            Endpoint\Update::make()
                ->authenticated()
                ->admin()
                ->defaultInclude(['advertisements']),
            Endpoint\Delete::make()
                ->authenticated()
                ->admin(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->regex('/^[a-z0-9_]+$/')
                ->maxLength(50)
                ->unique('ad_zones', 'name', true)
                ->set(function (AdZone $zone, string $value, Context $context) {
                    // Don't allow changing name on default zones
                    if ($context->updating() && $zone->is_default) {
                        return;
                    }
                    $zone->name = $value;
                }),
            Schema\Str::make('label')
                ->requiredOnCreate()
                ->maxLength(100),
            Schema\Str::make('description')
                ->nullable(),
            Schema\Str::make('position')
                ->requiredOnCreate()
                ->in(['header', 'below_header', 'between_posts', 'sidebar', 'above_footer', 'footer', 'custom']),
            Schema\Boolean::make('isDefault'),
            Schema\Boolean::make('isActive')
                ->writable(),
            Schema\Integer::make('sortOrder')
                ->writable(),
            Schema\Integer::make('maxWidth')
                ->nullable()
                ->writable(),
            Schema\Integer::make('maxHeight')
                ->nullable()
                ->writable(),
            Schema\Str::make('displayMode')
                ->in(['rotate', 'stack'])
                ->writable(),
            Schema\Integer::make('adsCount')
                ->get(function (AdZone $zone) {
                    return (int) ($zone->advertisements_count ?? $zone->advertisements()->count());
                }),
            Schema\DateTime::make('createdAt'),
            Schema\Relationship\ToMany::make('advertisements')
                ->includable()
                ->inverse('zone')
                ->type('advertisements'),
        ];
    }

    public function creating(object $model, Context $context): ?object
    {
        $model->is_default = false;
        if (empty($model->display_mode)) {
            $model->display_mode = 'rotate';
        }
        return $model;
    }

    public function deleting(object $model, Context $context): void
    {
        if ($model->is_default) {
            throw new ValidationException([
                'zone' => 'Default zones cannot be deleted.',
            ]);
        }
    }
}
