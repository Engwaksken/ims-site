let step = 1;
const STEPS = 3;
let memberSearchTimer = null;
let currentMemberRequest = 0;

function get(id) {
    return document.getElementById(id);
}

function qs(selector) {
    return document.querySelector(selector);
}

function qsa(selector) {
    return document.querySelectorAll(selector);
}

function syncUI() {
    for (let s = 1; s <= STEPS; s++) {
        const sep = get('sep-' + s);
        const nav = get('nav-' + s);
        const visible = (s === step);

        if (sep) sep.style.display = visible ? '' : 'none';
        if (nav) nav.style.display = visible ? '' : 'none';
    }
}

function clearErrors() {
    qsa('.field-err').forEach(el => el.remove());

    qsa(`#panel-${step} input, #panel-${step} select, #panel-${step} textarea`).forEach(el => {
        el.style.borderColor = '';
        el.style.boxShadow = '';
    });
}

function err(el, msg) {
    if (!el) return;

    el.style.borderColor = 'var(--danger)';
    el.style.boxShadow = '0 0 0 3px rgba(231,76,60,.14)';

    const wrap = el.closest('.field') || el.parentElement;
    if (wrap) {
        const div = document.createElement('div');
        div.className = 'field-err';
        div.innerHTML = `<i class="fas fa-circle-exclamation"></i>${escapeHtml(msg)}`;
        wrap.appendChild(div);
    }

    try {
        el.focus();
    } catch (e) {}
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function (m) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[m];
    });
}

function attrEncode(str) {
    return escapeHtml(str).replace(/"/g, '&quot;');
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email).trim());
}

function setMemberUserId(value) {
    const input = get('member_user_id');
    if (input) input.value = value ? String(value) : '';
}

function clearMemberSelection({ clearIdentityFields = true } = {}) {
    setMemberUserId('');

    const search = get('member_search');
    const results = get('memberResults');
    const selected = get('selectedMemberBox');
    const name = get('f_name');
    const phone = get('f_phone');
    const email = get('f_email');

    if (search) search.value = '';
    if (results) {
        results.classList.remove('show');
        results.innerHTML = '';
    }
    if (selected) {
        selected.classList.remove('show');
        selected.innerHTML = '';
    }

    if (clearIdentityFields) {
        if (name) name.value = '';
        if (phone) phone.value = '';
        if (email) email.value = '';
    }
}

function validate(s) {
    clearErrors();
    let ok = true;

    if (s === 1) {
        const isMember = get('f_is_member');
        const isFirstTime = get('f_is_first_time');
        const nationality = get('f_nationality');
        const name = get('f_name');
        const email = get('f_email');
        const branch = get('f_branch');
        const gender = get('f_gender');
        const memberUserId = get('member_user_id');
        const memberSearch = get('member_search');
        const isPwd = get('f_is_pwd');
        const pwdType = get('f_pwd_type');
        const pwdOther = get('f_pwd_other');

        if (!isMember || !/^[01]$/.test(isMember.value)) {
            err(isMember, 'Please select whether this visitor is a member.');
            ok = false;
        }

        if (isMember && isMember.value === '1') {
            if (!memberUserId || !memberUserId.value || parseInt(memberUserId.value, 10) <= 0) {
                err(memberSearch || memberUserId, 'Please search and select a member.');
                ok = false;
            }
        } else {
            if (!isFirstTime || !/^[01]$/.test(isFirstTime.value)) {
                err(isFirstTime, 'Please select whether this is the first visit.');
                ok = false;
            }

            if (!name || !name.value.trim()) {
                err(name, 'Your full name is required.');
                ok = false;
            }
        }

        if (email && email.value.trim() && !isValidEmail(email.value.trim())) {
            err(email, 'Please enter a valid email address.');
            ok = false;
        }

        if (!nationality || !nationality.value) {
            err(nationality, 'Please select nationality.');
            ok = false;
        }

        if (!branch || !branch.value) {
            err(branch, 'Please select a branch.');
            ok = false;
        }

        if (!gender || !gender.value) {
            err(gender, 'Please select gender.');
            ok = false;
        }

        if (isPwd && isPwd.checked) {
            if (!pwdType || !pwdType.value) {
                err(pwdType, 'Please select type of PWD.');
                ok = false;
            } else if (pwdType.value === 'Other' && (!pwdOther || !pwdOther.value.trim())) {
                err(pwdOther, 'Please specify the other type of PWD.');
                ok = false;
            }
        }
    }

    if (s === 2) {
        const date = get('f_date');
        const purpose = get('f_purpose');

        if (!date || !date.value) {
            err(date, 'Visit date is required.');
            ok = false;
        } else {
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const selectedDate = new Date(date.value + 'T00:00:00');
            if (selectedDate > today) {
                err(date, 'Visit date cannot be in the future.');
                ok = false;
            }
        }

        if (!purpose || !purpose.value) {
            err(purpose, 'Please select the purpose of your visit.');
            ok = false;
        }
    }

    return ok;
}

