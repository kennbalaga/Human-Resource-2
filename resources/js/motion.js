/**
 * The "Reduce motion" preference, for the scrolls JavaScript asks for itself.
 *
 * A `behavior` passed to scrollIntoView/scrollTo/scrollBy overrides the
 * stylesheet's `scroll-behavior`, so the global reduce-motion rule in
 * dashboard.css cannot reach these calls — a smooth scroll written in JS keeps
 * animating for a user who asked the app to stop moving. Both switches are
 * read here: the device's own accessibility setting and the per-user
 * preference the layout renders onto <body>.
 */
export const prefersReducedMotion = () =>
    document.body.classList.contains('reduce-motion')
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Pass straight into a scroll call's `behavior`. */
export const scrollBehavior = () => (prefersReducedMotion() ? 'auto' : 'smooth');
