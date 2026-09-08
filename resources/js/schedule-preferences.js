// The page heading (subtitle + primary action button) is shared chrome above
// the Shift Swaps / Preferences tabs rather than duplicated inside each pane,
// so it has to track whichever tab Bootstrap's own tab plugin just activated.
document.addEventListener('DOMContentLoaded', () => {
    const tabNav = document.querySelector('[data-preference-tabs]');
    if (!tabNav) return;

    const subtitles = document.querySelectorAll('[data-tab-subtitle]');
    const actions = document.querySelectorAll('[data-tab-action]');

    const syncHeading = (key) => {
        subtitles.forEach((el) => { el.hidden = el.dataset.tabSubtitle !== key; });
        actions.forEach((el) => { el.hidden = el.dataset.tabAction !== key; });
    };

    const tabButtons = [...tabNav.querySelectorAll('[data-bs-toggle="tab"]')];
    tabButtons.forEach((button) => {
        button.addEventListener('shown.bs.tab', () => syncHeading(button.dataset.tabKey));
    });

    const initiallyActive = tabNav.querySelector('.active[data-bs-toggle="tab"]') ?? tabButtons[0];
    if (initiallyActive) syncHeading(initiallyActive.dataset.tabKey);

    // A notification or an old bookmark to the standalone Shift Swaps page
    // now lands here as #shift-swaps — honour it by switching tabs instead of
    // leaving the visitor on whichever tab defaults to active.
    const hashKey = window.location.hash.replace('#', '');
    const hashButton = hashKey && tabButtons.find((button) => button.dataset.tabKey === hashKey);
    if (hashButton && window.bootstrap) {
        window.bootstrap.Tab.getOrCreateInstance(hashButton).show();
    }
});
