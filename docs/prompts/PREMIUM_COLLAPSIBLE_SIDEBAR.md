# Premium Collapsible Sidebar Prompt

Copy and use the prompt below when implementing or reviewing the shared HRMS sidebar.

## Prompt

You are improving an existing Laravel Blade Human Resource Management System. Redesign its shared sidebar into a polished, premium collapsible navigation while preserving every working feature and the current green hospital identity.

### Primary objective

Create a desktop sidebar that smoothly changes between a full 272px navigation panel and an 84px icon rail. Place one compact 38px edge control where the sidebar meets the page, aligned with the brand area. The control should look intentionally attached to the layout—not like a generic floating button.

### Toggle design direction

- Use a refined rounded-square glass control with a deep emerald gradient, subtle inner highlight, restrained glow, and a clean outline separating it from the page.
- Combine a small vertical panel grip with a chevron. In expanded mode, the chevron points toward the sidebar; in collapsed mode, it points toward the page.
- Add tasteful hover elevation, press feedback, and a highly visible keyboard focus ring.
- Show a compact custom tooltip containing “Collapse sidebar” or “Expand sidebar” on hover and keyboard focus.
- Update `aria-expanded`, `aria-label`, and the visible tooltip text whenever the state changes.
- Keep the control hidden at 1100px and below because mobile must continue using the existing hamburger and drawer controls.
- Honor the existing reduced-motion preference and avoid distracting looping animation.

### Sidebar behavior

- Animate sidebar width, page offset, spacing, and chevron direction with one consistent 240ms easing curve.
- Expanded mode must retain the logo, section headings, labels, badges, system status, and user profile.
- Collapsed mode must center all icons, keep the active state obvious, preserve native link destinations, and provide accessible names/tooltips.
- Save the desktop preference in `localStorage` and restore it before the CSS loads to prevent layout flashing during refresh or navigation.
- Ensure the main content uses the released space without overlap, horizontal scrolling, or sudden jumps.

### Non-negotiable safeguards

- Do not remove, rename, reorder, or replace existing routes and anchor links.
- Do not change authorization or role-based visibility conditions.
- Do not alter controllers, models, database structures, forms, reports, or page-specific JavaScript.
- Preserve the mobile drawer, hamburger button, close button, overlay, Escape key behavior, and link-to-close behavior.
- Preserve light mode, dark mode, compact navigation, reduced motion, profile access, settings access, and logout behavior.
- Implement centrally through the shared Blade layout/sidebar, shared CSS, and shared JavaScript so every authenticated page behaves consistently.
- Use semantic HTML and do not add a new package or external dependency.

### Required verification

- Add regression assertions for the toggle, its accessible attributes, decorative grip, profile link, settings link, and navigation labels.
- Run the complete Laravel test suite and the Vite production build.
- Verify expanded and collapsed states at desktop width and confirm the toggle is absent at mobile width.
- Confirm that refreshing and navigating between pages retains the selected desktop state.
- Confirm that all existing links, dropdowns, page actions, role restrictions, and dark-mode controls continue working.

### Definition of done

The result should feel like part of a premium enterprise dashboard: visually balanced, compact, accessible, responsive, persistent across pages, and completely non-disruptive to existing HRMS functionality.