function goStep(next, back = false) {
    if (next < 1 || next > STEPS) return;
    if (!back && !validate(step)) return;

    const prev = step;
    step = next;

    const prevItem = get('si-' + prev);
    if (prevItem) {
        prevItem.classList.remove('active');
        if (back) {
            prevItem.classList.remove('done');
        } else {
            prevItem.classList.add('done');
        }
    }

    const lineIdx = back ? next : prev;
    const line = get('sl-' + lineIdx);
    if (line) {
        line.classList.toggle('filled', !back);
    }

    const nextItem = get('si-' + step);
    if (nextItem) {
        nextItem.classList.add('active');
        nextItem.classList.remove('done');
    }

    qsa('.step-panel').forEach(panel => {
        panel.classList.remove('active', 'go-back');
    });

    const panel = get('panel-' + step);
    if (panel) {
        panel.classList.add('active');
        if (back) panel.classList.add('go-back');
    }

    syncUI();

    if (step === 3) {
        buildReview();
    }

    const card = get('mainCard');
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function toggleMemberMode() {
    const isMember = get('f_is_member')?.value === '1';

    const memberWrap = get('memberSearchWrap');
    const firstVisitField = get('firstVisitField');
    const fullNameField = get('fullNameField');
    const fullNameInput = get('f_name');
    const firstVisitInput = get('f_is_first_time');

    if (isMember) {
        if (memberWrap) memberWrap.classList.add('show');
        if (firstVisitField) firstVisitField.hidden = true;
        if (fullNameField) fullNameField.hidden = false;

        if (fullNameInput) {
            fullNameInput.setAttribute('required', 'required');
            fullNameInput.readOnly = true;
        }

        if (firstVisitInput) firstVisitInput.value = '0';
    } else {
        if (memberWrap) memberWrap.classList.remove('show');
        if (firstVisitField) firstVisitField.hidden = false;
        if (fullNameField) fullNameField.hidden = false;

        if (fullNameInput) {
            fullNameInput.readOnly = false;
            fullNameInput.setAttribute('required', 'required');
        }

        clearMemberSelection({ clearIdentityFields: true });
    }
}

function togglePwdFields() {
    const isPwd = get('f_is_pwd')?.checked;
    const pwdTypeField = get('pwdTypeField');
    const pwdOtherField = get('pwdOtherField');
    const pwdType = get('f_pwd_type');
    const pwdOther = get('f_pwd_other');

    if (!pwdTypeField || !pwdOtherField || !pwdType) return;

    if (isPwd) {
        pwdTypeField.hidden = false;
    } else {
        pwdTypeField.hidden = true;
        pwdOtherField.hidden = true;
        pwdType.value = '';
        if (pwdOther) pwdOther.value = '';
        return;
    }

    if (pwdType.value === 'Other') {
        pwdOtherField.hidden = false;
    } else {
        pwdOtherField.hidden = true;
        if (pwdOther) pwdOther.value = '';
    }
}

function setupMemberSearch() {
    const memberType = get('f_is_member');
    const input = get('member_search');

    memberType?.addEventListener('change', () => {
        toggleMemberMode();
        clearErrors();
    });

    input?.addEventListener('input', function () {
        const q = this.value.trim();

        setMemberUserId('');
        const selectedBox = get('selectedMemberBox');
        if (selectedBox) {
            selectedBox.classList.remove('show');
            selectedBox.innerHTML = '';
        }

        const name = get('f_name');
        const phone = get('f_phone');
        const email = get('f_email');

        if (name) name.value = '';
        if (phone) phone.value = '';
        if (email) email.value = '';

        clearTimeout(memberSearchTimer);

        if (q.length < 2 || get('f_is_member')?.value !== '1') {
            const results = get('memberResults');
            if (results) {
                results.classList.remove('show');
                results.innerHTML = '';
            }
            return;
        }

        memberSearchTimer = setTimeout(() => searchMembers(q), 300);
    });
}

async function searchMembers(q) {
    const resultsBox = get('memberResults');
    if (!resultsBox) return;

    const requestId = ++currentMemberRequest;

    resultsBox.innerHTML = `<div class="search-item"><small><i class="fas fa-spinner fa-spin"></i> Searching members...</small></div>`;
    resultsBox.classList.add('show');

    try {
        const url = `?ajax=member_search&q=${encodeURIComponent(q)}`;
        const res = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!res.ok) {
            throw new Error('Request failed');
        }

        const data = await res.json();

        if (requestId !== currentMemberRequest) return;

        if (!data.success) {
            resultsBox.innerHTML = `<div class="search-item"><small>Unable to search members.</small></div>`;
            return;
        }

        const members = Array.isArray(data.members) ? data.members : [];

        if (!members.length) {
            resultsBox.innerHTML = `<div class="search-item"><small>No matching members found.</small></div>`;
            return;
        }

        resultsBox.innerHTML = members.map(member => {
            const id = Number(member.id || member.user_id || 0);
            const safeName = escapeHtml(member.name || member.full_name || '');
            const safeEmail = escapeHtml(member.email || '');
            const safePhone = escapeHtml(member.phone || '');
            const safeUsername = escapeHtml(member.username || '');

            return `
                <div class="search-item"
                     data-id="${id}"
                     data-name="${attrEncode(member.name || member.full_name || '')}"
                     data-email="${attrEncode(member.email || '')}"
                     data-phone="${attrEncode(member.phone || '')}"
                     data-username="${attrEncode(member.username || '')}">
                    <strong>${safeName}</strong>
                    <small>${safeEmail || 'No email'}${safePhone ? ' � ' + safePhone : ''}${safeUsername ? ' � @' + safeUsername : ''}</small>
                </div>
            `;
        }).join('');

        resultsBox.querySelectorAll('.search-item[data-id]').forEach(item => {
            item.addEventListener('click', function () {
                selectMember({
                    id: this.dataset.id,
                    name: this.dataset.name,
                    email: this.dataset.email,
                    phone: this.dataset.phone,
                    username: this.dataset.username
                });
            });
        });
    } catch (error) {
        if (requestId !== currentMemberRequest) return;
        resultsBox.innerHTML = `<div class="search-item"><small>Search failed. Please try again.</small></div>`;
    }
}

