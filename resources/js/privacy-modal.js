/*
 * The privacy notice, opened over the auth pages instead of navigating away
 * from a half-filled sign-in form.
 *
 * Its own entry rather than part of script.js because the two-factor page opts
 * out of that bundle (it has no password field and so has never loaded the
 * reveal script), and the footer link is on all five auth pages. Nothing here
 * runs on a page without the dialog.
 */

const dialog = document.querySelector('[data-privacy-modal]');

if (dialog) {
    const doc = dialog.querySelector('[data-privacy-doc]');
    const progress = dialog.querySelector('.privacy-modal-progress i');
    const tocLinks = [...dialog.querySelectorAll('.privacy-modal-toc a')];
    const sections = [...doc.querySelectorAll('section')];
    const root = document.documentElement;

    // Whatever was clicked to open it, so focus has somewhere to go back to.
    let opener = null;

    const open = (trigger) => {
        opener = trigger ?? null;

        // showModal over the open attribute: it takes focus, traps it, and
        // dims the page behind, which the attribute alone does none of.
        dialog.showModal();
        root.classList.add('privacy-modal-open');

        // Reopening should start at the top, not wherever it was left.
        doc.scrollTop = 0;
        paint();
    };

    document.querySelectorAll('[data-privacy-open]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            // Anything but a plain left click is someone deliberately asking
            // for the page itself — a new tab, a new window, a saved link.
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                return;
            }

            event.preventDefault();
            open(trigger);
        });
    });

    dialog.querySelectorAll('[data-privacy-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('close', () => {
        root.classList.remove('privacy-modal-open');

        // A modal dialog restores focus on its own, but the link is the thing
        // the reader was last certain of, so put them back on it explicitly.
        opener?.focus();
    });

    /*
     * closedby="any" gives click-outside-to-close declaratively in Chrome,
     * Edge and Firefox. Safari has not shipped it, so there it is worked out
     * from the click coordinates: a click that lands on the dialog element but
     * outside its box is a click on the backdrop.
     */
    if (!('closedBy' in HTMLDialogElement.prototype)) {
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) {
                return;
            }

            const box = dialog.getBoundingClientRect();

            const insideTheBox = event.clientX >= box.left && event.clientX <= box.right
                && event.clientY >= box.top && event.clientY <= box.bottom;

            if (!insideTheBox) {
                dialog.close();
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Where am I in it — the progress hairline under the header, and the
     * section the contents rail marks as current.
     * ------------------------------------------------------------------ */
    const paint = () => {
        const scrollable = doc.scrollHeight - doc.clientHeight;
        progress?.style.setProperty('--read', scrollable > 0 ? Math.min(doc.scrollTop / scrollable, 1) : 0);

        // The heading nearest above the top of the pane is the one being read.
        const line = doc.getBoundingClientRect().top + 90;
        let current = sections[0];

        for (const section of sections) {
            if (section.getBoundingClientRect().top <= line) {
                current = section;
            }
        }

        tocLinks.forEach((link) => {
            if (current && link.getAttribute('href') === `#${current.id}`) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    let painting = false;

    doc.addEventListener('scroll', () => {
        if (painting) {
            return;
        }

        painting = true;

        window.requestAnimationFrame(() => {
            paint();
            painting = false;
        });
    }, { passive: true });

    /*
     * The contents scroll the pane rather than navigating. Letting the anchor
     * through would leave the address bar reading /login#retention — a
     * fragment of a document the reader is not on, which then survives a
     * refresh and a shared link.
     */
    dialog.querySelectorAll('[href^="#"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const target = doc.querySelector(link.getAttribute('href'));

            if (!target) {
                return;
            }

            event.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    /*
     * /privacy-policy sends a signed-out reader here with the dialog already
     * open, so the notice is on screen even if this file never loads. When it
     * does load, trade that plain open dialog for a real modal.
     *
     * removeAttribute rather than close(): closing would fire the close event
     * and run the handler above for a dialog that was never properly open.
     *
     * Last in the file because open() calls paint(), which is declared further
     * up as a const — reaching it from any earlier line is a dead-zone error.
     */
    if (dialog.hasAttribute('data-privacy-autoopen')) {
        dialog.removeAttribute('open');

        // Closing it should land on the footer link, which is where the reader
        // would have opened it from had they arrived by the usual route.
        open(document.querySelector('[data-privacy-open]'));

        /*
         * Leaving ?privacy=1 in the address bar survives a refresh and a
         * shared link, and would reopen the notice over someone's sign-in form
         * tomorrow. Same reasoning, and the same treatment, as ?reason= in
         * script.js.
         */
        const url = new URL(window.location.href);

        if (url.searchParams.has('privacy')) {
            url.searchParams.delete('privacy');
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        }
    }
}
