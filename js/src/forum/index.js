import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import CommentPost from 'flarum/forum/components/CommentPost';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import AdBanner from './components/AdBanner';
import AdWidget from './components/AdWidget';
import MyAdsPage from './components/MyAdsPage';

let adsCache = null;
let adsCacheTime = 0;
let adsLoading = false;
let adsError = false;
let zonePositions = {};
let zoneNames = {};
let zoneDisplayModes = {};
// One randomly selected ad per position/zone, rotated on each page navigation
let selectedAdByPosition = {};
let selectedAdByZoneName = {};
let lastRoute = null;
const CACHE_TTL = 60000;

function loadAds() {
    if (adsLoading || adsError) return;
    const now = Date.now();
    if (adsCache && (now - adsCacheTime) < CACHE_TTL) return;

    adsLoading = true;

    app.request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/advertisements/active',
        errorHandler: (error) => {
            console.error('[AdManagement] Failed to load active ads:', error);
        },
    }).then(response => {
        adsCache = response.data || [];
        adsCacheTime = Date.now();
        adsLoading = false;
        adsError = false;

        // Build zone maps from included resources
        zonePositions = {};
        zoneNames = {};
        zoneDisplayModes = {};
        if (response.included) {
            response.included.forEach(item => {
                if (item.type === 'ad-zones') {
                    zonePositions[item.id] = item.attributes.position;
                    zoneNames[item.id] = item.attributes.name;
                    zoneDisplayModes[item.id] = item.attributes.displayMode || 'rotate';
                }
            });
        }

        // Fallback: if included zones are missing, try to look them up from the store
        if (Object.keys(zonePositions).length === 0 && adsCache.length > 0) {
            const zones = app.store.all('ad-zones');
            if (zones && zones.length > 0) {
                zones.forEach(zone => {
                    zonePositions[zone.id()] = zone.attribute('position');
                    zoneNames[zone.id()] = zone.attribute('name');
                    zoneDisplayModes[zone.id()] = zone.attribute('displayMode') || 'rotate';
                });
                console.log('[AdManagement] Used store fallback for zone mapping');
            } else {
                console.warn('[AdManagement] No zone mapping available — ads will not display');
            }
        }

        console.log('[AdManagement] Loaded', adsCache.length, 'active ads');
        console.log('[AdManagement] zonePositions:', zonePositions);
        console.log('[AdManagement] zoneNames:', zoneNames);
        console.log('[AdManagement] adsCache sample:', adsCache[0]);

        // Pick one random ad per position and per zone name for this page load
        selectAdsForRotation();

        console.log('[AdManagement] selectedAdByPosition:', selectedAdByPosition);
        console.log('[AdManagement] selectedAdByZoneName:', selectedAdByZoneName);

        m.redraw();
    }).catch(error => {
        adsLoading = false;
        adsError = true;
        console.error('[AdManagement] Error loading ads:', error);
        // Retry after 30 seconds
        setTimeout(() => { adsError = false; loadAds(); }, 30000);
    });
}

/**
 * Re-select ads when the user navigates to a new page.
 * Flarum is an SPA so hard refreshes are rare; this ensures ads rotate
 * on every route change instead of staying fixed for the whole session.
 */
function rotateAdsOnNavigation() {
    if (!adsCache) return;
    const route = m.route.get();
    if (route !== lastRoute) {
        lastRoute = route;
        selectAdsForRotation();
    }
}

/**
 * For each position and zone name, randomly select one ad to display.
 */
function selectAdsForRotation() {
    selectedAdByPosition = {};
    selectedAdByZoneName = {};

    if (!adsCache) return;

    const adsByPosition = {};
    const adsByZoneName = {};

    adsCache.forEach(ad => {
        const zoneRel = ad.relationships?.zone?.data;
        if (!zoneRel) return;

        const pos = zonePositions[zoneRel.id];
        const name = zoneNames[zoneRel.id];

        if (pos) {
            if (!adsByPosition[pos]) adsByPosition[pos] = [];
            adsByPosition[pos].push(ad);
        }
        if (name) {
            if (!adsByZoneName[name]) adsByZoneName[name] = [];
            adsByZoneName[name].push(ad);
        }
    });

    Object.entries(adsByPosition).forEach(([pos, ads]) => {
        selectedAdByPosition[pos] = ads[Math.floor(Math.random() * ads.length)];
    });

    Object.entries(adsByZoneName).forEach(([name, ads]) => {
        selectedAdByZoneName[name] = ads[Math.floor(Math.random() * ads.length)];
    });
}

