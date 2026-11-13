function composeUpdatePresentation(container) {
    if (container.oneOff) {
        return { label: 'Job', icon: 'clock-o', color: 'grey-text', detail: 'One-off Compose jobs are not update targets.' };
    }
    if (container.isPinned) {
        return { label: 'Pinned', icon: 'thumb-tack', color: 'cyan-text', detail: 'Image is pinned to a digest.' };
    }
    var states = {
        'current': ['Current', 'check', 'green-text', 'Container matches the checked target.'],
        'download-required': ['Available', 'cloud-download', 'orange-text', 'A newer image is available remotely and must be downloaded.'],
        'ready-to-apply': ['Downloaded', 'hdd-o', 'orange-text', 'The checked replacement application image is local, but this container has not been recreated with it.'],
        'rebuild-required': ['Rebuild', 'wrench', 'orange-text', 'The application must be rebuilt with the newer base image. Downloading the base alone is not enough.'],
        'unknown': ['Unchecked', 'question-circle', 'grey-text', 'Readiness is unknown. Run Check for Updates for fresh evidence.']
    };
    var state = container.updateReadiness || 'unknown';
    if (!container.updateReadiness && container.updateStatus === 'up-to-date') state = 'current';
    var value = Object.prototype.hasOwnProperty.call(states, state) ? states[state] : states.unknown;
    if (state === 'unknown' && container.hasUpdate) {
        value = ['Pending', 'flash', 'orange-text', 'An update was detected, but download/build readiness has not been verified.'];
    }
    if (state === 'current' && container.updateKind === 'build-base') {
        value = ['Current', 'check', 'green-text', 'The application includes the checked base layers. Local source changes are not checked.'];
    }
    return { label: value[0], icon: value[1], color: value[2], detail: value[3] };
}

function composeUpdateBadge(container) {
    return composeUpdatePresentationBadge(composeUpdatePresentation(container));
}

function composeUpdatePresentationBadge(presentation) {
    return '<span class="' + presentation.color + '" style="white-space:nowrap;" title="' +
        presentation.detail + '" aria-label="' + presentation.label + ': ' + presentation.detail +
        '"><i class="fa fa-' + presentation.icon + ' fa-fw" aria-hidden="true"></i> ' + presentation.label + '</span>';
}

function composeStackUpdatePresentation(containers) {
    containers = (containers || []).filter(function(container) { return !container.oneOff; });
    var counts = {};
    var pending = [];
    containers.forEach(function(container) {
        var presentation = composeUpdatePresentation(container);
        counts[presentation.label] = (counts[presentation.label] || 0) + 1;
        if (container.hasUpdate && !container.isPinned) pending.push(presentation);
    });
    var detail = Object.keys(counts).map(function(label) {
        return counts[label] + ' ' + label.toLowerCase();
    }).join('; ');
    var result;
    if (pending.length === 1) {
        result = Object.assign({}, pending[0]);
    } else if (pending.length > 1) {
        result = { label: pending.length + ' updates', icon: 'flash', color: 'orange-text' };
    } else if (counts.Unchecked || containers.length === 0) {
        result = { label: counts.Unchecked ? counts.Unchecked + ' unchecked' : 'Unchecked', icon: 'question-circle', color: 'grey-text' };
    } else if (counts.Pinned === containers.length) {
        result = { label: 'All pinned', icon: 'thumb-tack', color: 'cyan-text' };
    } else {
        result = { label: 'Current', icon: 'check', color: 'green-text' };
    }
    result.detail = detail || 'No checked containers.';
    result.hasUpdate = pending.length > 0;
    result.needsCheck = !!counts.Unchecked || containers.length === 0;
    return result;
}
