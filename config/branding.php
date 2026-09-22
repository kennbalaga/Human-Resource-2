<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Placeholder branding
    |--------------------------------------------------------------------------
    |
    | The system is not yet cleared to carry the client hospital's name or
    | seal, so every user-facing mention of the institution reads from here
    | instead of being written into a view. When permission comes through,
    | the real name goes in the environment (or these defaults) and the whole
    | app follows — no view hunting, no stray copyright line left behind.
    |
    | The matching artwork is the WorkForce mark in public/images/icons; see
    | resources/views/components/brand-mark.blade.php for the on-page version.
    |
    */

    'organization' => env('BRAND_ORGANIZATION', 'Memorial Hospital & Sanitarium'),

    // The product's own name — the WorkForce wordmark beside the Staff Cross
    // mark. Short enough for a 44px launcher tile or a browser tab.
    'short_name' => env('BRAND_SHORT_NAME', 'WorkForce'),

    // The line under the wordmark on the auth pages and the privacy notice.
    'tagline' => env('BRAND_TAGLINE', 'Hospital Workforce & HR System'),

];
