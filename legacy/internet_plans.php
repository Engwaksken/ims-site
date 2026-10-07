<?php
ob_start();

$page_title = 'Manage Internet Plans';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant']);

require_once 'includes/internet-plans.php';
?>

<style>
.internet-wrap{padding:25px}
.manage-header{background:linear-gradient(135deg,#ff9800,#ff5722);color:#fff;border-radius:18px;padding:24px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;gap:15px}
.manage-header h1{margin:0;font-size:26px}
.manage-header p{margin:6px 0 0;color:#fff;font-size:14px;opacity:.9}
.internet-card{background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 25px rgba(15,23,42,.08);border:1px solid #e2e8f0}
.internet-btn{border:0;border-radius:10px;padding:10px 13px;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:13px}
.internet-btn-primary{background:#ff9800;color:#fff}
.internet-btn-warning{background:#f59e0b;color:#fff}
.internet-btn-danger{background:#dc2626;color:#fff}
.internet-btn-success{background:#16a34a;color:#fff}
.internet-btn-light{background:#fff;color:#ff5722}
.internet-table-wrap{overflow:auto}
.internet-table{width:100%;border-collapse:collapse}
.internet-table th,.internet-table td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px;vertical-align:top}
.internet-table th{background:#f8fafc;font-size:13px;text-transform:uppercase;color:#475569}
.internet-badge{padding:5px 9px;border-radius:999px;font-size:12px;font-weight:bold;display:inline-block}
.internet-active{background:#dcfce7;color:#166534}
.internet-inactive{background:#fee2e2;color:#991b1b}
.internet-type{background:#dbeafe;color:#ff5722}
.internet-actions{display:flex;gap:6px;flex-wrap:wrap}
.internet-msg{padding:12px 14px;border-radius:12px;margin-bottom:15px}
.internet-success{background:#dcfce7;color:#166534}
.internet-error{background:#fee2e2;color:#991b1b}
.internet-price-old{text-decoration:line-through;color:#94a3b8;font-size:12px}
.internet-price-final{font-weight:bold;color:#16a34a}
.internet-small{font-size:12px;color:#64748b}
.internet-modal{display:none;position:fixed;z-index:9999;inset:0;background:rgba(15,23,42,.55);padding:20px;overflow:auto}
.internet-modal.show{display:block}
.internet-modal-card{background:#fff;max-width:680px;margin:40px auto;border-radius:18px;box-shadow:0 25px 80px rgba(0,0,0,.25);overflow:hidden}
.internet-modal-header{padding:18px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
.internet-modal-header h2{margin:0;font-size:20px}
.internet-modal-body{padding:22px}
.internet-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.internet-form-group{margin-bottom:14px}
.internet-form-group label{display:block;font-weight:bold;margin-bottom:6px;font-size:14px}
.internet-form-group input,.internet-form-group select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px}
.internet-form-group input:focus,.internet-form-group select:focus{outline:none;border-color:#ff9800}
.internet-full{grid-column:1/-1}
.internet-close{background:transparent;border:0;font-size:22px;cursor:pointer;color:#64748b}
@media(max-width:800px){
    .manage-header{display:block}
    .manage-header button,.manage-header a{margin-top:12px}
    .internet-form-grid{grid-template-columns:1fr}
}
</style>

<div class="internet-wrap">

    <div class="manage-header">
        <div>
            <h1><i class="fas fa-wifi"></i> Manage Internet Plans</h1>
            <p>Create plans, set discounts, bandwidth, and maximum devices per subscription.</p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="internet_subscriptions.php" class="internet-btn internet-btn-light">
                <i class="fas fa-list"></i> Subscriptions
            </a>

            <button type="button" class="internet-btn internet-btn-light" onclick="openPlanModal()">
                <i class="fas fa-plus"></i> Add Internet Plan
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="internet-msg internet-success">
            <i class="fas fa-circle-check"></i> <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="internet-msg internet-error">
            <i class="fas fa-triangle-exclamation"></i> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="internet-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;gap:10px;flex-wrap:wrap;">
            <h2 style="margin:0;">Internet Plans</h2>
            <input type="text" id="planSearch" placeholder="Search plans..."
                   style="padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;min-width:240px;">
        </div>

        <div class="internet-table-wrap">
            <table class="internet-table" id="plansTable">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Plan</th>
                    <th>Duration</th>
                    <th>Price</th>
                    <th>Discount</th>
                    <th>Final Price</th>
                    <th>Bandwidth</th>
                    <th>Devices</th>
                    <th>Status</th>
                    <th style="min-width:170px;">Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if ($plans && $plans->num_rows > 0): ?>
                    <?php $i = 1; while ($row = $plans->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>

                            <td>
                                <strong><?= h($row['plan_name']) ?></strong><br>
                                <span class="internet-badge internet-type">
                                    <?= h(strtoupper($row['duration_type'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format((int)$row['duration_minutes']) ?> mins<br>
                                <span class="internet-small">
                                    <?= round(((int)$row['duration_minutes']) / 1440, 2) ?> day(s)
                                </span>
                            </td>

                            <td>
                                <span class="<?= ((float)$row['discount_percent'] > 0) ? 'internet-price-old' : '' ?>">
                                    UGX <?= number_format((float)$row['price']) ?>
                                </span>
                            </td>

                            <td><?= number_format((float)$row['discount_percent'], 2) ?>%</td>

                            <td class="internet-price-final">
                                UGX <?= number_format((float)$row['final_price']) ?>
                            </td>

                            <td><?= h($row['bandwidth_limit'] ?: '-') ?></td>

                            <td>
                                <span class="internet-badge internet-type">
                                    <?= number_format((int)($row['device_limit'] ?? 1)) ?> device(s)
                                </span>
                            </td>

                            <td>
                                <span class="internet-badge <?= $row['status'] === 'active' ? 'internet-active' : 'internet-inactive' ?>">
                                    <?= h(strtoupper($row['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <div class="internet-actions">
                                    <button type="button"
                                            class="internet-btn internet-btn-warning"
                                            onclick='openPlanModal(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="internet-btn internet-btn-success" type="submit">
                                            <i class="fas fa-toggle-on"></i>
                                        </button>
                                    </form>

                                    <form method="post" onsubmit="return confirm('Delete this plan?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="internet-btn internet-btn-danger" type="submit">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10">No internet plans found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="internet-modal" id="planModal">
    <div class="internet-modal-card">
        <div class="internet-modal-header">
            <h2 id="modalTitle">Add Internet Plan</h2>
            <button type="button" class="internet-close" onclick="closePlanModal()">&times;</button>
        </div>

        <div class="internet-modal-body">
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="plan_id" value="0">

                <div class="internet-form-grid">
                    <div class="internet-form-group internet-full">
                        <label>Plan Name</label>
                        <input type="text" name="plan_name" id="plan_name" required placeholder="Daily Internet">
                    </div>

                    <div class="internet-form-group">
                        <label>Duration Type</label>
                        <select name="duration_type" id="duration_type" required>
                            <option value="">Select Type</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <div class="internet-form-group">
                        <label>Duration Minutes</label>
                        <input type="number" name="duration_minutes" id="duration_minutes" required min="1">
                        <div class="internet-small">Daily = 1440, Monthly = 43200, Annual = 525600</div>
                    </div>

                    <div class="internet-form-group">
                        <label>Price</label>
                        <input type="number" step="0.01" name="price" id="price" required min="0">
                    </div>

                    <div class="internet-form-group">
                        <label>Discount Percent</label>
                        <input type="number" step="0.01" name="discount_percent" id="discount_percent" min="0" max="100" value="0">
                    </div>

                    <div class="internet-form-group">
                        <label>Final Price Preview</label>
                        <input type="text" id="final_price_preview" readonly>
                    </div>

                    <div class="internet-form-group">
                        <label>Device Limit Per Subscription</label>
                        <input type="number" name="device_limit" id="device_limit" min="1" value="1" required>
                    </div>

                    <div class="internet-form-group">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="internet-form-group internet-full">
                        <label>Bandwidth Limit</label>
                        <input type="text" name="bandwidth_limit" id="bandwidth_limit" placeholder="Example: 5Mbps / Unlimited">
                    </div>
                </div>

                <button class="internet-btn internet-btn-primary" type="submit" style="width:100%;justify-content:center;margin-top:8px;">
                    <i class="fas fa-save"></i> Save Plan
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const modal = document.getElementById('planModal');
const modalTitle = document.getElementById('modalTitle');

const planId = document.getElementById('plan_id');
const planName = document.getElementById('plan_name');
const durationType = document.getElementById('duration_type');
const durationMinutes = document.getElementById('duration_minutes');
const price = document.getElementById('price');
const discount = document.getElementById('discount_percent');
const finalPreview = document.getElementById('final_price_preview');
const bandwidthLimit = document.getElementById('bandwidth_limit');
const deviceLimit = document.getElementById('device_limit');
const statusField = document.getElementById('status');

function openPlanModal(plan = null) {
    modal.classList.add('show');

    if (plan) {
        modalTitle.innerHTML = 'Edit Internet Plan';
        planId.value = plan.id || 0;
        planName.value = plan.plan_name || '';
        durationType.value = plan.duration_type || '';
        durationMinutes.value = plan.duration_minutes || '';
        price.value = plan.price || '';
        discount.value = plan.discount_percent || 0;
        bandwidthLimit.value = plan.bandwidth_limit || '';
        deviceLimit.value = plan.device_limit || 1;
        statusField.value = plan.status || 'active';
    } else {
        modalTitle.innerHTML = 'Add Internet Plan';
        planId.value = 0;
        planName.value = '';
        durationType.value = '';
        durationMinutes.value = '';
        price.value = '';
        discount.value = 0;
        bandwidthLimit.value = '';
        deviceLimit.value = 1;
        statusField.value = 'active';
    }

    calculateFinalPrice();
}

function closePlanModal() {
    modal.classList.remove('show');
}

function setDurationMinutes() {
    if (durationType.value === 'daily') {
        durationMinutes.value = 1440;
    } else if (durationType.value === 'monthly') {
        durationMinutes.value = 43200;
    } else if (durationType.value === 'annual') {
        durationMinutes.value = 525600;
    }

    calculateFinalPrice();
}

function calculateFinalPrice() {
    const p = parseFloat(price.value || 0);
    const d = parseFloat(discount.value || 0);
    const finalPrice = p - ((p * d) / 100);

    finalPreview.value = 'UGX ' + finalPrice.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

durationType.addEventListener('change', setDurationMinutes);
price.addEventListener('input', calculateFinalPrice);
discount.addEventListener('input', calculateFinalPrice);

window.addEventListener('click', function(e) {
    if (e.target === modal) {
        closePlanModal();
    }
});

document.getElementById('planSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#plansTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php
require_once 'includes/footer.php';
ob_end_flush();
?>