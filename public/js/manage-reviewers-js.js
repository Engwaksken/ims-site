(function () {
    const config = window.manageReviewersConfig || {};
    const currentTab = config.activeTab || 'assign';

    function switchTab(tabName) {
        document.querySelectorAll('.tab-btn').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.tab === tabName);
        });

        document.querySelectorAll('.tab-panel').forEach((panel) => {
            panel.classList.toggle('active', panel.id === `tab-${tabName}`);
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        if (tabName !== 'criteria') {
            url.searchParams.delete('edit_criteria');
        }
        if (tabName !== 'review_types') {
            url.searchParams.delete('edit_review_type');
        }
        window.history.replaceState({}, '', url.toString());
    }

    document.querySelectorAll('.tab-btn[data-tab]').forEach((btn) => {
        btn.addEventListener('click', function () {
            switchTab(this.dataset.tab);
        });
    });

    switchTab(currentTab);

    const flashAlert = document.getElementById('flashAlert');
    if (flashAlert) {
        setTimeout(() => {
            flashAlert.style.transition = 'opacity .3s ease, transform .3s ease';
            flashAlert.style.opacity = '0';
            flashAlert.style.transform = 'translateY(-8px)';
            setTimeout(() => flashAlert.remove(), 350);
        }, 4500);
    }

    const searchInput = document.getElementById('search-input');
    const statusFilter = document.getElementById('status-filter');
    const asgnTable = document.getElementById('asgn-table');
    const checkAll = document.getElementById('check-all');
    const selectOverdueBtn = document.getElementById('selectOverdueBtn');

    function filterAssignmentRows() {
        if (!asgnTable) return;

        const search = (searchInput?.value || '').trim().toLowerCase();
        const status = (statusFilter?.value || '').trim();

        asgnTable.querySelectorAll('tbody tr').forEach((row) => {
            const datasetSearch = (row.dataset.search || '').toLowerCase();
            const rowStatus = row.dataset.status || '';
            const isEmptyState = !row.dataset.status && !row.dataset.search;

            if (isEmptyState) {
                row.style.display = '';
                return;
            }

            const matchSearch = search === '' || datasetSearch.includes(search);
            const matchStatus = status === '' || rowStatus === status;

            row.style.display = matchSearch && matchStatus ? '' : 'none';
        });

        syncCheckAllState();
    }

    function visibleAssignmentCheckboxes() {
        if (!asgnTable) return [];
        return Array.from(asgnTable.querySelectorAll('tbody tr'))
            .filter((row) => row.style.display !== 'none')
            .flatMap((row) => Array.from(row.querySelectorAll('.asgn-check')));
    }

    function syncCheckAllState() {
        if (!checkAll) return;
        const boxes = visibleAssignmentCheckboxes();
        if (boxes.length === 0) {
            checkAll.checked = false;
            checkAll.indeterminate = false;
            return;
        }

        const checkedCount = boxes.filter((box) => box.checked).length;
        checkAll.checked = checkedCount === boxes.length;
        checkAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterAssignmentRows);
    }
    if (statusFilter) {
        statusFilter.addEventListener('change', filterAssignmentRows);
    }
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            visibleAssignmentCheckboxes().forEach((box) => {
                box.checked = this.checked;
            });
            syncCheckAllState();
        });
    }
    if (asgnTable) {
        asgnTable.addEventListener('change', function (e) {
            if (e.target.classList.contains('asgn-check')) {
                syncCheckAllState();
            }
        });
    }
    if (selectOverdueBtn) {
        selectOverdueBtn.addEventListener('click', function () {
            if (!asgnTable) return;
            asgnTable.querySelectorAll('.asgn-check[data-overdue="1"]').forEach((box) => {
                const row = box.closest('tr');
                if (row && row.style.display !== 'none') {
                    box.checked = true;
                }
            });
            syncCheckAllState();
        });
    }

    filterAssignmentRows();

    const rtSearch = document.getElementById('rt-search');
    const rtBody = document.getElementById('rt-tbody');
    if (rtSearch && rtBody) {
        rtSearch.addEventListener('input', function () {
            const term = this.value.trim().toLowerCase();
            rtBody.querySelectorAll('tr').forEach((row) => {
                const hay = (row.dataset.rtName || '').toLowerCase();
                row.style.display = term === '' || hay.includes(term) ? '' : 'none';
            });
        });
    }

    const categoryBlocks = document.getElementById('categoryBlocks');
    const addCategoryBtn = document.getElementById('addCategoryBtn');

    if (categoryBlocks && addCategoryBtn) {
        let categoryIndex = 0;

        function questionTemplate(catIndex, qIndex) {
            return `
                <div class="question-item" data-question-index="${qIndex}">
                    <div class="question-head">
                        <div class="question-title">
                            <i class="fas fa-question-circle"></i> Question ${qIndex + 1}
                        </div>
                        <div class="question-score-badge">
                            <i class="fas fa-star"></i>
                            Score: <span class="question-score-preview">0</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="field-label">Question / Criterion <span class="req">*</span></label>
                        <textarea
                            name="categories[${catIndex}][questions][${qIndex}][question]"
                            class="form-control"
                            rows="2"
                            required
                            placeholder="Enter the question reviewers will score..."
                        ></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="field-label">Question Score <span class="req">*</span></label>
                            <input
                                type="number"
                                name="categories[${catIndex}][questions][${qIndex}][max_score]"
                                class="form-control question-score-input"
                                min="0"
                                step="0.01"
                                value="0"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="field-label">Weight</label>
                            <input
                                type="number"
                                name="categories[${catIndex}][questions][${qIndex}][weight]"
                                class="form-control"
                                min="0.1"
                                max="100"
                                step="0.1"
                                value="1"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="field-label">Description / Scoring Guide</label>
                        <textarea
                            name="categories[${catIndex}][questions][${qIndex}][description]"
                            class="form-control"
                            rows="3"
                            placeholder="Explain how reviewers should score this question."
                        ></textarea>
                    </div>

                    <div class="question-actions">
                        <button type="button" class="btn btn-outline btn-sm removeQuestionBtn">
                            <i class="fas fa-trash"></i> Remove Question
                        </button>
                    </div>
                </div>
            `;
        }

        function categoryTemplate(catIndex) {
            return `
                <div class="category-block" data-category-index="${catIndex}">
                    <div class="category-head">
                        <div class="category-title-wrap">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="field-label">Criteria Category <span class="req">*</span></label>
                                <input
                                    type="text"
                                    name="categories[${catIndex}][category]"
                                    class="form-control"
                                    list="cat-suggestions"
                                    required
                                    placeholder="e.g. Innovation, Team, Impact"
                                >
                            </div>
                        </div>

                        <div class="category-total-box">
                            <span class="lbl">Category Total Score</span>
                            <span class="val category-total-preview">0</span>
                        </div>
                    </div>

                    <div class="questions-wrap"></div>

                    <div class="category-actions">
                        <button type="button" class="btn btn-outline btn-sm addQuestionBtn">
                            <i class="fas fa-plus"></i> Add Question
                        </button>
                        <button type="button" class="btn btn-danger btn-sm removeCategoryBtn">
                            <i class="fas fa-trash"></i> Remove Category
                        </button>
                    </div>
                </div>
            `;
        }

        function renumberQuestions(categoryBlock) {
            const questionItems = categoryBlock.querySelectorAll('.question-item');
            questionItems.forEach((item, idx) => {
                const title = item.querySelector('.question-title');
                if (title) {
                    title.innerHTML = `<i class="fas fa-question-circle"></i> Question ${idx + 1}`;
                }
            });
        }

        function updateCategoryTotal(categoryBlock) {
            let total = 0;
            categoryBlock.querySelectorAll('.question-score-input').forEach((input) => {
                const value = parseFloat(input.value || '0');
                total += Number.isNaN(value) ? 0 : value;

                const questionItem = input.closest('.question-item');
                const preview = questionItem ? questionItem.querySelector('.question-score-preview') : null;
                if (preview) {
                    preview.textContent = Number.isNaN(value) ? '0' : (Number.isInteger(value) ? String(value) : value.toFixed(2));
                }
            });

            const totalPreview = categoryBlock.querySelector('.category-total-preview');
            if (totalPreview) {
                totalPreview.textContent = Number.isInteger(total) ? String(total) : total.toFixed(2);
            }
        }

        function addQuestion(categoryBlock) {
            const catIndex = parseInt(categoryBlock.dataset.categoryIndex, 10);
            const questionsWrap = categoryBlock.querySelector('.questions-wrap');
            const qIndex = questionsWrap.querySelectorAll('.question-item').length;
            questionsWrap.insertAdjacentHTML('beforeend', questionTemplate(catIndex, qIndex));
            renumberQuestions(categoryBlock);
            updateCategoryTotal(categoryBlock);
        }

        function addCategory() {
            const catIndex = categoryIndex++;
            categoryBlocks.insertAdjacentHTML('beforeend', categoryTemplate(catIndex));
            const newBlock = categoryBlocks.querySelector(`.category-block[data-category-index="${catIndex}"]`);
            addQuestion(newBlock);
        }

        addCategoryBtn.addEventListener('click', addCategory);

        categoryBlocks.addEventListener('click', function (e) {
            const addQuestionBtn = e.target.closest('.addQuestionBtn');
            const removeQuestionBtn = e.target.closest('.removeQuestionBtn');
            const removeCategoryBtn = e.target.closest('.removeCategoryBtn');

            if (addQuestionBtn) {
                const categoryBlock = addQuestionBtn.closest('.category-block');
                addQuestion(categoryBlock);
            }

            if (removeQuestionBtn) {
                const categoryBlock = removeQuestionBtn.closest('.category-block');
                const questionItem = removeQuestionBtn.closest('.question-item');
                if (questionItem) {
                    questionItem.remove();
                    renumberQuestions(categoryBlock);
                    updateCategoryTotal(categoryBlock);
                }
            }

            if (removeCategoryBtn) {
                const categoryBlock = removeCategoryBtn.closest('.category-block');
                if (categoryBlock) {
                    categoryBlock.remove();
                }
            }
        });

        categoryBlocks.addEventListener('input', function (e) {
            if (e.target.classList.contains('question-score-input')) {
                const categoryBlock = e.target.closest('.category-block');
                if (categoryBlock) {
                    updateCategoryTotal(categoryBlock);
                }
            }
        });

        addCategory();
    }
})();