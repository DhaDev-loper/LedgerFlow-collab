const ACCOUNTING_PERIOD_API = 'routes/periods.php';

function escapePeriodHtml(value) {
    const element = document.createElement('span');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
}

function periodLabel(period) {
    if (period.period_type === 'month') {
        const [year, month] = period.period_start.split('-').map(Number);
        return new Date(year, month - 1, 1).toLocaleDateString(undefined, {
            month: 'long',
            year: 'numeric',
        });
    }
    return period.period_start.slice(0, 4);
}

async function loadClosedPeriods() {
    const body = document.getElementById('closedPeriodsBody');
    const columnCount = window.ACCOUNTING_PERIOD_ROLE === 'owner' ? 5 : 4;
    body.innerHTML = `<tr><td colspan="${columnCount}" class="text-center py-4">Loading periods...</td></tr>`;

    try {
        const response = await fetch(`${ACCOUNTING_PERIOD_API}?action=list`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not load accounting periods.');
        }

        if (!data.periods.length) {
            body.innerHTML = `<tr><td colspan="${window.ACCOUNTING_PERIOD_ROLE === 'owner' ? 5 : 4}" class="text-center py-4" style="color: var(--dark-text-muted);">No periods are currently closed.</td></tr>`;
            return;
        }

        body.innerHTML = data.periods.map(period => `
            <tr>
                <td><span class="badge bg-warning text-dark">${escapePeriodHtml(period.period_type)}</span> ${escapePeriodHtml(periodLabel(period))}</td>
                <td>${escapePeriodHtml(period.closed_by_name)}</td>
                <td>${escapePeriodHtml(period.closed_at)}</td>
                <td>${escapePeriodHtml(period.close_reason)}</td>
                ${window.ACCOUNTING_PERIOD_ROLE === 'owner' ? `
                    <td>
                        <button class="quick-action-btn danger" type="button"
                            data-period-type="${escapePeriodHtml(period.period_type)}"
                            data-period-value="${period.period_type === 'month' ? escapePeriodHtml(period.period_start.slice(0, 7)) : escapePeriodHtml(period.period_start.slice(0, 4))}"
                            data-period-label="${escapePeriodHtml(periodLabel(period))}">
                            <i class="bi bi-unlock me-1"></i> Reopen
                        </button>
                    </td>
                ` : ''}
            </tr>
        `).join('');
    } catch (error) {
        console.error('Loading accounting periods failed:', error);
        body.innerHTML = `<tr><td colspan="${columnCount}" class="text-center py-4 text-danger">Could not load accounting periods. Please refresh.</td></tr>`;
    }
}

document.getElementById('periodType').addEventListener('change', event => {
    const isMonth = event.target.value === 'month';
    document.getElementById('monthValueGroup').classList.toggle('d-none', !isMonth);
    document.getElementById('monthValue').required = isMonth;
    document.getElementById('yearValueGroup').classList.toggle('d-none', isMonth);
    document.getElementById('yearValue').required = !isMonth;
});

document.getElementById('closePeriodForm').addEventListener('submit', async event => {
    event.preventDefault();
    const message = document.getElementById('periodFormMessage');
    const button = document.getElementById('closePeriodBtn');
    const periodType = document.getElementById('periodType').value;
    const periodValue = periodType === 'month'
        ? document.getElementById('monthValue').value
        : document.getElementById('yearValue').value;
    button.disabled = true;
    message.textContent = 'Closing period...';

    try {
        const response = await fetch(ACCOUNTING_PERIOD_API, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'close',
                period_type: periodType,
                period_value: periodValue,
                reason: document.getElementById('closeReason').value.trim(),
            }),
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not close the period.');
        }
        message.className = 'small text-success';
        message.textContent = data.message;
        document.getElementById('closeReason').value = '';
        await loadClosedPeriods();
    } catch (error) {
        message.className = 'small text-danger';
        message.textContent = error.message;
    } finally {
        button.disabled = false;
    }
});

document.getElementById('closedPeriodsBody').addEventListener('click', async event => {
    const button = event.target.closest('button[data-period-type]');
    if (!button) return;

    const reason = window.prompt(`Why are you reopening ${button.dataset.periodLabel}? This reason is recorded in the audit log.`);
    if (reason === null) return;
    if (!reason.trim()) {
        window.alert('A reopen reason is required.');
        return;
    }

    button.disabled = true;
    try {
        const response = await fetch(ACCOUNTING_PERIOD_API, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'reopen',
                period_type: button.dataset.periodType,
                period_value: button.dataset.periodValue,
                reason: reason.trim(),
            }),
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not reopen the period.');
        }
        await loadClosedPeriods();
    } catch (error) {
        window.alert(error.message);
        button.disabled = false;
    }
});

document.getElementById('refreshPeriodsBtn').addEventListener('click', loadClosedPeriods);

const currentMonth = new Date();
const previousMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
document.getElementById('monthValue').value = `${previousMonth.getFullYear()}-${String(previousMonth.getMonth() + 1).padStart(2, '0')}`;
document.getElementById('yearValue').value = currentMonth.getFullYear() - 1;
loadClosedPeriods();
