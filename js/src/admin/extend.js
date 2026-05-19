import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import AdManagementPage from './components/AdManagementPage';

export default [
    new Extend.Admin()
        .page(AdManagementPage)
        .permission(
            () => ({
                icon: 'fas fa-ad',
                label: app.translator.trans('wyatts97-ad-management.admin.permissions.submit_ad'),
                permission: 'wyatts97-ad-management.submitAd',
            }),
            'reply',
            90
        )
        .permission(
            () => ({
                icon: 'fas fa-eye-slash',
                label: app.translator.trans('wyatts97-ad-management.admin.permissions.no_ads'),
                permission: 'wyatts97-ad-management.noAds',
            }),
            'view',
            89
        ),
];
