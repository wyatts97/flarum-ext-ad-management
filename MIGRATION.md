# Flarum 2.0.0-rc.1 Migration Summary

## What Changed

### 1. composer.json
- Updated `flarum/core` requirement from `^1.8` to `^2.0.0-rc.1`

### 2. Models (Laravel 11 `$dates` removal)
- `src/Model/Ad.php`: Moved `created_at`, `updated_at`, `start_date`, `end_date`, `last_notified_at` from `$dates` to `$casts` as `datetime`
- `src/Model/AdZone.php`: Moved `created_at`, `updated_at` from `$dates` to `$casts` as `datetime`
- `src/Model/AdClick.php`: Moved `created_at` from `$dates` to `$casts` as `datetime`

### 3. New ApiResource Classes (replaces old Controllers + Serializers)
- `src/Api/Resource/AdResource.php` — Full CRUD + custom `/active` and `/{id}/analytics` endpoints
- `src/Api/Resource/AdZoneResource.php` — Full CRUD for ad zones

**Endpoints migrated:**
| Old Route | Old Class | New Pattern |
|---|---|---|
| `GET /advertisements` | `ListAdsController` | `AdResource` Index endpoint |
| `POST /advertisements` | `CreateAdController` | `AdResource` Create endpoint |
| `PATCH /advertisements/{id}` | `UpdateAdController` | `AdResource` Update endpoint |
| `DELETE /advertisements/{id}` | `DeleteAdController` | `AdResource` Delete endpoint |
| `GET /advertisements/active` | `ListActiveAdsController` | `AdResource` custom `active` endpoint |
| `GET /advertisements/{id}/analytics` | `AdAnalyticsController` | `AdResource` custom `analytics` endpoint |
| `GET /ad-zones` | `ListAdZonesController` | `AdZoneResource` Index endpoint |
| `POST /ad-zones` | `CreateAdZoneController` | `AdZoneResource` Create endpoint |
| `PATCH /ad-zones/{id}` | `UpdateAdZoneController` | `AdZoneResource` Update endpoint |
| `DELETE /ad-zones/{id}` | `DeleteAdZoneController` | `AdZoneResource` Delete endpoint |

### 4. extend.php
- Removed `Extend\Routes('api')` for standard CRUD endpoints
- Removed `Extend\ApiSerializer(ForumSerializer::class)`
- Added `Extend\ApiResource(AdResource::class)` and `Extend\ApiResource(AdZoneResource::class)`
- Added `Extend\ApiResource(ForumResource::class)` to inject `canManageAds`, `canViewOwnAds`, `canSubmitAds`, `adsHidden`
- Kept `Extend\Routes('api')` only for tracking endpoints (`/ad-track/click`, `/ad-track/impression`)

### 5. Console Commands (Laravel 11 `fire()` → `handle()`)
- `src/Command/PurgeAdClicksCommand.php`: Renamed `fire()` → `handle()`
- `src/Command/SendAdNotificationsCommand.php`: Renamed `fire()` → `handle()`

### 6. Tracking Controllers (PSR-7 response library update)
- Replaced `Laminas\Diactoros\Response\JsonResponse` / `EmptyResponse` with `Nyholm\Psr7\Response` in both tracking controllers for Flarum 2.0 compatibility

### 7. JS Build Tooling
- `js/package.json`: Bumped `flarum-webpack-config` `^2.0.0` → `^3.0.0`
- `js/package.json`: Bumped `flarum-tsconfig` `^1.0.2` → `^2.0.0`

### 8. Frontend Breaking Changes (Flarum 2.0)
- **Admin** (`js/src/admin/index.js`): Removed deprecated `app.extensionData.for()` - replaced with Admin extender in `extend.php`
- **Forum** (`js/src/forum/index.js`): Changed `extend(IndexPage.prototype, 'sidebarItems')` → `extend(IndexSidebar.prototype, 'items')` (sidebar moved to IndexSidebar in Flarum 2.0)
- **extend.php**: Added `Extend\Admin` extender to register custom page and permissions

### 9. Carbon 3 Compatibility
- **File**: `src/Command/SendAdNotificationsCommand.php`
- **Change**: `diffInDays()` now returns floats instead of integers
- **Fix**: Added `round()` to handle float values: `(int) round($now->diffInDays($ad->end_date, false))`

## Files to Delete (Dead Code)
These files are no longer referenced and extend removed Flarum 1.x classes. Delete them before deploying:

```
src/Api/Controller/ListAdsController.php
src/Api/Controller/CreateAdController.php
src/Api/Controller/UpdateAdController.php
src/Api/Controller/DeleteAdController.php
src/Api/Controller/ListActiveAdsController.php
src/Api/Controller/AdAnalyticsController.php
src/Api/Controller/ListAdZonesController.php
src/Api/Controller/CreateAdZoneController.php
src/Api/Controller/UpdateAdZoneController.php
src/Api/Controller/DeleteAdZoneController.php
src/Api/Serializer/AdSerializer.php
src/Api/Serializer/AdZoneSerializer.php
```

## Deployment Steps

1. **Fork / push the updated code** to your GitHub repository.
2. **Delete the dead-code files** listed above.
3. **Rebuild frontend assets** on a machine with Node.js:
   ```bash
   cd js
   npm install
   npm run build
   ```
4. **On your Flarum server**, update the extension via Composer:
   ```bash
   composer update ralkage/flarum-ext-ad-management
   ```
5. **Run Flarum migrations** (in case any schema changes are needed in the future):
   ```bash
   php flarum migrate
   ```
6. **Clear caches**:
   ```bash
   php flarum cache:clear
   ```
7. **Enable the extension** in Admin → Extensions if it was disabled.
8. **Test**:
   - Open the forum frontend and verify ads load without 500/400 errors
   - Open Admin → Ad Management and verify zones/ads CRUD works
   - Check browser Network tab for any API errors on `/api/advertisements` or `/api/ad-zones`

## Known Considerations
- If you see frontend JS runtime errors after deployment, the frontend may need import-path adjustments for Flarum 2.0's new module system. Share the exact console errors and I can patch them.
- The old `serializeToForum` settings extender was kept in `extend.php`; in Flarum 2.0 it should still work by auto-injecting into `ForumResource`. If settings like `adsBetweenPostsInterval` are missing from the forum payload, let me know.
