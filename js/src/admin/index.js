import app from 'flarum/admin/app';

app.initializers.add('ralkage-ad-management', () => {
    // Page registration and permissions are handled by the Admin extender in extend.js
});

export { default as extend } from './extend';
