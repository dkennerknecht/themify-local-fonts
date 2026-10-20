/* Route the direct Google stylesheet requests used by Themify's editors locally. */
(() => {
    'use strict';
    const config = window.themifyLocalFonts;
    if (!config || window.themifyLocalFontsInstalled) return;
    window.themifyLocalFontsInstalled = true;

    function localUrl(value) {
        if (typeof value !== 'string' || !value.includes('fonts.googleapis.com')) return value;
        let url;
        try { url = new URL(value.replace(/&amp;/g, '&'), document.baseURI); }
        catch (_) { return value; }
        if (url.hostname !== 'fonts.googleapis.com' || !['/css', '/css2'].includes(url.pathname)) return value;
        const names = [];
        for (const families of url.searchParams.getAll('family')) {
            for (const family of families.split('|')) {
                const name = family.split(':')[0].trim();
                if (name && !names.includes(name)) names.push(name);
            }
        }
        if (names.every(name => config.families.includes(name))) return config.css;
        return config.endpoint + '&families=' + encodeURIComponent(JSON.stringify(names))
            + (config.nonce ? '&_wpnonce=' + encodeURIComponent(config.nonce) : '');
    }

    // Both property assignment (Themify.loadCss) and setAttribute (jQuery).
    const href = Object.getOwnPropertyDescriptor(HTMLLinkElement.prototype, 'href');
    if (href && href.set) {
        Object.defineProperty(HTMLLinkElement.prototype, 'href', {
            ...href,
            set(value) { href.set.call(this, localUrl(value)); }
        });
    }
    const setAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function (name, value) {
        if (this.tagName === 'LINK' && String(name).toLowerCase() === 'href') value = localUrl(value);
        return setAttribute.call(this, name, value);
    };

    // Handle stylesheet links inserted as HTML before the browser can fetch them.
    function localHtml(value) {
        if (typeof value !== 'string' || !value.includes('fonts.googleapis.com')) return value;
        return value.replace(/<link\b[^>]*>/gi, tag => tag.replace(/\bhref\s*=\s*(["'])(.*?)\1/i,
            (attribute, quote, url) => 'href=' + quote + localUrl(url) + quote));
    }
    const innerHTML = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
    if (innerHTML && innerHTML.set) {
        Object.defineProperty(Element.prototype, 'innerHTML', {
            ...innerHTML,
            set(value) { innerHTML.set.call(this, localHtml(value)); }
        });
    }
    const insertAdjacentHTML = Element.prototype.insertAdjacentHTML;
    Element.prototype.insertAdjacentHTML = function (position, html) {
        return insertAdjacentHTML.call(this, position, localHtml(html));
    };

    // Failed preparation is visible to the editor instead of silently saving a
    // selection whose font files could not be downloaded.
    if (config.nonce) {
        document.addEventListener('error', event => {
            const link = event.target;
            if (!(link instanceof HTMLLinkElement) || !link.href.includes('action=tlf_local_fonts_css')) return;
            if (document.getElementById('tlf-font-error')) return;
            const notice = document.createElement('div');
            notice.id = 'tlf-font-error';
            notice.setAttribute('role', 'alert');
            notice.style.cssText = 'position:fixed;bottom:20px;left:20px;right:20px;z-index:2147483647;background:#fff4f4;color:#7d1010;border:2px solid #b32d2e;padding:16px;font:16px/1.5 sans-serif';
            notice.textContent = 'Die ausgewählte Schrift konnte nicht lokal vorbereitet werden. Bitte die Schrift erneut auswählen oder die Seite erneut speichern. Details stehen unter WordPress → Dashboard.';
            (document.body || document.documentElement).appendChild(notice);
        }, true);
    }
})();
