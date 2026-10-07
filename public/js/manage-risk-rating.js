const LOGGED_IN_USER = window.LOGGED_IN_USER || { id: 0, name: '', role: '' };
const SIGNATURE_PERMISSIONS = window.SIGNATURE_PERMISSIONS || {};

let WF = { rrId: 0, appId: 0 };
const PRFX = ['prep', 'rev', 'app'];
const CV = {};
const DRAWN = {};
const UPLOADED = {};
const CLEARED = {};
const CANVAS_READY = {};
let CURRENT_STEP = 0;

document.addEventListener('DOMContentLoaded', () => {
    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.addEventListener('click', function (e) {
            if (e.target === e.currentTarget) closeDeleteModal();
        });
    }

    const wfModal = document.getElementById('wfModal');
    if (wfModal) {
        wfModal.addEventListener('click', function (e) {
            if (e.target === e.currentTarget) closeWf();
        });
    }
});

function canSignStep(prefix) {
    return !!(SIGNATURE_PERMISSIONS[prefix] && SIGNATURE_PERMISSIONS[prefix].allowed);
}

function allowedRolesText(prefix) {
    if (!SIGNATURE_PERMISSIONS[prefix] || !Array.isArray(SIGNATURE_PERMISSIONS[prefix].roles)) {
        return '';
    }
    return SIGNATURE_PERMISSIONS[prefix].roles.join(', ');
}

function confirmDelete(id, name) {
    const idEl = document.getElementById('deleteRrId');
    const nameEl = document.getElementById('deleteModalName');
    const modal = document.getElementById('deleteModal');

    if (idEl) idEl.value = id;
    if (nameEl) nameEl.textContent = name || '(unknown)';
    if (modal) modal.classList.add('show');
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) modal.classList.remove('show');
}

function setWfStatus(message, type = '') {
    const el = document.getElementById('wfStatus');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'wf-status' + (type ? (' ' + type) : '');
}

function openWf(data) {
    WF = {
        rrId: Number(data.rrId || 0),
        appId: Number(data.appId || 0)
    };

    const startupEl = document.getElementById('wfStartupName');
    if (startupEl) {
        startupEl.textContent = data.startupName || '-';
    }

    const sigMap = {
        prep: data.prepSig || '',
        rev: data.revSig || '',
        app: data.appSig || ''
    };

    PRFX.forEach((prefix, index) => {
        const nameEl = document.getElementById(prefix + 'CurrentUserName');
        const roleEl = document.querySelector('#wfPane' + index + ' .wf-current-user-role');
        const userIdInput = document.getElementById(prefix + 'UserId');
        const userNameInput = document.getElementById(prefix + 'UserName');
        const fileInput = document.getElementById(prefix + 'File');
        const preview = document.getElementById(prefix + 'UploadPreview');
        const canvas = document.getElementById(prefix + 'Canvas');

        if (nameEl) nameEl.textContent = LOGGED_IN_USER.name || 'Current User';
        if (roleEl) roleEl.textContent = LOGGED_IN_USER.role || '';

        if (userIdInput) userIdInput.value = canSignStep(prefix) ? String(LOGGED_IN_USER.id || '') : '';
        if (userNameInput) userNameInput.value = canSignStep(prefix) ? String(LOGGED_IN_USER.name || '') : '';

        populateSaved(
            prefix,
            canSignStep(prefix) ? LOGGED_IN_USER.id : '',
            canSignStep(prefix) ? LOGGED_IN_USER.name : '',
            sigMap[prefix]
        );

        DRAWN[prefix] = false;
        UPLOADED[prefix] = null;
        CLEARED[prefix] = false;

        if (fileInput) fileInput.value = '';
        if (preview) {
            preview.style.display = 'none';
            preview.removeAttribute('src');
        }

        if (canvas && canSignStep(prefix)) {
            initCanvas(prefix);
            clearCanvas(prefix, true);
        }
    });

    const comments = document.getElementById('reviewerComments');
    if (comments) comments.value = data.revComments || '';

    goStep(0);
    setWfStatus('', '');

    const modal = document.getElementById('wfModal');
    if (modal) modal.classList.add('show');
}

function closeWf() {
    const modal = document.getElementById('wfModal');
    if (modal) modal.classList.remove('show');
}

function goStep(step) {
    CURRENT_STEP = step;

    for (let i = 0; i < 3; i++) {
        const tab = document.getElementById('wfTab' + i);
        const pane = document.getElementById('wfPane' + i);

        if (tab) tab.classList.toggle('active', i === step);
        if (pane) pane.classList.toggle('active', i === step);
    }
}

