const clientDetailModalElement = document.getElementById('clientDetailModal');
const clientDetailModal = clientDetailModalElement
    ? bootstrap.Modal.getOrCreateInstance(clientDetailModalElement)
    : null;

window.currentClientDetailId = null;

window.viewClient = async function (clientId) {
    window.currentClientDetailId = Number(clientId);
    if (!clientDetailModal) return;

    document.getElementById('clientDetailName').textContent = 'Loading client...';
    document.getElementById('clientDetailCompany').textContent = '';
    document.getElementById('clientDetailBalance').textContent = '—';
    document.getElementById('clientHistoryBody').innerHTML =
        '<tr><td colspan="4" class="text-center py-4 client-detail-muted">Loading transaction history...</td></tr>';
    clientDetailModal.show();

    try {
        const response = await fetch(
            `routes/journal.php?action=client-detail&client_id=${encodeURIComponent(clientId)}`
        );
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not load client details.');
        }

        const client = data.client;
        document.getElementById('clientDetailName').textContent = client.name || 'Client';
        document.getElementById('clientDetailCompany').textContent = client.company_name || 'Individual client';
        document.getElementById('clientDetailEmail').textContent = client.email || '—';
        document.getElementById('clientDetailPhone').textContent = client.phone || '—';
        document.getElementById('clientDetailPartyType').textContent = client.party_type || 'customer';
        document.getElementById('clientDetailStatus').textContent = client.status || '—';
        const isVendorOnly = client.party_type === 'vendor';
        document.getElementById('clientDetailBalance').textContent = formatPaymentCurrency(
            isVendorOnly ? client.unsettled_payable_balance : client.unsettled_balance
        );
        const canReceive = client.party_type === 'customer' || client.party_type === 'both' || !client.party_type;
        const canPay = client.party_type === 'vendor' || client.party_type === 'both';
        const receiveButton = document.getElementById('clientReceivePaymentBtn');
        const makePaymentButton = document.getElementById('clientMakePaymentBtn');
        if (receiveButton) receiveButton.classList.toggle('d-none', !canReceive);
        if (makePaymentButton) makePaymentButton.classList.toggle('d-none', !canPay);
        const balanceLabel = document.getElementById('clientDetailBalanceLabel');
        if (balanceLabel) {
            balanceLabel.textContent = client.party_type === 'vendor'
                ? 'Total Unsettled Balance (A/P)'
                : 'Total Unsettled Balance (A/R)';
        }
        renderClientHistory(data.history || []);
    } catch (error) {
        document.getElementById('clientDetailName').textContent = 'Client Details';
        document.getElementById('clientHistoryBody').innerHTML =
            `<tr><td colspan="4" class="text-center py-3 text-danger">${escapeClientHtml(error.message)}</td></tr>`;
    }
};

function renderClientHistory(history) {
    const tbody = document.getElementById('clientHistoryBody');
    if (history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 client-detail-muted">No transactions recorded for this client.</td></tr>';
        return;
    }

    tbody.innerHTML = history.map(entry => `
        <tr>
            <td class="text-nowrap client-history-date">${escapeClientHtml(entry.transaction_date || '')}</td>
            <td>
                <div class="client-history-description">${escapeClientHtml(entry.description || '')}</div>
                <small class="client-detail-muted">${escapeClientHtml(entry.transaction_type || '')}${entry.reference_no ? ` · Ref ${escapeClientHtml(entry.reference_no)}` : ''}</small>
            </td>
            <td class="client-history-lines">${escapeClientHtml(entry.entries || '')}</td>
            <td class="text-end text-nowrap client-history-amount">${formatPaymentCurrency(entry.total_amount)}</td>
        </tr>
    `).join('');
}

function escapeClientHtml(value) {
    const element = document.createElement('span');
    element.textContent = String(value ?? '');
    return element.innerHTML;
}