function getDisplayModeForPosition(position) {
    for (const [id, pos] of Object.entries(zonePositions)) {
        if (pos === position) return zoneDisplayModes[id] || 'rotate';
    }
    return 'rotate';
}

function getDisplayModeForZoneName(zoneName) {
    for (const [id, name] of Object.entries(zoneNames)) {
        if (name === zoneName) return zoneDisplayModes[id] || 'rotate';
    }
    return 'rotate';
}

function getAdsByPosition(position) {
    if (!adsCache) return [];

    return adsCache.filter(ad => {
        const zoneRel = ad.relationships?.zone?.data;
        if (!zoneRel) return false;
        return zonePositions[zoneRel.id] === position;
    });
}

function getAdsByZoneName(zoneName) {
    if (!adsCache) return [];
    return adsCache.filter(ad => {
        const zoneRel = ad.relationships?.zone?.data;
        if (!zoneRel) return false;
        return zoneNames[zoneRel.id] === zoneName;
    });
}

function mountAdPlaceholders(element) {
    if (!adsCache || !element) return;

    element.querySelectorAll('.AdZonePlaceholder[data-zone]').forEach(placeholder => {
        const zoneName = placeholder.getAttribute('data-zone');
        if (!zoneName) return;

        if (getDisplayModeForZoneName(zoneName) === 'stack') {
            const ads = getAdsByZoneName(zoneName);
            if (ads.length > 0) {
                m.render(placeholder, ads.map(ad => m(AdBanner, { key: ad.id, ad })));
            }
        } else {
            const ad = selectedAdByZoneName[zoneName];
            if (ad) {
                m.render(placeholder, m(AdBanner, { key: ad.id, ad }));
            }
        }
    });
}

function shouldHideAds() {
    return !!app.forum.attribute('adsHidden');
}

function renderZoneAds(position, className) {
    const mode = getDisplayModeForPosition(position);
    console.log('[AdManagement] renderZoneAds called for', position, 'mode:', mode);

    if (mode === 'stack') {
        const ads = getAdsByPosition(position);
        console.log('[AdManagement] stack ads for', position, ':', ads.length);
        if (ads.length === 0) return null;
        return (
            <div className={'AdZone ' + className}>
                <div className="container">
                    {ads.map(ad => <AdBanner key={ad.id} ad={ad} />)}
                </div>
            </div>
        );
    }

    // Default: rotate — show one randomly selected ad
    const ad = selectedAdByPosition[position];
    console.log('[AdManagement] rotate ad for', position, ':', ad ? ad.id : 'none');
    if (!ad) return null;
    return (
        <div className={'AdZone ' + className}>
            <div className="container">
                <AdBanner ad={ad} />
            </div>
        </div>
    );
}

