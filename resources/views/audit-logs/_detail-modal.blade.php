{{--
    One shared detail dialog, populated by audit-logs.js from the data
    attributes on whichever Details button opened it.

    One modal per row would have meant twenty-five near-identical dialogs in the
    document on every page load, and a Content Security Policy rules out the
    other obvious shortcut of an inline onclick per row. A single dialog also
    keeps one set of ARIA ids valid, which twenty-five copies would not.

    Nothing shown here is a submitted value: the middleware stores field *names*
    only, and strips the credential fields before even that.
--}}
<div class="modal fade audit-detail-modal" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content schedule-modal-content">
            <div class="modal-header">
                <div>
                    <p class="panel-kicker">Audit event <span data-detail="event-id">—</span></p>
                    <h2 class="modal-title" id="auditDetailModalLabel" data-detail="action">Event details</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close event details"></button>
            </div>

            <div class="modal-body audit-detail-body">
                <div class="audit-detail-summary">
                    <span class="audit-status" data-detail="status-badge">
                        <span class="audit-status-dot" aria-hidden="true"></span>
                        <span data-detail="status">—</span>
                        <span class="audit-status-word" data-detail="status-label">—</span>
                    </span>
                    <time data-detail="timestamp" datetime="">—</time>
                </div>

                <dl class="audit-detail-grid">
                    <div>
                        <dt>Performed by</dt>
                        <dd><strong data-detail="user">—</strong><small data-detail="user-role">—</small></dd>
                    </div>
                    <div>
                        <dt>Activity type</dt>
                        <dd data-detail="activity">—</dd>
                    </div>
                    <div>
                        <dt>Module</dt>
                        <dd data-detail="module">—</dd>
                    </div>
                    <div>
                        <dt>Affected record</dt>
                        <dd data-detail="subject">—</dd>
                    </div>
                    <div>
                        <dt>Request method</dt>
                        <dd><code data-detail="method">—</code></dd>
                    </div>
                    <div>
                        <dt>Endpoint</dt>
                        <dd><code data-detail="endpoint">—</code></dd>
                    </div>
                    <div>
                        <dt>Route name</dt>
                        <dd><code data-detail="route">—</code></dd>
                    </div>
                    <div>
                        <dt>Recorded action</dt>
                        <dd><code data-detail="action-raw">—</code></dd>
                    </div>
                    <div>
                        <dt>IP address</dt>
                        <dd><code data-detail="ip">—</code></dd>
                    </div>
                    <div>
                        <dt>Request reference</dt>
                        <dd><code data-detail="request-id">—</code></dd>
                    </div>
                    <div class="audit-detail-wide">
                        <dt>Device and browser</dt>
                        <dd data-detail="device">—</dd>
                    </div>
                    <div class="audit-detail-wide">
                        <dt>Fields included in the request</dt>
                        <dd data-detail="fields">—</dd>
                    </div>
                </dl>

                <p class="audit-detail-note">
                    <x-icon name="lock" />
                    Field names are listed so a reviewer can see what a request touched. The values themselves
                    are never written to the audit trail, and passwords, one-time codes, recovery codes and
                    session tokens are removed before the field name is recorded.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
