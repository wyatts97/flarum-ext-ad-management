import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import AdManagementPage from './components/AdManagementPage';
import AdminPage from 'flarum/admin/components/AdminPage';

app.initializers.add('ralkage-ad-management', () => {
    // Register custom admin page
    extend(AdminPage.prototype, 'navItems', (items) => {
        items.add('ralkage-ad-management',
            <button className="Button Button--user Button--flat" icon="fas fa-ad" onclick={() => m.route(app.route('ralkage-ad-management'))}>
                {app.translator.trans('ralkage-ad-management.admin.nav.title')}
            </button>,
            100
        );
    });

    // Register permissions via Admin extender is handled in extend.php
    // The admin page is registered as a route below
    app.routes['ralkage-ad-management'] = { path: '/ralkage-ad-management', component: AdManagementPage };
});
