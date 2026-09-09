/**
 * Opens a report's print dialog over the page the reader is already on.
 *
 * The point of previewing a PDF export is to see the document before deciding
 * to keep it, and the browser's own print dialog does that better than anything
 * we could build: it lays the report out, counts the pages, offers portrait or
 * landscape, and has Save as PDF in the same window. What it must not do is
 * cost the reader their place -- neither a new tab to close afterwards nor a
 * navigation away from the report and back.
 *
 * The obvious way to do that is an iframe, and it does not work here. The
 * application sends X-Frame-Options: DENY and sets frame-ancestors 'none', so
 * it refuses to be framed by anything, itself included. A frame pointed at one
 * of its own pages loads a blocked document, and Chrome still fires `load` for
 * it -- so the failure arrives looking like success and only surfaces when
 * print() throws on the inaccessible window. Relaxing the header for one
 * feature would trade the application's clickjacking defence for a
 * convenience.
 *
 * So the document is fetched and injected into the current page instead. It
 * sits off-screen until the dialog opens, and a print stylesheet stands the
 * host page down for the duration. No frame, no navigation, no change to the
 * security posture.
 */
const ROOT_ID = 'report-print-root';
const PRINTING_CLASS = 'is-printing-report';

let restoreTitle = null;

const tearDown = () => {
    document.getElementById(ROOT_ID)?.remove();
    document.documentElement.classList.remove(PRINTING_CLASS);

    if (restoreTitle !== null) {
        document.title = restoreTitle;
        restoreTitle = null;
    }
};

/**
 * The dialog offers the document title as the default filename, and while the
 * report is injected into the host page that title is the application's -- so a
 * saved export would land as "Attendance Reports · Memorial Hospital &
 * Sanitarium.pdf". The fragment carries the name the file should have; it is
 * borrowed for the length of the print and handed back afterwards.
 */
const useExportTitle = (root) => {
    const title = root.querySelector('[data-print-title]')?.dataset.printTitle;

    if (!title) {
        return;
    }

    restoreTitle = document.title;
    document.title = title.replace(/\.pdf$/i, '');
};

/** Two frames of slack, so the injected styles are applied before we measure. */
const nextPaint = () => new Promise((resolve) => {
    window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
});

const printReport = async (url) => {
    tearDown();

    let response;

    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
    } catch (error) {
        console.error('The report could not be prepared for printing.', error);

        return;
    }

    // A range over the print ceiling is answered with a redirect back to the
    // report carrying the reason. Following it here would inject the report
    // screen into itself, so the redirect is honoured as a navigation -- to the
    // report, never to the printable page -- and the reader sees the message.
    if (response.redirected) {
        window.location.href = response.url;

        return;
    }

    if (!response.ok) {
        console.error(`The report could not be prepared for printing (${response.status}).`);

        return;
    }

    const root = document.createElement('div');
    root.id = ROOT_ID;
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = await response.text();

    document.body.appendChild(root);
    document.documentElement.classList.add(PRINTING_CLASS);
    useExportTitle(root);

    await nextPaint();

    // afterprint covers both outcomes -- saved and cancelled -- and fires in
    // every browser this runs in. The timer is only there so a dialog that is
    // dismissed in some way that does not raise it cannot leave the document
    // attached for the rest of the session.
    window.addEventListener('afterprint', tearDown, { once: true });
    window.setTimeout(tearDown, 120000);

    window.print();
};

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-print-url]');

    if (!trigger) {
        return;
    }

    event.preventDefault();
    printReport(trigger.dataset.printUrl);
});