function populateSaved(prefix, userId, userName, signatureSrc) {
    const wrap = document.getElementById(prefix + 'Saved');
    const img = document.getElementById(prefix + 'SavedImg');
    const name = document.getElementById(prefix + 'SavedName');

    if (!wrap || !img || !name) return;

    if (signatureSrc) {
        wrap.style.display = 'flex';
        img.src = signatureSrc;
        name.textContent = userName || 'Signed';
    } else {
        wrap.style.display = 'none';
        img.removeAttribute('src');
        name.textContent = '-';
    }
}

function clearSaved(prefix) {
    if (!canSignStep(prefix)) {
        setWfStatus(
            'You are not allowed to modify this signature section. Allowed roles: ' + allowedRolesText(prefix),
            'err'
        );
        return;
    }

    CLEARED[prefix] = true;
    DRAWN[prefix] = false;
    UPLOADED[prefix] = null;

    populateSaved(prefix, '', '', '');
    clearCanvas(prefix, true);

    const fileInput = document.getElementById(prefix + 'File');
    const preview = document.getElementById(prefix + 'UploadPreview');

    if (fileInput) fileInput.value = '';
    if (preview) {
        preview.style.display = 'none';
        preview.removeAttribute('src');
    }

    setWfStatus('', '');
}

function switchSigTab(prefix, tab, button) {
    if (!canSignStep(prefix)) return;

    const drawPane = document.getElementById(prefix + 'DrawPane');
    const uploadPane = document.getElementById(prefix + 'UploadPane');
    const tabsWrap = button ? button.closest('.sig-tabs') : null;
    const tabs = tabsWrap ? tabsWrap.querySelectorAll('.sig-tab') : [];

    tabs.forEach(btn => btn.classList.remove('active'));
    if (button) button.classList.add('active');

    if (drawPane) drawPane.classList.toggle('active', tab === 'draw');
    if (uploadPane) uploadPane.classList.toggle('active', tab === 'upload');
}

function initCanvas(prefix) {
    const canvas = document.getElementById(prefix + 'Canvas');
    const hint = document.getElementById(prefix + 'Hint');
    if (!canvas) return;

    if (!CV[prefix]) {
        const ctx = canvas.getContext('2d');
        CV[prefix] = { canvas, ctx, drawing: false };
    }

    resizeCanvas(prefix);

    if (CANVAS_READY[prefix]) {
        return;
    }

    const ctx = CV[prefix].ctx;

    const getPos = (event) => {
        const rect = canvas.getBoundingClientRect();
        if (event.touches && event.touches[0]) {
            return {
                x: event.touches[0].clientX - rect.left,
                y: event.touches[0].clientY - rect.top
            };
        }
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
        };
    };

    const start = (event) => {
        if (!canSignStep(prefix)) return;
        const pos = getPos(event);
        CV[prefix].drawing = true;
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
        if (hint) hint.style.display = 'none';
    };

    const move = (event) => {
        if (!CV[prefix].drawing) return;
        const pos = getPos(event);
        ctx.lineTo(pos.x, pos.y);
        ctx.strokeStyle = '#111827';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke();
        DRAWN[prefix] = true;
        CLEARED[prefix] = false;
    };

    const end = () => {
        CV[prefix].drawing = false;
    };

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', end);
    canvas.addEventListener('mouseleave', end);

    canvas.addEventListener('touchstart', (e) => {
        e.preventDefault();
        start(e);
    }, { passive: false });

    canvas.addEventListener('touchmove', (e) => {
        e.preventDefault();
        move(e);
    }, { passive: false });

    canvas.addEventListener('touchend', (e) => {
        e.preventDefault();
        end();
    }, { passive: false });

    CANVAS_READY[prefix] = true;
}

function resizeCanvas(prefix) {
    const cv = CV[prefix];
    if (!cv) return;

    const canvas = cv.canvas;
    const ratio = window.devicePixelRatio || 1;
    const width = canvas.offsetWidth || canvas.parentElement.offsetWidth || 400;
    const height = 120;

    const temp = document.createElement('canvas');
    temp.width = canvas.width;
    temp.height = canvas.height;

    const tempCtx = temp.getContext('2d');
    if (canvas.width > 0 && canvas.height > 0) {
        tempCtx.drawImage(canvas, 0, 0);
    }

    canvas.width = width * ratio;
    canvas.height = height * ratio;
    canvas.style.height = height + 'px';

    cv.ctx.setTransform(1, 0, 0, 1, 0, 0);
    cv.ctx.scale(ratio, ratio);

    if (temp.width > 0 && temp.height > 0) {
        cv.ctx.drawImage(temp, 0, 0, width, height);
    }
}

