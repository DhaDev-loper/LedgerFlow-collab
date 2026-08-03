/* =====================================================================
   Dashboard logic.
   Right now this uses SAMPLE data so the page has something to show.
   Later, replace the "sample data" blocks below with fetch() calls
   to real backend endpoints, e.g.:
     fetch('/backend/routes/reports.php?action=cashflow')
     fetch('/backend/routes/journal.php?action=recent')
   ===================================================================== */

// ---------- Sample cash flow data (last 14 days) ----------
const cashflowLabels = [
    'Jul 16', 'Jul 17', 'Jul 18', 'Jul 19', 'Jul 20', 'Jul 21', 'Jul 22',
    'Jul 23', 'Jul 24', 'Jul 25', 'Jul 26', 'Jul 27', 'Jul 28', 'Jul 29',
];
const cashIn  = [3200, 2800, 4100, 3900, 3300, 5200, 4700, 3100, 3600, 4400, 5100, 3800, 4200, 3900];
const cashOut = [1200, 900, 2100, 1500, 1800, 1300, 2200, 1100, 1600, 1900, 1400, 1700, 1300, 1500];

const gridColor = 'rgba(255, 255, 255, 0.06)';
const tickColor = 'rgba(241, 245, 249, 0.55)';

const ctx = document.getElementById('cashflowChart');
const gradientIn = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
gradientIn.addColorStop(0, 'rgba(46, 144, 250, 0.35)');
gradientIn.addColorStop(1, 'rgba(46, 144, 250, 0.02)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: cashflowLabels,
        datasets: [
            {
                label: 'Cash In',
                data: cashIn,
                borderColor: '#2E90FA',
                backgroundColor: gradientIn,
                fill: true,
                tension: 0.35,
                pointRadius: 0,
                borderWidth: 2,
            },
            {
                label: 'Cash Out',
                data: cashOut,
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
                position: 'top',
                align: 'end',
                labels: { boxWidth: 12, usePointStyle: true, color: tickColor, font: { family: 'Inter', size: 12 } },
            },
            tooltip: {
                backgroundColor: '#14243F',
                borderColor: 'rgba(255,255,255,0.1)',
                borderWidth: 1,
                titleColor: '#F1F5F9',
                bodyColor: '#F1F5F9',
                callbacks: {
                    label: (item) => `${item.dataset.label}: ₱${item.formattedValue}`,
                },
            },
        },
        scales: {
            y: {
                ticks: { color: tickColor, callback: (val) => '₱' + val.toLocaleString(), font: { family: 'IBM Plex Mono', size: 11 } },
                grid: { color: gridColor },
            },
            x: {
                grid: { display: false },
                ticks: { color: tickColor, font: { family: 'Inter', size: 11 } },
            },
        },
    },
});

// ---------- Mini "Today's Sales" bar chart (side widget) ----------
const miniCtx = document.getElementById('miniSalesChart');
if (miniCtx) {
    new Chart(miniCtx, {
        type: 'bar',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                data: [820, 950, 700, 1100, 980, 1240, 640],
                backgroundColor: '#2E90FA',
                borderRadius: 4,
                maxBarThickness: 18,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: {
                y: { display: false, grid: { display: false } },
                x: { grid: { display: false }, ticks: { color: tickColor, font: { family: 'Inter', size: 10 } } },
            },
        },
    });
}

// ---------- Sample recent transactions ----------
const sampleTransactions = [
    { type: 'in',  desc: 'Walk-in refill x8',          category: 'Walk-in Refills',   date: 'Jul 29, 2026', amount: 240.00 },
    { type: 'out', desc: 'Electricity bill — Meralco',  category: 'Electricity',       date: 'Jul 28, 2026', amount: -3450.00 },
    { type: 'in',  desc: 'Delivery — Dela Cruz Res.',   category: 'Delivery Sales',    date: 'Jul 28, 2026', amount: 350.00 },
    { type: 'out', desc: '5 sacks — bottle caps',       category: 'Cap & Bottle Inventory', date: 'Jul 27, 2026', amount: -1200.00 },
    { type: 'in',  desc: 'Dispenser rental — Unit #12', category: 'Dispenser Rentals', date: 'Jul 27, 2026', amount: 500.00 },
    { type: 'out', desc: 'Filter replacement service',  category: 'Water Treatment Maintenance', date: 'Jul 26, 2026', amount: -2800.00 },
];

function formatMoney(amount) {
    const sign = amount < 0 ? '-' : '+';
    return `${sign}₱${Math.abs(amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

const tbody = document.getElementById('recentTransactionsBody');
tbody.innerHTML = sampleTransactions.map(tx => `
    <tr>
        <td><span class="tx-icon ${tx.type}">${tx.type === 'in' ? '↓' : '↑'}</span></td>
        <td>${tx.desc}</td>
        <td><span class="text-secondary small">${tx.category}</span></td>
        <td><span class="text-secondary small">${tx.date}</span></td>
        <td class="text-end money fw-semibold ${tx.type === 'in' ? 'text-success' : 'text-danger'}">
            ${formatMoney(tx.amount)}
        </td>
    </tr>
`).join('');

// ---------- Logout ----------
document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await fetch('../backend/routes/auth.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'logout' }),
    });
    window.location.href = '../frontend/login.html';
});