function selectMember(member) {
    setMemberUserId(member.id || '');

    const name = get('f_name');
    const email = get('f_email');
    const phone = get('f_phone');
    const search = get('member_search');
    const box = get('selectedMemberBox');
    const results = get('memberResults');

    if (name) name.value = member.name || '';
    if (email) email.value = member.email || '';
    if (phone) phone.value = member.phone || '';
    if (search) search.value = member.name || '';

    if (box) {
        box.innerHTML = `
            <strong><i class="fas fa-circle-check"></i> Member selected:</strong>
            ${escapeHtml(member.name || '')}
            ${member.email ? ' � ' + escapeHtml(member.email) : ''}
            ${member.phone ? ' � ' + escapeHtml(member.phone) : ''}
        `;
        box.classList.add('show');
    }

    if (results) {
        results.classList.remove('show');
        results.innerHTML = '';
    }
}

function buildReview() {
    const name = get('f_name')?.value.trim() || '';
    const phone = get('f_phone')?.value.trim() || '';
    const email = get('f_email')?.value.trim() || '';
    const org = get('f_org')?.value.trim() || '';
    const branch = get('f_branch')?.value || '';
    const gender = get('f_gender')?.value || '';
    const nationality = get('f_nationality')?.value || '';
    const isPwdChecked = !!get('f_is_pwd')?.checked;
    const pwdType = get('f_pwd_type')?.value || '';
    const pwdOther = get('f_pwd_other')?.value.trim() || '';
    const isPwd = isPwdChecked
        ? ('PWD' + (pwdType ? ` (${pwdType === 'Other' ? pwdOther : pwdType})` : ''))
        : 'Not PWD';

    const isMember = get('f_is_member')?.value === '1';
    const memberText = isMember ? 'Member' : 'Non-member';
    const firstVisitText = isMember
        ? 'Returning Visitor'
        : (get('f_is_first_time')?.value === '1' ? 'First Visit' : 'Returning Visitor');

    const dateVal = get('f_date')?.value || '';
    const timeVal = get('f_time')?.value || '';
    const purpose = get('f_purpose')?.value || '';
    const host = get('f_host')?.value.trim() || '';

    if (get('rv-name')) get('rv-name').textContent = name || '-';
    if (get('rv-member-status')) get('rv-member-status').textContent = `${memberText} � ${firstVisitText}`;
    if (get('rv-contact')) get('rv-contact').textContent = [phone, email].filter(Boolean).join(' � ') || '-';
    if (get('rv-branch')) get('rv-branch').textContent = branch || '-';
    if (get('rv-profile')) get('rv-profile').textContent = [gender || '-', nationality || '-', isPwd].join(' � ');
    if (get('rv-purpose')) get('rv-purpose').textContent = purpose || '-';

    if (org) {
        if (get('rv-org')) get('rv-org').textContent = org;
        if (get('rvr-org')) get('rvr-org').style.display = '';
    } else {
        if (get('rvr-org')) get('rvr-org').style.display = 'none';
    }

    if (dateVal) {
        const d = new Date(dateVal + 'T00:00:00');
        let dt = d.toLocaleDateString('en-UG', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });

        if (timeVal) {
            const [h, m] = timeVal.split(':');
            const hour = parseInt(h, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            dt += ' � ' + ((hour % 12) || 12) + ':' + m + ' ' + ampm;
        }

        if (get('rv-dt')) get('rv-dt').textContent = dt;
    } else {
        if (get('rv-dt')) get('rv-dt').textContent = '-';
    }

    if (host) {
        if (get('rv-host')) get('rv-host').textContent = host;
        if (get('rvr-host')) get('rvr-host').style.display = '';
    } else {
        if (get('rvr-host')) get('rvr-host').style.display = 'none';
    }
}

