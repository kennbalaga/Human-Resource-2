import * as bootstrap from 'bootstrap';
import './leave-site-confirm';
import './dashboard';
import './staff-dashboard';
import './attendance';
import './attendance-settings';
import './schedule';
import './schedule-preferences';
import './workforce';
import './audit-logs';
import './burnout-risk';
import './organization';
import './ai-scheduling';
import './integration-settings';
import './theme';
import './session-timeout';
import './download-confirm';
import './global-search';
import './confirm-actions';
// The shared toast. Imported here so every page shows its success flash.
import './toast';
import './report-print';
import './settings-nav';
// Touch-only interaction polish and the on-device PIN/fingerprint app lock.
// Both no-op immediately on a desktop pointer.
import './app-mobile';
import './app-lock';
// Keeps the desk-bound roles off phones and keeps the server's view of this
// handset's app lock in step with the handset. Also imported by script.js, which
// is what the sign-in page loads.
import './mobile-access';

window.bootstrap = bootstrap;
