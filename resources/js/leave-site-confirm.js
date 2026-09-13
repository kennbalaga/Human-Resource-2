// Chrome's own "Leave site?" prompt should catch a real exit — the tab's ×
// button, a reload, typing a new address, the back/forward buttons, or
// closing the window — but beforeunload fires the same way for an ordinary
// click inside the app, and the two can't be told apart from the event
// alone. So every navigation this app drives on purpose (a link, a form
// submit, or a script-driven redirect/reload) marks itself exempt right
// before it happens; anything else falls through to the prompt.
let isIntentionalNavigation = false;

// The exemption cannot expire on the next tick. Chrome does not reach
// beforeunload inside the click or submit that caused it — measured at
// roughly 15ms later, before the request is even sent — so a timer set to
// clear "immediately after" always won that race and the prompt appeared on
// top of an ordinary Save. It is cleared by evidence instead: an event whose
// default was prevented never navigated, so the exemption it claimed is
// given back once the dispatch is over and defaultPrevented can be read.
const markUntilCancelled = (event) => {
    isIntentionalNavigation = true;

    window.setTimeout(() => {
        if (event.defaultPrevented) isIntentionalNavigation = false;
    }, 0);
};

// A script-driven navigation has no event to watch, so this one path keeps a
// timed release — long enough to outlast the browser's delay in reaching
// beforeunload, short enough that a redirect that never happened does not
// leave the page exempt from the prompt for the rest of the session.
window.markIntentionalNavigation = () => {
    isIntentionalNavigation = true;
    window.setTimeout(() => { isIntentionalNavigation = false; }, 2000);
};

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || link.target) return;
    // An in-page jump — the settings section nav, a "back to top" — scrolls
    // this document rather than unloading it, and mailto:/tel: hands off to
    // another app. Neither is a navigation, so neither may spend the
    // exemption the next real exit needs.
    if (link.getAttribute('href').startsWith('#') || /^(mailto|tel):/.test(link.href)) return;
    markUntilCancelled(event);
}, true);

document.addEventListener('submit', (event) => markUntilCancelled(event), true);

// Back and forward are only an exit when they leave the system. Moving between
// Timesheets and Payslips with the mouse's side buttons is ordinary use and
// must not ask "Leave site?".
//
// Those buttons reach the page as a mouseup -- back is 3, forward 4 -- just
// before the browser acts on them. The Navigation API lists only this origin's
// history entries, so a press that would step past the first or last of them
// is leaving the app, and keeps the prompt.
//
// The toolbar's arrows and a trackpad swipe cannot be exempted the same way.
// Chrome raises beforeunload for them before the page hears anything of the
// traversal -- a navigate listener was tried and measured to fire too late --
// so they still prompt, as a typed address or a reload does.
document.addEventListener('mouseup', (event) => {
    if (event.button !== 3 && event.button !== 4) return;

    const current = window.navigation?.currentEntry;
    if (current) {
        const target = current.index + (event.button === 3 ? -1 : 1);
        if (target < 0 || target >= window.navigation.entries().length) return;
    }

    window.markIntentionalNavigation();
}, true);

// A page brought back from the back/forward cache resumes with whatever flag it
// left with. It is a fresh visit, so it starts guarded again.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) isIntentionalNavigation = false;
});

window.addEventListener('beforeunload', (event) => {
    if (isIntentionalNavigation) return;
    event.preventDefault();
    event.returnValue = '';
});
