const API_URL = 'routes/journal.php';

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatMoney(amount) {
    const num = parseFloat(amount) || 0;
    const sign = num < 0 ? '-' : '';
    return sign + '₱' + Math.abs(num).toLocaleString(undefined, { minimumFractionDigits: 2 });
}

function renderBreakdown(containerId, items, color) {
    const container = document.getElementById(containerId);

    if (items.length === 0) {
        container.innerHTML = `<p class="text-center py-3" style="color: var(--dark-text-muted);">No data for this period.</p>`;
        return;
    }

    const maxTotal = Math.max(...items.map(i => i.total));

    container.innerHTML = items.map(item => `
        <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
                <span>${escapeHtml(item.category)}</span>
                <span class="money fw-semibold">${formatMoney(item.total)}</span>
            </div>
            <div class="aging-progress">
                <div style="background: ${color}; width: ${(item.total / maxTotal) * 100}%; height: 100%;"></div>
            </div>
        </div>
    `).join('');
}

async function loadCashflowReport() {
    const dateFrom = document.getElementById('cfDateFrom').value;
    const dateTo = document.getElementById('cfDateTo').value;

    try {
        const params = new URLSearchParams({ action: 'cashflow-report' });
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        const response = await fetch(`${API_URL}?${params.toString()}`);
        const data = await response.json();

        if (!data.success) return;

        document.getElementById('cfTotalInflow').textContent = formatMoney(data.total_inflow);
        document.getElementById('cfTotalOutflow').textContent = formatMoney(data.total_outflow);

        const netEl = document.getElementById('cfNetTotal');
        const isPositive = data.net_cash_flow >= 0;
        netEl.textContent = (isPositive ? '+' : '-') + formatMoney(Math.abs(data.net_cash_flow));
        netEl.classList.remove('positive', 'negative');
        netEl.classList.add(isPositive ? 'positive' : 'negative');

        renderBreakdown('cfInflowBreakdown', data.inflow_breakdown, '#4ADE80');
        renderBreakdown('cfOutflowBreakdown', data.outflow_breakdown, '#F87171');

        const ctx = document.getElementById('cfChart');
        const existingChart = Chart.getChart(ctx);
        if (existingChart) existingChart.destroy();

        const gridColor = 'rgba(255, 255, 255, 0.06)';
        const tickColor = 'rgba(241, 245, 249, 0.55)';

        const gradientIn = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
        gradientIn.addColorStop(0, 'rgba(46, 144, 250, 0.35)');
        gradientIn.addColorStop(1, 'rgba(46, 144, 250, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Cash In',
                        data: data.cash_in,
                        borderColor: '#2E90FA',
                        backgroundColor: gradientIn,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Cash Out',
                        data: data.cash_out,
                        borderColor: '#F87171',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2,
                        borderDash: [4, 4],
                    },
                ],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { boxWidth: 12, usePointStyle: true, color: tickColor, font: { family: 'Inter', size: 12 } },
                    },
                    tooltip: {
                        backgroundColor: '#14243F', borderColor: 'rgba(255,255,255,0.1)', borderWidth: 1,
                        titleColor: '#F1F5F9', bodyColor: '#F1F5F9',
                        callbacks: { label: (item) => `${item.dataset.label}: ₱${item.formattedValue}` },
                    },
                },
                scales: {
                    y: {
                        ticks: { color: tickColor, callback: (val) => '₱' + val.toLocaleString(), font: { family: 'IBM Plex Mono', size: 11 } },
                        grid: { color: gridColor },
                    },
                    x: { grid: { display: false }, ticks: { color: tickColor, font: { family: 'Inter', size: 11 } } },
                },
            },
        });

    } catch (err) {
        console.error('Failed to load cash flow report:', err);
    }
}

document.getElementById('cfGenerateBtn').addEventListener('click', loadCashflowReport);

document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('routes/auth.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'logout' }),
    });
    window.location.href = '../frontend/login.html';
});

// Default to this month
const today = new Date().toISOString().split('T')[0];
const firstOfMonth = new Date();
firstOfMonth.setDate(1);
document.getElementById('cfDateFrom').value = firstOfMonth.toISOString().split('T')[0];
document.getElementById('cfDateTo').value = today;

//-- journal entries navigation expandble
document.addEventListener('DOMContentLoaded', () => {
    const journalExpandable = document.getElementById('journalExpandable');
    const journalTrigger = document.getElementById('journalTrigger');

    journalTrigger.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        // Toggle active state to trigger expand animation
        journalExpandable.classList.toggle('active');
    });

    // Optional: Close expansion when clicking outside the nav
    document.addEventListener('click', (e) => {
        if (!journalExpandable.contains(e.target)) {
            journalExpandable.classList.remove('active');
        }
    });
});

loadCashflowReport();