app.initializers.add('wyatts97-ad-management', () => {
    app.routes['user.ads'] = { path: '/u/:username/ads', component: MyAdsPage };

    // Inject header ad via DOM since there's no good Mithril hook above the header.
    // Re-renders on every call so the ad rotates on each page navigation.
    function injectHeaderAd() {
        console.log('[AdManagement] injectHeaderAd called, adsCache:', !!adsCache, 'hide:', shouldHideAds());
        if (shouldHideAds() || !adsCache) return;

        const isStack = getDisplayModeForPosition('header') === 'stack';
        const ads = isStack ? getAdsByPosition('header') : [];
        const singleAd = !isStack ? selectedAdByPosition['header'] : null;

        console.log('[AdManagement] header isStack:', isStack, 'ads:', ads.length, 'singleAd:', singleAd ? singleAd.id : 'none');

        if (!isStack && !singleAd) return;
        if (isStack && ads.length === 0) return;

        const appHeader = document.getElementById('header');
        console.log('[AdManagement] #header found:', !!appHeader);
        if (!appHeader) return;

        let container = document.querySelector('.AdZone--header');
        if (!container) {
            container = document.createElement('div');
            container.className = 'AdZone AdZone--header';
            const inner = document.createElement('div');
            inner.className = 'container';
            container.appendChild(inner);
            appHeader.parentNode.insertBefore(container, appHeader);
            console.log('[AdManagement] Created header ad container');
        }

        const inner = container.querySelector('.container');
        if (isStack) {
            m.render(inner, ads.map(ad => m(AdBanner, { key: ad.id, ad })));
        } else {
            m.render(inner, m(AdBanner, { key: singleAd.id, ad: singleAd }));
        }
        console.log('[AdManagement] Rendered header ad');
    }

    // Add "My Ads" link to user page nav
    extend(UserPage.prototype, 'navItems', function (items) {
        if (app.session.user && app.session.user === this.user) {
            items.add('ads',
                <LinkButton href={app.route('user.ads', { username: this.user.slug() })} icon="fas fa-ad">
                    {app.translator.trans('wyatts97-ad-management.forum.nav.my_ads')}
                </LinkButton>,
                10
            );
        }
    });

    // Index page: below_header, above_footer, footer zones
    extend(IndexPage.prototype, 'view', function (vdom) {
        console.log('[AdManagement] IndexPage.view extend called');
        loadAds();
        rotateAdsOnNavigation();
        if (shouldHideAds() || !adsCache || !vdom || !vdom.children) {
            console.log('[AdManagement] IndexPage.view early return — hide:', shouldHideAds(), 'cache:', !!adsCache, 'vdom:', !!vdom, 'children:', !!(vdom && vdom.children));
            return;
        }

        injectHeaderAd();

        // Below header - insert at position 0 (above hero)
        const belowHeader = renderZoneAds('below_header', 'AdZone--below-header');
        if (belowHeader) {
            const heroIdx = vdom.children.findIndex(c =>
                c && c.attrs && c.attrs.className && typeof c.attrs.className === 'string' && c.attrs.className.includes('Hero')
            );
            vdom.children.splice((heroIdx >= 0 ? heroIdx + 1 : 0), 0, belowHeader);
            console.log('[AdManagement] Injected below_header ad into IndexPage');
        }

        // Above footer
        const aboveFooter = renderZoneAds('above_footer', 'AdZone--above-footer');
        if (aboveFooter) {
            vdom.children.push(aboveFooter);
            console.log('[AdManagement] Injected above_footer ad into IndexPage');
        }

        // Footer
        const footer = renderZoneAds('footer', 'AdZone--footer');
        if (footer) {
            vdom.children.push(footer);
            console.log('[AdManagement] Injected footer ad into IndexPage');
        }
    });

    // Sidebar zone - moved to IndexSidebar in Flarum 2.x
    extend(IndexSidebar.prototype, 'items', function (items) {
        loadAds();
        if (shouldHideAds() || !adsCache) return;

        if (getDisplayModeForPosition('sidebar') === 'stack') {
            const ads = getAdsByPosition('sidebar');
            if (ads.length > 0) {
                items.add('adWidget', <AdWidget ads={ads} />, -100);
            }
        } else {
            const ad = selectedAdByPosition['sidebar'];
            if (ad) {
                items.add('adWidget', <AdWidget ads={[ad]} />, -100);
            }
        }
    });

    // Shortcode placeholders: mount ads into {myadvertisements[zone_name]} divs in post content
    extend(CommentPost.prototype, 'oncreate', function () {
        mountAdPlaceholders(this.element);
    });

    extend(CommentPost.prototype, 'onupdate', function () {
        mountAdPlaceholders(this.element);
    });

    // Between posts zone: cycles through ads using post position
    extend(CommentPost.prototype, 'view', function (vdom) {
        loadAds();
        if (shouldHideAds() || !adsCache) return;

        const interval = app.forum.attribute('adsBetweenPostsInterval') || 5;
        if (interval <= 0) return;

        const ads = getAdsByPosition('between_posts');
        if (ads.length === 0) return;

        const post = this.attrs.post;
        if (!post) return;

        const number = post.number();
        if (number > 1 && (number - 1) % interval === 0) {
            // Use proper modulo to cycle through available ads (handles any number of ads)
            const n = Math.floor((number - 1) / interval) - 1;
            const adIndex = ((n % ads.length) + ads.length) % ads.length;
            const ad = ads[adIndex];
            if (ad && vdom && vdom.children) {
                vdom.children.push(
                    <div className="AdZone AdZone--between-posts">
                        <AdBanner ad={ad} />
                    </div>
                );
            }
        }
    });

    // Discussion page: below_header and footer zones
    extend(DiscussionPage.prototype, 'view', function (vdom) {
        console.log('[AdManagement] DiscussionPage.view extend called');
        loadAds();
        rotateAdsOnNavigation();
        if (shouldHideAds() || !adsCache || !vdom || !vdom.children) {
            console.log('[AdManagement] DiscussionPage.view early return — hide:', shouldHideAds(), 'cache:', !!adsCache, 'vdom:', !!vdom, 'children:', !!(vdom && vdom.children));
            return;
        }

        injectHeaderAd();

        const belowHeader = renderZoneAds('below_header', 'AdZone--below-header');
        if (belowHeader) {
            vdom.children.unshift(belowHeader);
            console.log('[AdManagement] Injected below_header ad into DiscussionPage');
        }
    });
});