window.addEventListener('DOMContentLoaded', () => {
    syncUI();
    setupMemberSearch();
    toggleMemberMode();
    togglePwdFields();

    const timeInput = get('f_time');
    if (timeInput && !timeInput.value) {
        const now = new Date();
        timeInput.value =
            String(now.getHours()).padStart(2, '0') +
            ':' +
            String(now.getMinutes()).padStart(2, '0');
    }

    get('f_is_pwd')?.addEventListener('change', togglePwdFields);
    get('f_pwd_type')?.addEventListener('change', togglePwdFields);

    if (window.VISITOR_REGISTER_CONFIG?.hasError) {
        const e = String(window.VISITOR_REGISTER_CONFIG.errorMessage || '');
        if (/purpose|date|visit/i.test(e)) {
            step = 2;
            qsa('.step-panel').forEach(panel => panel.classList.remove('active', 'go-back'));
            get('panel-2')?.classList.add('active');
            get('si-1')?.classList.add('done');
            get('si-2')?.classList.add('active');
            get('sl-1')?.classList.add('filled');
            syncUI();
        }
    }

    if (
        get('f_is_member')?.value === '1' &&
        parseInt(get('member_user_id')?.value || '0', 10) > 0 &&
        get('f_name')?.value.trim()
    ) {
        const box = get('selectedMemberBox');
        if (box) {
            box.innerHTML = `<strong><i class="fas fa-circle-check"></i> Member selected:</strong> ${escapeHtml(get('f_name').value.trim())}`;
            box.classList.add('show');
        }
    }

    get('regForm')?.addEventListener('submit', (e) => {
        if (!validate(1) || !validate(2)) {
            e.preventDefault();
            return;
        }

        const btn = get('submitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        }
    });
});

window.goStep = goStep;