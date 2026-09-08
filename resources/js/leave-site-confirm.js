// Chrome's own "Leave site?" prompt should catch a real exit — the tab's ×
// button, a reload, typing a new address, the back/forward buttons, or
// closing the window — but beforeunload fires the same way for an ordinary
// click inside the app, and the two can't be told apart from the event
// alone. So every navigation this app drives on purpose (a link, a form
// submit, or a script-driven redirect/reload) marks itself exempt right
// before it happens; anything else falls through to the prompt.
let isIntentionalNavigation = false;

window.markIntentionalNavigation = () => {
    isIntentionalNavigation = true;
    // A click that never actually navigates (a dropdown toggle, a
    // prevented link, a failed submit) should not leave the page
    // permanently exempt from the prompt.
    window.setTimeout(() => { isIntentionalNavigation = false; }, 0);
};

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || link.target || /^(mailto|tel):/.test(link.href)) return;
    window.markIntentionalNavigation();
}, true);

document.addEventListener('submit', () => window.markIntentionalNavigation(), true);

window.addEventListener('beforeunload', (event) => {
    if (isIntentionalNavigation) return;
    event.preventDefault();
    event.returnValue = '';
});
