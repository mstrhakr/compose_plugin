/* Shared-source notices are advisory; they do not establish operation ownership. */
function parseEditorSourceResponse(data) {
    return typeof data === 'string' ? JSON.parse(data) : data;
}

function editorSharedSourceText(response) {
    var lines = (response.shared || []).map(function(entry) {
        return entry.path + ' is also used by: ' + entry.stacks.join(', ');
    });
    if ((response.incomplete || []).length) {
        lines.push('Sharing could not be checked for: ' + response.incomplete.join(', ') + '.');
    }
    lines.push('Saving changes the shared source, not just this stack. Running containers are not automatically recreated.');
    return lines.join('\n');
}

var editorSharedSourceLoad = 0;
function loadEditorSharedSources(project) {
    var owner = ++editorSharedSourceLoad;
    $('#editor-shared-sources').hide();
    $.post(caURL, { action: 'getSharedStackSources', script: project }).done(function(data) {
        if (editorModal.currentProject !== project || owner !== editorSharedSourceLoad) return;
        try {
            var response = parseEditorSourceResponse(data);
            if (!response || response.result !== 'success') throw new Error(response && response.message || 'Invalid server response.');
            $('#editor-shared-sources-dismissed').prop('checked', response.dismissed);
            $('#editor-shared-sources-message').text(editorSharedSourceText(response)).toggle(!response.dismissed);
            $('#editor-shared-sources').toggle(response.dismissed || response.shared.length > 0 || response.incomplete.length > 0);
        } catch (error) {
            swal({ title: 'Shared-file check failed', text: error.message, type: 'error' });
        }
    }).fail(function() {
        if (editorModal.currentProject === project && owner === editorSharedSourceLoad) swal({ title: 'Shared-file check failed', text: 'Unable to check shared files.', type: 'error' });
    });
}

function setEditorSharedSourceWarning(dismissed) {
    var project = editorModal.currentProject;
    $.post(caURL, { action: 'setSharedSourceWarning', script: project, dismissed: dismissed ? '1' : '0' })
        .done(function(data) {
            try {
                var response = parseEditorSourceResponse(data);
                if (!response || response.result !== 'success') throw new Error(response && response.message || 'Invalid server response.');
                if (editorModal.currentProject === project) loadEditorSharedSources(project);
            } catch (error) {
                if (editorModal.currentProject === project) $('#editor-shared-sources-dismissed').prop('checked', !dismissed);
                swal({ title: 'Preference save failed', text: error.message, type: 'error' });
            }
        }).fail(function() {
            if (editorModal.currentProject === project) $('#editor-shared-sources-dismissed').prop('checked', !dismissed);
            swal({ title: 'Preference save failed', text: 'Unable to save the warning preference.', type: 'error' });
        });
}

function postEditorSourceSave(payload) {
    var deferred = $.Deferred();
    function send(confirmed) {
        $.post(caURL, payload).done(function(data) {
            var response;
            try {
                response = parseEditorSourceResponse(data);
            } catch (_error) {
                // Legacy successful saves return "<path> saved", not JSON.
                deferred.resolve(data);
                return;
            }
            if (!response || response.errorCode !== 'shared_source_confirmation') {
                deferred.resolve(data);
                return;
            }
            if (editorModal.currentProject !== payload.script) {
                deferred.resolve(JSON.stringify({ result: 'error', message: 'Editor changed. Reopen the stack before saving.' }));
                return;
            }
            if (confirmed) {
                deferred.resolve(JSON.stringify({ result: 'error', message: 'Shared sources changed during confirmation. Review and save again.' }));
                return;
            }
            swal({
                title: 'Save shared source?',
                text: '<div style="white-space:pre-wrap;text-align:left;">' + composeEscapeHtml(editorSharedSourceText(response)) + '</div>'
                    + '<label><input id="shared-source-confirm-dismiss" type="checkbox"> Don\'t show again for this stack</label>',
                html: true, type: 'warning', showCancelButton: true,
                confirmButtonText: 'Save shared file', cancelButtonText: 'Cancel', closeOnConfirm: false
            }, function(accepted) {
                if (editorModal.currentProject !== payload.script) accepted = false;
                if (!accepted) {
                    deferred.resolve(JSON.stringify({ result: 'error', message: 'Save cancelled. Your unsaved changes have been retained.' }));
                    return;
                }
                var dismiss = $('#shared-source-confirm-dismiss').prop('checked');
                swal.close();
                payload.sharedConfirmation = response.confirmation;
                if (!dismiss) { send(true); return; }
                $.post(caURL, { action: 'setSharedSourceWarning', script: payload.script, dismissed: '1' })
                    .done(function(preferenceData) {
                        try {
                            var preference = parseEditorSourceResponse(preferenceData);
                            if (!preference || preference.result !== 'success') throw new Error(preference && preference.message || 'Unable to save warning preference.');
                            if (editorModal.currentProject === payload.script) loadEditorSharedSources(payload.script);
                            send(true);
                        } catch (error) {
                            deferred.resolve(JSON.stringify({ result: 'error', message: error.message }));
                        }
                    }).fail(function() { deferred.reject(); });
            });
        }).fail(function() { deferred.reject(); });
    }
    send(false);
    return deferred.promise();
}
