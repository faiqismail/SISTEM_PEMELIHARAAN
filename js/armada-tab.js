/**
 * Multi-tab: pastikan armada_tab ada di URL dan di semua link/form internal.
 * SessionStorage dipakai per-tab, jadi tiap tab punya tab_id sendiri.
 */
(function () {
    'use strict';

    function getTabFromUrl() {
        var m = /[?&]armada_tab=([^&]+)/.exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : null;
    }

    function getTabId() {
        var fromUrl = getTabFromUrl();
        if (fromUrl) {
            try { sessionStorage.setItem('armada_tab_id', fromUrl); } catch (e) {}
            return fromUrl;
        }
        try {
            return sessionStorage.getItem('armada_tab_id');
        } catch (e) {
            return null;
        }
    }

    function addParam(url, param, value) {
        if (!url || !param || value == null) return url;
        var base = url.split('#')[0];
        var hash = url.indexOf('#') >= 0 ? url.substring(url.indexOf('#')) : '';
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        return base + sep + encodeURIComponent(param) + '=' + encodeURIComponent(value) + hash;
    }

    function hasParam(url, param) {
        if (!url) return false;
        var q = url.split('?')[1];
        if (!q) return false;
        return new RegExp('(^|&)' + encodeURIComponent(param) + '=').test('&' + q);
    }

    function isSameOrigin(href) {
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;
        if (href.startsWith('/') || href.indexOf('://') === -1) return true;
        try {
            var a = document.createElement('a');
            a.href = href;
            return a.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function run() {
        var tabId = getTabId();
        if (!tabId) return;

        var param = 'armada_tab';

        document.querySelectorAll('a[href]').forEach(function (a) {
            var href = a.getAttribute('href');
            if (isSameOrigin(href) && !hasParam(href, param)) {
                a.setAttribute('href', addParam(href, param, tabId));
            }
        });

        document.querySelectorAll('form[action]').forEach(function (f) {
            var action = f.getAttribute('action');
            if (action && isSameOrigin(action) && !hasParam(action, param)) {
                f.setAttribute('action', addParam(action, param, tabId));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
