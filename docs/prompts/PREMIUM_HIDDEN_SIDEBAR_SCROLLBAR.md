# Premium Hidden Sidebar Scrollbar Prompt

Copy and use this prompt when removing the visible scrollbar from the shared HRMS sidebar.

## Prompt

You are improving an existing Laravel Blade HRMS dashboard with a fixed, responsive, collapsible sidebar. Make the sidebar feel cleaner and more premium by hiding its visible scrollbar track and thumb without disabling or weakening scrolling.

### Required behavior

- Keep `overflow-y: auto` so long navigation content remains fully reachable.
- Hide the scrollbar in Chrome, Edge, Safari, Firefox, and legacy Microsoft engines using the appropriate standards and vendor selectors.
- Preserve mouse-wheel, trackpad, touch, Page Up/Page Down, arrow-key, Tab-focus, and programmatic scrolling behavior.
- Prevent accidental horizontal scrolling inside the sidebar.
- Contain vertical overscroll so reaching the end of the sidebar does not unexpectedly scroll the page behind it.
- Apply the behavior to both expanded and collapsed desktop states and to the existing mobile drawer.
- Do not use JavaScript to fake scrolling and do not add a package or external dependency.

### Non-negotiable safeguards

- Do not remove `overflow-y: auto` and do not use `overflow: hidden` on the vertical axis.
- Do not change the sidebar width, spacing, navigation order, routes, role checks, active states, profile link, settings link, or collapse preference.
- Preserve the premium collapse button, custom tooltip, animations, dark mode, compact-navigation mode, reduced-motion mode, and mobile drawer behavior.
- Do not modify controllers, models, database files, migrations, authentication, or page-specific functionality.
- Make the smallest possible shared CSS change so every authenticated page receives the same behavior.

### Cross-browser CSS target

Use `scrollbar-width: none` for Firefox, `-ms-overflow-style: none` for older Microsoft engines, and a zero-size hidden `::-webkit-scrollbar` for Chromium and Safari. Keep `overflow-x: hidden`, `overflow-y: auto`, and `overscroll-behavior-y: contain` on the sidebar.

### Required verification

- Confirm the scrollbar track and thumb are not visible when sidebar content overflows.
- Confirm the last navigation item and profile section remain reachable with wheel, trackpad, touch, and keyboard navigation.
- Confirm scrolling works in expanded, collapsed, and mobile drawer states.
- Run the complete Laravel test suite and the Vite production build.
- Confirm no route, link, permission, layout, or existing page interaction changed.

### Definition of done

The sidebar should retain full native scrolling behavior while presenting a seamless, uninterrupted premium surface with no visible scrollbar chrome.