window.addEventListener('resize', function () {
    PRFX.forEach(prefix => {
        if (CV[prefix]) resizeCanvas(prefix);
    });
});

function clearCanvas(prefix, silent = false) {
    const cv = CV[prefix];
    if (!cv) return;

    const canvas = cv.canvas;
    cv.ctx.clearRect(0, 0, canvas.width, canvas.height);
    DRAWN[prefix] = false;

    const hint = document.getElementById(prefix + 'Hint');
    if (hint) hint.style.display = 'flex';

    if (!silent) setWfStatus('', '');
}

function handleUpload(prefix, input) {
    if (!canSignStep(prefix)) {
        if (input) input.value = '';
        setWfStatus(
            'You are not allowed to upload a signature here. Allowed roles: ' + allowedRolesText(prefix),
            'err'
        );
        return;
    }

    const file = input && input.files ? input.files[0] : null;
    if (!file) return;

    if (!/^image\//i.test(file.type)) {
        input.value = '';
        setWfStatus('Please upload an image file only.', 'err');
        return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
        const result = e.target && e.target.result ? e.target.result : '';
        if (!result) {
            setWfStatus('Failed to read uploaded signature image.', 'err');
            return;
        }

        UPLOADED[prefix] = result;
        CLEARED[prefix] = false;
        DRAWN[prefix] = false;

        const preview = document.getElementById(prefix + 'UploadPreview');
        if (preview) {
            preview.src = result;
            preview.style.display = 'block';
        }

        const hint = document.getElementById(prefix + 'Hint');
        if (hint) hint.style.display = 'none';

        populateSaved(prefix, LOGGED_IN_USER.id, LOGGED_IN_USER.name, result);
        setWfStatus('', '');
    };
    reader.readAsDataURL(file);
}

function getSignaturePacket(prefix) {
    if (!canSignStep(prefix)) {
        return {
            cleared: false,
            signature: null
        };
    }

    if (CLEARED[prefix]) {
        return {
            cleared: true,
            signature: ''
        };
    }

    if (UPLOADED[prefix]) {
        return {
            cleared: false,
            signature: UPLOADED[prefix]
        };
    }

    if (DRAWN[prefix] && CV[prefix] && CV[prefix].canvas) {
        return {
            cleared: false,
            signature: CV[prefix].canvas.toDataURL('image/png')
        };
    }

    return {
        cleared: false,
        signature: null
    };
}

async function saveWorkflow() {
    const saveBtn = document.getElementById('wfSaveBtn');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const prepPacket = getSignaturePacket('prep');
        const revPacket = getSignaturePacket('rev');
        const appPacket = getSignaturePacket('app');

        const payload = {
            rr_id: WF.rrId,
            application_id: WF.appId,
            reviewer_comments: document.getElementById('reviewerComments')
                ? document.getElementById('reviewerComments').value.trim()
                : ''
        };

        if (canSignStep('prep')) {
            payload.prep_by_user_id = LOGGED_IN_USER.id;
            payload.prep_by_name = LOGGED_IN_USER.name;
            payload.prep_by_signature = prepPacket.signature;
            payload.prep_clear = prepPacket.cleared ? 1 : 0;
        }

        if (canSignStep('rev')) {
            payload.rev_by_user_id = LOGGED_IN_USER.id;
            payload.rev_by_name = LOGGED_IN_USER.name;
            payload.rev_by_signature = revPacket.signature;
            payload.rev_clear = revPacket.cleared ? 1 : 0;
        }

        if (canSignStep('app')) {
            payload.app_by_user_id = LOGGED_IN_USER.id;
            payload.app_by_name = LOGGED_IN_USER.name;
            payload.app_by_signature = appPacket.signature;
            payload.app_clear = appPacket.cleared ? 1 : 0;
        }

        const response = await fetch('ajax/save_risk_rating_workflow.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        let result = null;
        try {
            result = await response.json();
        } catch (jsonError) {
            setWfStatus('Server returned an invalid response.', 'err');
            return;
        }

        if (!response.ok || !result || !result.success) {
            setWfStatus(
                (result && result.message) ? result.message : 'Failed to save workflow.',
                'err'
            );
            return;
        }

        setWfStatus(result.message || 'Workflow saved successfully.', 'ok');
        setTimeout(() => window.location.reload(), 700);
    } catch (error) {
        setWfStatus('An unexpected error occurred while saving.', 'err');
    } finally {
        if (saveBtn) saveBtn.disabled = false;
    }
}