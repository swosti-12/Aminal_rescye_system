/**
 * Admin rescue queue: dynamic status updates, archive, history filters.
 */
(function () {
    'use strict';

    var queueEl = document.getElementById('ars-active-queue');
    var historyBody = document.getElementById('ars-history-tbody');
    var historyMeta = document.getElementById('ars-history-meta');
    var modalBackdrop = document.getElementById('ars-archive-modal');
    var modalConfirm = document.getElementById('ars-archive-confirm');
    var modalCancel = document.getElementById('ars-archive-cancel');
    var pendingStatusUpdate = null;

    function counterEls() {
        return {
            active: document.getElementById('ars-count-active'),
            today: document.getElementById('ars-count-today'),
            archived: document.getElementById('ars-count-archived'),
        };
    }

    function updateCounters(counters) {
        if (!counters) return;
        var els = counterEls();
        if (els.active) els.active.textContent = counters.active_requests ?? '0';
        if (els.today) els.today.textContent = counters.completed_today ?? '0';
        if (els.archived) els.archived.textContent = counters.archived_cases ?? '0';
    }

    function fetchStats() {
        return fetch('backend/api/admin_queue_stats.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.counters) updateCounters(data.counters);
            })
            .catch(function () {});
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function removeCardFromQueue(caseId) {
        var card = document.querySelector('.admin-request-card[data-case-id="' + caseId + '"]');
        if (!card) return;
        card.classList.add('is-archiving');
        setTimeout(function () {
            card.classList.add('is-removed');
            var empty = queueEl && !queueEl.querySelector('.admin-request-card:not(.is-removed)');
            if (empty && queueEl) {
                queueEl.innerHTML = '<p class="ars-queue-empty" style="margin:0;color:#64748b;">No active requests in queue. Completed cases appear under Case History.</p>';
            }
        }, 280);
    }

    function postStatusUpdate(caseId, status, confirmArchive) {
        return fetch('backend/api/admin_update_case_status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                case_id: caseId,
                case_status: status,
                confirm_archive: !!confirmArchive,
            }),
        }).then(function (r) { return r.json(); });
    }

    function applyStatusSuccess(data) {
        if (data.counters) updateCounters(data.counters);
        if (data.archived) {
            removeCardFromQueue(data.case_id);
        } else {
            var badge = document.querySelector(
                '.admin-request-card[data-case-id="' + data.case_id + '"] .js-case-status-badge'
            );
            if (badge && data.status_label) {
                badge.textContent = data.status_label;
                badge.className = 'ars-badge js-case-status-badge ' + (data.status_class || '');
            }
        }
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'success',
                title: 'Updated',
                text: data.message || 'Status saved.',
                timer: 2200,
                showConfirmButton: false,
            });
        }
    }

    function openArchiveModal(caseId, status, form) {
        pendingStatusUpdate = { caseId: caseId, status: status, form: form };
        if (modalBackdrop) modalBackdrop.classList.add('is-open');
    }

    function closeArchiveModal() {
        pendingStatusUpdate = null;
        if (modalBackdrop) modalBackdrop.classList.remove('is-open');
    }

    function onStatusFormSubmit(e) {
        var form = e.target.closest('.js-case-status-form');
        if (!form) return;
        e.preventDefault();
        var caseId = parseInt(form.getAttribute('data-case-id') || '0', 10);
        var sel = form.querySelector('[name="case_status"]');
        var status = sel ? sel.value : '';
        if (!caseId || !status) return;

        postStatusUpdate(caseId, status, false).then(function (data) {
            if (data.requires_confirmation) {
                openArchiveModal(caseId, status, form);
                return;
            }
            if (!data.ok) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Update failed.' });
                }
                return;
            }
            applyStatusSuccess(data);
        }).catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach server.' });
            }
        });
    }

    if (modalConfirm) {
        modalConfirm.addEventListener('click', function () {
            if (!pendingStatusUpdate) return;
            var p = pendingStatusUpdate;
            closeArchiveModal();
            postStatusUpdate(p.caseId, p.status, true).then(function (data) {
                if (!data.ok) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Update failed.' });
                    }
                    return;
                }
                applyStatusSuccess(data);
            });
        });
    }
    if (modalCancel) {
        modalCancel.addEventListener('click', closeArchiveModal);
    }
    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', function (e) {
            if (e.target === modalBackdrop) closeArchiveModal();
        });
    }

    document.addEventListener('submit', onStatusFormSubmit);

    /* Sub-tabs: Active Queue | Case History */
    var subnav = document.querySelector('.ars-queue-subnav');
    if (subnav) {
        subnav.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-queue-pane]');
            if (!btn) return;
            var pane = btn.getAttribute('data-queue-pane');
            subnav.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.querySelectorAll('.ars-queue-pane').forEach(function (p) {
                p.classList.toggle('active', p.id === 'ars-pane-' + pane);
            });
            if (pane === 'history') loadHistory(1);
        });
    }

    var historyPage = 1;

    function loadHistory(page) {
        if (!historyBody) return;
        historyPage = page || 1;
        var params = new URLSearchParams();
        params.set('page', String(historyPage));
        params.set('per_page', '20');
        var search = document.getElementById('ars-filter-search');
        var status = document.getElementById('ars-filter-status');
        var dateFrom = document.getElementById('ars-filter-date-from');
        var dateTo = document.getElementById('ars-filter-date-to');
        var rescuer = document.getElementById('ars-filter-rescuer');
        if (search && search.value) params.set('search', search.value);
        if (status && status.value) params.set('status', status.value);
        if (dateFrom && dateFrom.value) params.set('date_from', dateFrom.value);
        if (dateTo && dateTo.value) params.set('date_to', dateTo.value);
        if (rescuer && rescuer.value) params.set('rescuer_id', rescuer.value);

        historyBody.innerHTML = '<tr><td colspan="8" style="padding:1.25rem;color:#94a3b8;">Loading…</td></tr>';

        fetch('backend/api/admin_case_history.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Invalid JSON response');
                    }
                });
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    var errMsg = (data && data.error) ? data.error : 'Could not load history.';
                    historyBody.innerHTML = '<tr><td colspan="8" style="padding:1.25rem;color:#dc2626;">' + escapeHtml(errMsg) + '</td></tr>';
                    return;
                }
                if (!data.items || data.items.length === 0) {
                    historyBody.innerHTML = '<tr><td colspan="8" style="padding:1.25rem;color:#64748b;">No archived cases match your filters.</td></tr>';
                    if (historyMeta) {
                        historyMeta.textContent = '0 results';
                    }
                    return;
                }
                historyBody.innerHTML = data.items.map(function (row) {
                    var archived = row.archived_at ? new Date(row.archived_at).toLocaleString() : '—';
                    var created = row.created_at ? new Date(row.created_at).toLocaleString() : '—';
                    return '<tr>' +
                        '<td>#' + row.case_id + '</td>' +
                        '<td>' + escapeHtml(row.animal_type) + '</td>' +
                        '<td><span class="ars-badge ' + escapeHtml(row.status_class || '') + '">' +
                        escapeHtml(row.status_label) + '</span></td>' +
                        '<td>' + escapeHtml(row.priority_level) + '</td>' +
                        '<td>' + escapeHtml(row.reporter_name) + '</td>' +
                        '<td>' + escapeHtml(row.rescuer_name || '—') + '</td>' +
                        '<td>' + escapeHtml(created) + '</td>' +
                        '<td>' + escapeHtml(archived) + '</td></tr>';
                }).join('');
                if (historyMeta) {
                    historyMeta.textContent = 'Page ' + data.page + ' of ' + (data.total_pages || 1) +
                        ' · ' + data.total + ' archived case(s)';
                }
                bindHistoryPagination(data.page, data.total_pages || 1);
            })
            .catch(function (err) {
                var msg = err && err.message ? err.message : 'Network error';
                historyBody.innerHTML = '<tr><td colspan="8" style="padding:1.25rem;color:#dc2626;">Failed to load history: ' + escapeHtml(msg) + '. Check that you are logged in as admin.</td></tr>';
            });
    }

    function bindHistoryPagination(page, totalPages) {
        var prev = document.getElementById('ars-history-prev');
        var next = document.getElementById('ars-history-next');
        if (prev) {
            prev.disabled = page <= 1;
            prev.onclick = function () { if (page > 1) loadHistory(page - 1); };
        }
        if (next) {
            next.disabled = page >= totalPages;
            next.onclick = function () { if (page < totalPages) loadHistory(page + 1); };
        }
    }

    var filterBtn = document.getElementById('ars-history-apply');
    if (filterBtn) filterBtn.addEventListener('click', function () { loadHistory(1); });

    fetchStats();
})();
