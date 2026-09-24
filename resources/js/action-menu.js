import { Dropdown } from 'bootstrap';

/*
 * Row and panel overflow menus.
 *
 * The menus live inside .table-responsive, which is a scroll container, so an
 * absolutely positioned dropdown is clipped by it — on the last row of a table
 * the menu simply never appeared. Popper's fixed strategy takes the panel out
 * of that container's coordinate space, which is the whole reason this is
 * registered by hand rather than left to Bootstrap's data-api.
 *
 * Takes a root because the employee directory replaces its results markup on
 * every keystroke of the live filter: the rows that come back are new nodes and
 * need registering again.
 */
export const initActionMenus = (root = document) => {
    root.querySelectorAll('[data-dashboard-action-menu]').forEach((toggle) => {
        Dropdown.getOrCreateInstance(toggle, {
            popperConfig: (defaultConfig) => ({
                ...defaultConfig,
                strategy: 'fixed',
            }),
        });
    });
};
