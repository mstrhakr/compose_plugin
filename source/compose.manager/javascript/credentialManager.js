(function(window, $) {
    'use strict';

    var credentials = [];
    var onSaved = null;
    var githubPollTimer = null;
    var activeCredential = {};

    function ensureModal() {
        if ($('#compose-credential-modal').length) return;
        $('body').append(
            '<div id="compose-credential-modal" class="credential-modal-backdrop" style="display:none;">' +
                '<div class="credential-modal" role="dialog" aria-modal="true" aria-labelledby="credential-modal-title">' +
                    '<div class="credential-modal-header"><h3 id="credential-modal-title">Add registry credential</h3><button type="button" class="credential-close" title="Close"><i class="fa fa-times"></i></button></div>' +
                    '<input type="hidden" id="credential-id">' +
                    '<label for="credential-provider">Provider</label><select id="credential-provider"><option value="github">GitHub Container Registry</option><option value="docker">Docker Hub</option><option value="generic">Other registry</option></select>' +
                    '<button type="button" id="credential-github-signin"><i class="fa fa-github"></i> Sign in with GitHub</button>' +
                    '<div id="credential-github-device" style="display:none;"><p>Enter this code on GitHub:</p><strong id="credential-github-code"></strong> <a id="credential-github-link" target="_blank" rel="noopener noreferrer">Open GitHub</a><p id="credential-github-status">Waiting for authorization...</p></div>' +
                    '<div id="credential-oauth-connected" style="display:none;"><i class="fa fa-check-circle"></i> GitHub account connected</div>' +
                    '<div id="credential-details">' +
                        '<label for="credential-name">Name</label><input id="credential-name" type="text" autocomplete="off" placeholder="Work GitHub">' +
                        '<label for="credential-registry">Registry</label><input id="credential-registry" type="text" autocomplete="off" placeholder="ghcr.io">' +
                        '<label for="credential-username">Username</label><input id="credential-username" type="text" autocomplete="username">' +
                    '</div>' +
                    '<div id="credential-secret-wrap"><label for="credential-secret">Access token</label><input id="credential-secret" type="password" autocomplete="new-password" placeholder="Required for new credentials"><div class="credential-help">Use a read-only token. Existing tokens are never displayed.</div></div>' +
                    '<div id="credential-modal-error" class="compose-status-danger" style="display:none;"></div>' +
                    '<div class="credential-modal-actions"><button type="button" class="credential-cancel">Cancel</button><button type="button" class="credential-save">Save credential</button></div>' +
                '</div>' +
            '</div>'
        );
        if (!$('#compose-credential-styles').length) {
            $('head').append('<style id="compose-credential-styles">' +
                '.credential-modal-backdrop{position:fixed;inset:0;z-index:10003;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:20px;background:var(--modal-overlay-bg);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}' +
                '.credential-modal{display:flex;flex-direction:column;box-sizing:border-box;width:min(560px,95vw);max-height:calc(100vh - 40px);overflow-y:auto;padding:24px 28px;color:var(--text-color);background-color:var(--background-color);border:1px solid var(--border-color);border-radius:8px;box-shadow:var(--panel-box-shadow);font-family:inherit}' +
                '.credential-modal-header{display:flex;align-items:center;justify-content:space-between;margin:-24px -28px 20px;padding:12px 20px;background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border-bottom:1px solid var(--border-color);border-radius:8px 8px 0 0}' +
                '.credential-modal-header h3{margin:0;color:var(--brand-orange);font-size:1.25rem;font-weight:600}' +
                '.credential-close{padding:5px 10px;color:var(--alt-text-color);background:none;border:0;cursor:pointer;font-size:1.5rem;line-height:1}.credential-close:hover{color:var(--brand-red)}.credential-close:focus-visible{outline:2px solid var(--brand-orange);outline-offset:2px}' +
                '.credential-modal label{display:block;margin:14px 0 8px;color:var(--text-color);font-size:1.05rem;font-weight:600}' +
                '.credential-modal input,.credential-modal select{box-sizing:border-box;width:100%;max-width:100%;padding:12px 16px;color:var(--text-color);background-color:var(--input-background-color);border:1px solid var(--border-color);border-radius:6px;font:inherit;transition:border-color .2s ease,box-shadow .2s ease}' +
                '.credential-modal input:hover,.credential-modal select:hover{border-color:var(--border-hover-color)}.credential-modal input:focus,.credential-modal select:focus{outline:none;border-color:var(--brand-orange);box-shadow:0 0 0 3px var(--brand-focus-ring)}.credential-modal input::placeholder{color:var(--placeholder-color)}.credential-modal input[readonly]{color:var(--alt-text-color);background-color:var(--disabled-input-background-color)}' +
                '.credential-help{margin-top:8px;color:var(--alt-text-color);font-size:.95rem;line-height:1.4}' +
                '.credential-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin:22px -28px -24px;padding:12px 20px;background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border-top:1px solid var(--border-color);border-radius:0 0 8px 8px}' +
                '.credential-modal-actions button,#credential-github-signin{padding:10px 25px;border:0;border-radius:4px;cursor:pointer;font-size:.95rem}' +
                '.credential-cancel{color:var(--text-color);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color)}.credential-cancel:hover{background-color:var(--border-color)}' +
                '.credential-save,#credential-github-signin{color:var(--action-primary-color);background-color:var(--action-primary-bg)}.credential-save:hover,#credential-github-signin:hover{background-color:var(--action-primary-bg-hover)}.credential-save:disabled,#credential-github-signin:disabled{cursor:not-allowed;opacity:.5}' +
                '.credential-list-actions{white-space:nowrap}.credential-empty{padding:22px;text-align:center;color:var(--alt-text-color)}' +
                '#credential-github-signin{width:100%;margin-top:12px}' +
                '#credential-github-device{margin-top:12px;padding:14px 16px;color:var(--text-color);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px}' +
                '#credential-github-device p{margin:0 0 10px;color:var(--alt-text-color)}#credential-github-device p:last-child{margin:10px 0 0}#credential-github-code{color:var(--brand-orange);font-size:1.3rem;letter-spacing:0}#credential-github-link{margin-left:12px}' +
                '#credential-oauth-connected{margin-top:12px;padding:10px 12px;color:var(--status-success);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px}#credential-oauth-connected i{margin-right:8px}' +
                '#credential-modal-error{margin-top:12px;padding:10px 12px;border-radius:6px}' +
                '@media(max-width:600px){.credential-modal-backdrop{padding:10px}.credential-modal{width:100%;max-height:calc(100vh - 20px);padding:20px}.credential-modal-header{margin:-20px -20px 16px}.credential-modal-actions{margin:20px -20px -20px}.credential-modal-actions button{padding:10px 16px}}' +
            '</style>');
        }
        $('.credential-close,.credential-cancel').on('click', closeModal);
        $('#credential-provider').on('change', applyProviderDefaults);
        $('.credential-save').on('click', saveCredential);
        $('#credential-github-signin').on('click', startGitHubSignIn);
    }

    function applyProviderDefaults() {
        var provider = $('#credential-provider').val();
        var isOAuthGitHub = provider === 'github' && activeCredential.authMethod === 'oauth_device';
        var isNewGitHub = provider === 'github' && !$('#credential-id').val();
        $('#credential-github-signin').toggle(isNewGitHub);
        $('#credential-github-device').hide();
        $('#credential-oauth-connected').toggle(isOAuthGitHub);
        $('#credential-details').toggle(!isNewGitHub);
        $('#credential-secret-wrap').toggle(!isNewGitHub && !isOAuthGitHub);
        $('.credential-save').toggle(!isNewGitHub && !isOAuthGitHub);
        $('.credential-cancel').text(isOAuthGitHub ? 'Close' : 'Cancel');
        $('#credential-provider').prop('disabled', isOAuthGitHub);
        $('#credential-name,#credential-username').prop('readonly', isOAuthGitHub);
        if (provider === 'github') $('#credential-registry').val('ghcr.io').prop('readonly', true);
        else if (provider === 'docker') $('#credential-registry').val('docker.io').prop('readonly', true);
        else $('#credential-registry').prop('readonly', false);
    }

    function closeModal() {
        if (githubPollTimer) window.clearTimeout(githubPollTimer);
        githubPollTimer = null;
        $('#compose-credential-modal').hide();
    }

    function startGitHubSignIn() {
        var $button = $('#credential-github-signin').prop('disabled', true);
        $('#credential-modal-error').hide();
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'startGitHubDeviceAuth' }).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            if (response.result !== 'success') {
                $('#credential-modal-error').text(response.message || 'Unable to start GitHub sign-in.').show();
                $button.prop('disabled', false);
                return;
            }
            var device = response.device;
            $('#credential-github-code').text(device.userCode);
            $('#credential-github-link').attr('href', device.verificationUri);
            $('#credential-github-device').show();
            window.open(device.verificationUri, '_blank', 'noopener');
            pollGitHubSignIn(device.state, device.interval || 5);
        }).fail(function() {
            $('#credential-modal-error').text('Unable to reach GitHub sign-in service.').show();
            $button.prop('disabled', false);
        });
    }

    function pollGitHubSignIn(state, interval) {
        githubPollTimer = window.setTimeout(function() {
            $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'pollGitHubDeviceAuth', state: state }).done(function(data) {
                var response;
                try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
                if (response.result !== 'success') {
                    $('#credential-github-status').text(response.message || 'GitHub sign-in failed.');
                    $('#credential-github-signin').prop('disabled', false);
                    return;
                }
                var auth = response.auth || {};
                if (auth.status === 'pending') {
                    pollGitHubSignIn(state, auth.interval || interval);
                    return;
                }
                if (auth.status === 'success') {
                    var callback = onSaved;
                    loadCredentials(function() {
                        if (callback) callback(auth.credential);
                        openModal(auth.credential, null);
                    });
                    return;
                }
                $('#credential-github-status').text(auth.status === 'denied' ? 'Authorization was denied.' : 'Authorization expired. Try again.');
                $('#credential-github-signin').prop('disabled', false);
            }).fail(function() {
                $('#credential-github-status').text('Unable to check authorization. Retrying...');
                pollGitHubSignIn(state, interval);
            });
        }, Math.max(5, interval) * 1000);
    }

    function openModal(credential, callback) {
        ensureModal();
        credential = credential || {};
        activeCredential = credential;
        onSaved = callback || null;
        $('#credential-modal-title').text(credential.id ? 'Edit registry credential' : 'Add registry credential');
        $('#credential-id').val(credential.id || '');
        $('#credential-provider').val(credential.provider || 'github');
        $('#credential-name').val(credential.name || '');
        $('#credential-registry').val(credential.registry || '');
        $('#credential-username').val(credential.username || '');
        $('#credential-secret').val('');
        $('#credential-github-signin').prop('disabled', false);
        $('#credential-modal-error').hide().text('');
        applyProviderDefaults();
        if (credential.registry) $('#credential-registry').val(credential.registry);
        $('#compose-credential-modal').css('display', 'flex');
        $('#credential-name').trigger('focus');
    }

    function saveCredential() {
        var $button = $('.credential-save').prop('disabled', true);
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', {
            action: 'saveCredential', id: $('#credential-id').val(), name: $('#credential-name').val(),
            provider: $('#credential-provider').val(), registry: $('#credential-registry').val(),
            username: $('#credential-username').val(), secret: $('#credential-secret').val()
        }).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            if (response.result !== 'success') {
                $('#credential-modal-error').text(response.message || 'Unable to save credential.').show();
                return;
            }
            closeModal();
            loadCredentials(function() { if (onSaved) onSaved(response.credential); });
        }).fail(function() {
            $('#credential-modal-error').text('Unable to reach credential service.').show();
        }).always(function() { $button.prop('disabled', false); });
    }

    function loadCredentials(callback) {
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'listCredentials' }).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            credentials = response.result === 'success' ? (response.credentials || []) : [];
            renderTable();
            $(document).trigger('compose:credentials-loaded', [credentials]);
            if (callback) callback(credentials);
        });
    }

    function renderTable() {
        var $body = $('#credentials-tbody');
        if (!$body.length) return;
        $body.empty();
        if (!credentials.length) {
            $body.append('<tr><td colspan="6" class="credential-empty">No registry credentials saved.</td></tr>');
            return;
        }
        credentials.forEach(function(credential) {
            var $row = $('<tr>');
            $row.append($('<td>').text(credential.name));
            $row.append($('<td>').text(credential.provider));
            $row.append($('<td>').text(credential.registry));
            $row.append($('<td>').text(credential.username));
            $row.append($('<td>').text((credential.stacks || []).join(', ') || 'Not assigned'));
            var $actions = $('<td class="credential-list-actions">');
            $('<button type="button" title="Edit"><i class="fa fa-pencil"></i></button>').on('click', function() { openModal(credential); }).appendTo($actions);
            $('<button type="button" title="Delete"><i class="fa fa-trash"></i></button>').on('click', function() { deleteCredential(credential); }).appendTo($actions);
            $row.append($actions).appendTo($body);
        });
    }

    function deleteCredential(credential) {
        if ((credential.stacks || []).length) {
            swal({ title: 'Credential is in use', text: 'Remove it from: ' + credential.stacks.join(', '), type: 'warning' });
            return;
        }
        swal({ title: 'Delete credential?', text: credential.name, type: 'warning', showCancelButton: true }, function(confirmed) {
            if (!confirmed) return;
            $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'deleteCredential', id: credential.id }).done(loadCredentials);
        });
    }

    function populateSelect($select, selectedId) {
        $select.empty().append($('<option value="">').text('Anonymous / no credential'));
        credentials.forEach(function(credential) {
            $select.append($('<option>').val(credential.id).text(credential.name + ' (' + credential.registry + ' / ' + credential.username + ')'));
        });
        $select.val(selectedId || '');
        if (selectedId && $select.val() !== selectedId) {
            $select.append($('<option>').val(selectedId).text('Missing credential').prop('disabled', true)).val(selectedId);
        }
    }

    window.ComposeCredentialManager = { open: openModal, load: loadCredentials, populateSelect: populateSelect };
    $(function() {
        ensureModal();
        $('#add-credential-button').on('click', function() { openModal(); });
    });
})(window, jQuery);