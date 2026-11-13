(function () {
    'use strict';

    var labels = {
        matched: 'No mismatch found',
        attention: 'Owner decision required',
        blocked: 'Ownership needs review',
        unavailable: 'Unable to verify',
        'no-evidence': 'No runtime evidence'
    };

    $(function () {
        var check = document.getElementById('compose-identity-check');
        var report = document.getElementById('compose-identity-report');
        if (!check || !report) {
            return;
        }

        function request(data) {
            return $.ajax({
                url: '/plugins/compose.manager/include/Exec.php',
                type: 'POST',
                dataType: 'json',
                data: data
            });
        }

        function showError(message) {
            report.textContent = message;
            swal('Project identity check failed', message, 'error');
        }

        function setBusy(busy) {
            check.disabled = busy;
            report.setAttribute('aria-busy', String(busy));
            report.querySelectorAll('button').forEach(function (button) {
                button.disabled = busy;
            });
        }

        function render(identities) {
            report.replaceChildren();
            if (!identities.length) {
                report.textContent = 'No stacks found.';
                return;
            }
            var attentionCount = identities.filter(function (identity) {
                return identity.auditStatus === 'attention' || identity.auditStatus === 'blocked';
            }).length;
            var summary = document.createElement('p');
            summary.textContent = identities.length + ' stacks checked; ' + attentionCount + ' need ownership review.';
            report.appendChild(summary);
            var table = document.createElement('table');
            table.className = 'tablesorter';
            var header = table.createTHead().insertRow();
            ['Stack', 'Project name', 'Result', 'Details / Actions'].forEach(function (label) {
                var cell = document.createElement('th');
                cell.scope = 'col';
                cell.textContent = label;
                header.appendChild(cell);
            });
            var body = table.createTBody();
            var priority = { blocked: 0, attention: 1, unavailable: 2, 'no-evidence': 3, matched: 4 };
            identities.slice().sort(function (left, right) {
                return priority[left.auditStatus] - priority[right.auditStatus];
            }).forEach(function (identity) {
                var row = body.insertRow();
                row.insertCell().textContent = identity.displayName || identity.projectFolder;
                row.insertCell().textContent = identity.projectName;
                row.insertCell().textContent = labels[identity.auditStatus];
                var detail = row.insertCell();
                var message = document.createElement('p');
                message.textContent = identity.auditMessage;
                detail.appendChild(message);
                if (identity.canChoose && identity.auditStatus === 'attention') {
                    (identity.candidates || []).forEach(function (candidate) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = 'Use project: ' + candidate;
                        button.addEventListener('click', function () {
                            swal({
                                title: 'Change saved project identity?',
                                text: 'Use "' + candidate + '" for "' + (identity.displayName || identity.projectFolder) +
                                    '"? Future Compose actions will target this project. No containers will be restarted now.',
                                type: 'warning',
                                showCancelButton: true,
                                confirmButtonText: 'Use this project'
                            }, function (confirmed) {
                                if (!confirmed) {
                                    return;
                                }
                                setBusy(true);
                                request({ action: 'setProjectIdentity', stackName: identity.projectFolder, projectName: candidate })
                                    .done(function (data) {
                                        if (!data || data.result !== 'success') {
                                            showError((data && data.message) || 'Failed to save project identity.');
                                            setBusy(false);
                                            return;
                                        }
                                        runCheck();
                                    })
                                    .fail(function () {
                                        showError('Could not save project identity. Check the connection and run the check again before retrying.');
                                        setBusy(false);
                                    });
                            });
                        });
                        detail.appendChild(button);
                    });
                }
            });
            report.appendChild(table);
        }

        function runCheck() {
            setBusy(true);
            report.textContent = 'Checking project identities...';
            request({ action: 'getProjectIdentities' })
                .done(function (data) {
                    if (!data || data.result !== 'success' || !Array.isArray(data.identities) ||
                        data.identities.some(function (identity) {
                            return !identity || !Object.prototype.hasOwnProperty.call(labels, identity.auditStatus) ||
                                typeof identity.auditMessage !== 'string';
                        })) {
                        showError((data && data.message) || 'Invalid project identity report. Run the check again.');
                        return;
                    }
                    render(data.identities);
                })
                .fail(function () {
                    showError('Could not check project identities. Check the connection and try again.');
                })
                .always(function () {
                    setBusy(false);
                });
        }

        check.addEventListener('click', runCheck);
    });
}());
