/*
 * Account settings — section navigation.
 *
 * The side nav is a column of in-page anchors. On its own an anchor gives no
 * feedback: clicking a section whose panel is already near the top of the
 * viewport scrolls a few pixels and reads as a dead control. This keeps an
 * `aria-current` marker on the entry for the panel you are actually reading,
 * so every click lands somewhere visible — and screen readers get the same
 * "you are here" the sighted marker gives.
 *
 * Entries whose panel is hidden are skipped rather than special-cased. The app
 * lock panel is display:none on a desktop, and a hidden panel must never win
 * the highlight.
 */

/** The offset an anchor jump stops short of, which is also the line a section becomes "current" at. */
function activationLine(panel) {
    const parsed = Number.parseFloat(getComputedStyle(panel).scrollMarginTop);

    return Number.isFinite(parsed) ? parsed : 0;
}

/** A panel hidden by CSS reports no layout boxes at all. */
function isVisible(panel) {
    return panel.getClientRects().length > 0;
}

document.addEventListener('DOMContentLoaded', () => {
    const nav = document.querySelector('.settings-section-nav');

    if (!nav) {
        return;
    }

    const sections = [...nav.querySelectorAll('a[href^="#"]')]
        .map((link) => ({ link, panel: document.getElementById(decodeURIComponent(link.hash.slice(1))) }))
        .filter((section) => section.panel);

    if (sections.length === 0) {
        return;
    }

    let current = null;

    const setCurrent = (section) => {
        if (section === current) {
            return;
        }

        current?.link.removeAttribute('aria-current');
        section?.link.setAttribute('aria-current', 'true');
        current = section ?? null;

        // At <=700px the nav is a horizontal chip rail, so the marked chip can
        // sit off-screen. Nudge it into view without moving the page itself.
        if (section && nav.scrollWidth > nav.clientWidth) {
            const chip = section.link.getBoundingClientRect();
            const rail = nav.getBoundingClientRect();

            if (chip.left < rail.left || chip.right > rail.right) {
                nav.scrollBy({ left: chip.left - rail.left - 12, behavior: 'smooth' });
            }
        }
    };

    // Set by a click and held until the smooth scroll arrives. Without it the
    // scroll events on the way there drag the highlight through every section
    // in between, which on a long jump reads as a strobe rather than a move.
    let pending = null;
    let pendingUntil = 0;

    const sectionInView = () => {
        const visible = sections.filter((section) => isVisible(section.panel));

        if (visible.length === 0) {
            return null;
        }

        // A short final panel never reaches the activation line, so at the
        // bottom of the page it would otherwise never light up.
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
            return visible[visible.length - 1];
        }

        // The last panel whose top has passed the line, falling back to the
        // first one when the page is scrolled above every panel.
        const passed = visible.filter(
            (section) => section.panel.getBoundingClientRect().top - activationLine(section.panel) <= 1,
        );

        return passed[passed.length - 1] ?? visible[0];
    };

    const sync = () => {
        const inView = sectionInView();

        if (pending) {
            // Arrived, or the scroll never got there — a jump to a panel near
            // the end of the page stops at the bottom, short of the line.
            if (inView === pending || Date.now() > pendingUntil) {
                pending = null;
            } else {
                return;
            }
        }

        setCurrent(inView);
    };

    let queued = false;

    const queueSync = () => {
        if (queued) {
            return;
        }

        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            sync();
        });
    };

    // The click marks its own target immediately. The smooth scroll that
    // follows takes a moment, and waiting for it to settle before the nav
    // reacts is the delay this whole file exists to remove.
    sections.forEach((section) => {
        section.link.addEventListener('click', () => {
            pending = section;
            pendingUntil = Date.now() + 1200;
            setCurrent(section);
        });
    });

    // Scrolling by hand mid-jump abandons the jump. The hold is only there to
    // stop the browser's own smooth scroll from fighting the click.
    const releasePending = () => {
        pending = null;
    };

    window.addEventListener('wheel', releasePending, { passive: true });
    window.addEventListener('touchstart', releasePending, { passive: true });

    window.addEventListener('scroll', queueSync, { passive: true });
    window.addEventListener('resize', queueSync);
    window.addEventListener('hashchange', queueSync);

    // app-lock.js reveals its panel from an async device check, so on a phone
    // one more section can appear after this file has already run.
    window.addEventListener('load', queueSync);

    sync();
});
