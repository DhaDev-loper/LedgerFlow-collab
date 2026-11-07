const PROJECT_REPORT_URL = 'reports/project_pnl.php';
let projectStatementData = null;

function escapeProjectHtml(value) {
    const element = document.createElement('span');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
}

function formatProjectMoney(value) {
    const amount = Number(value) || 0;
    const sign = amount < 0 ? '-' : '';
    return `${sign}₱${Math.abs(amount).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function formatProjectDate(value, includeTime = false) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-PH', includeTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium' });
}

function renderProjectLines(elementId, lines, emptyText) {
    const tbody = document.getElementById(elementId);
    if (!lines.length) {
        tbody.innerHTML = `<tr><td colspan="2" class="statement-empty">${emptyText}</td></tr>`;
        return;
    }

    tbody.innerHTML = lines.map(line => `
        <tr>
            <td>${escapeProjectHtml(line.account_name)}</td>
            <td class="text-end">${formatProjectMoney(line.amount)}</td>
        </tr>
    `).join('');
}

function renderProjectStatement(data) {
    projectStatementData = data;
    const project = data.project;
    const client = project.company_name
        ? `${project.company_name} (${project.client_name})`
        : project.client_name;
    const profitable = Number(data.net_income) >= 0;

    document.title = `${project.project_name} P&L — ${project.business_name}`;
    document.getElementById('businessName').textContent = project.business_name;
    document.getElementById('projectTitle').textContent = project.project_name;
    document.getElementById('clientName').textContent = client;
    const projectStatus = document.getElementById('projectStatus');
    if (projectStatus) {
        projectStatus.textContent = project.status === 'archived' ? 'Completed' : 'Active';
        projectStatus.classList.toggle('archived', project.status === 'archived');
    }
    document.getElementById('projectCreatedAt').textContent = formatProjectDate(project.created_at);
    document.getElementById('generatedAt').textContent = formatProjectDate(data.generated_at, true);

    renderProjectLines('revenueLines', data.revenue_lines || [], 'No project revenue recorded.');
    renderProjectLines('expenseLines', data.expense_lines || [], 'No project expenses recorded.');
    document.getElementById('totalRevenue').textContent = formatProjectMoney(data.total_revenue);
    document.getElementById('totalExpense').textContent = formatProjectMoney(data.total_expense);
    document.getElementById('netIncome').textContent = formatProjectMoney(data.net_income);
    document.getElementById('netMargin').textContent = `${(Number(data.net_margin) || 0).toFixed(2)}%`;
    const projectStatement = document.getElementById('projectStatement');
    projectStatement?.classList.toggle('is-loss', !profitable);
    projectStatement?.classList.toggle('is-profit', profitable);

    const statusButton = document.getElementById('projectStatusButton');
    if (statusButton && window.CAN_MANAGE_PROJECTS) {
        statusButton.hidden = false;
        statusButton.textContent = project.status === 'active' ? 'Archive project' : 'Reactivate project';
        statusButton.onclick = updateProjectStatus;
    }
}

async function loadProjectStatement() {
    const errorBox = document.getElementById('projectReportError');
    if (!Number.isInteger(Number(window.PROJECT_ID)) || Number(window.PROJECT_ID) <= 0) {
        errorBox.textContent = 'A valid project ID is required.';
        errorBox.hidden = false;
        return;
    }

    try {
        const response = await fetch(`${PROJECT_REPORT_URL}?project_id=${encodeURIComponent(window.PROJECT_ID)}`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Unable to load the project report.');
        }
        renderProjectStatement(data);
    } catch (error) {
        errorBox.textContent = error.message || 'Could not reach the server.';
        errorBox.hidden = false;
        document.getElementById('projectStatement').hidden = true;
    }
}

async function updateProjectStatus() {
    if (!projectStatementData) return;
    const nextStatus = projectStatementData.project.status === 'active' ? 'archived' : 'active';
    const confirmed = window.confirm(`${nextStatus === 'archived' ? 'Archive' : 'Reactivate'} this project?`);
    if (!confirmed) return;

    const formData = new URLSearchParams({
        action: 'set-status',
        project_id: String(projectStatementData.project.id),
        status: nextStatus,
    });
    const button = document.getElementById('projectStatusButton');
    button.disabled = true;
    try {
        const response = await fetch(PROJECT_REPORT_URL, { method: 'POST', body: formData });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.error || 'Unable to update project status.');
        }
        projectStatementData.project.status = result.status;
        renderProjectStatement(projectStatementData);
    } catch (error) {
        window.alert(error.message || 'Unable to update project status.');
    } finally {
        button.disabled = false;
    }
}

loadProjectStatement();
