/**
 * ClientFlow CRM - landing page behaviour.
 *
 * Separate from app.js on purpose. index.php does not reuse the app shell or the
 * app stylesheet, and this is the same idea applied to script: nothing here is
 * needed by a signed-in page.
 *
 * Exists because the header links for Preview, How it works and FAQ have to both
 * switch a Bootstrap tab and scroll, and neither can be done in markup:
 *
 *   - CSP is `script-src 'self'` (.htaccess), so there are no inline event
 *     handlers and no inline <script>. A link with an onclick= would be blocked
 *     by the browser and flagged by tools\regression.ps1.
 *   - The panels are driven by data-bs-toggle="tab", which Bootstrap's own
 *     script activates from a click on a <button>. A header link is an <a>.
 *     Only the Tab API can move that programmatically.
 *
 * Those three links previously pointed at #preview, #workflow and #faq, none of
 * which match any id on the page, so they went nowhere. They now carry
 * data-tab-target and an href of #features, which means the link still lands on
 * the right section with JavaScript switched off - it just opens on the default
 * tab. That is strictly better than the dead anchors it replaces.
 */
(function () {
    'use strict';

    var SECTION_ID = 'features';

    /** The element a data-tab-target link should reveal. */
    function panelFor(name) {
        return document.getElementById('panel-' + name);
    }

    /** The button that drives that panel. */
    function tabButtonFor(name) {
        return document.getElementById('tab-' + name);
    }

    /**
     * Mark the header link whose tab is showing.
     *
     * aria-current is what tells a screen reader "you are here" in the nav,
     * and it is easy to leave stale once the tab pills themselves can also
     * change the tab - hence listen to Bootstrap's own event rather than only
     * updating on click here.
     */
    function markCurrentLink(tabName) {
        var links = document.querySelectorAll('[data-tab-target]');
        for (var i = 0; i < links.length; i++) {
            if (links[i].getAttribute('data-tab-target') === tabName) {
                links[i].setAttribute('aria-current', 'true');
            } else {
                links[i].removeAttribute('aria-current');
            }
        }
    }

    /**
     * Show a tab by name, optionally scrolling to the section.
     *
     * Returns false when the name does not resolve, so the caller can fall back
     * to plain anchor behaviour instead of doing nothing at all.
     */
    function activate(name, scroll) {
        var panel = panelFor(name);
        var button = tabButtonFor(name);

        if (!panel || !button || !window.bootstrap) {
            return false;
        }

        window.bootstrap.Tab.getOrCreateInstance(button).show();

        if (scroll) {
            var section = document.getElementById(SECTION_ID);
            if (section) {
                // landing.css already sets scroll-padding-top on html, so the
                // sticky header does not cover the heading we scroll to.
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        markCurrentLink(name);
        return true;
    }

    /**
     * Link clicks.
     *
     * preventDefault is not optional here. The href is #features so the page
     * still works without JavaScript, but letting the default run would push
     * #features into the address bar on every click - the hash is deliberately
     * left alone so Back and Forward do not walk through the tabs.
     */
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-tab-target]') : null;
        if (!link) {
            return;
        }

        var name = link.getAttribute('data-tab-target');
        if (!name || !panelFor(name)) {
            return;
        }

        event.preventDefault();

        if (activate(name, true)) {
            // Move focus into the content, since the link that was pressed is
            // not the thing that changed. The panels carry tabindex="0" in the
            // markup for exactly this.
            var panel = panelFor(name);
            panel.focus({ preventScroll: true });
        }
    });

    /**
     * Keep the header in step when someone uses the tab pills instead.
     *
     * Bootstrap fires this on the tab container, and it fires for programmatic
     * activation too, which is why the click handler does not have to do it.
     */
    document.addEventListener('shown.bs.tab', function (event) {
        var target = event.target.getAttribute('data-bs-target') || '';
        if (target.indexOf('#panel-') === 0) {
            markCurrentLink(target.replace('#panel-', ''));
        }
    });

    /**
     * An arriving #panel-faq (or #panel-workflow, ...) opens that tab.
     *
     * This is what makes a shared link to a tab work. The hash is left as it
     * was found - reading it is not the same as writing it.
     */
    function openFromHash() {
        var hash = window.location.hash;
        if (!hash || hash.indexOf('#panel-') !== 0) {
            return;
        }

        var name = hash.replace('#panel-', '');
        if (activate(name, false)) {
            markCurrentLink(name);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openFromHash);
    } else {
        openFromHash();
    }

    // The first tab is showing on load, so reflect that in the nav rather than
    // leaving every link unmarked until someone clicks one.
    var initial = document.querySelector('.lp-tabs .nav-link.active');
    if (initial) {
        markCurrentLink((initial.id || '').replace('tab-', ''));
    }
})();