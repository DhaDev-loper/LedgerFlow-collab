(() => {
    const modalElement = document.getElementById('paymentModal');
    if (!modalElement) return;

    const paymentModal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const paymentForm = document.getElementById('paymentForm');
    const typeSelect = document.getElementById('paymentType');
    const clientSelect = document.getElementById('paymentClient');
    const sourceSelect = document.getElementById('paymentSourceAccount');
    const settlementSelect = document.getElementById('paymentSettlementAccount');
    const errorAlert = document.getElementById('paymentError');
    const successAlert = document.getElementById('paymentSuccess');
    const submitButton = document.getElementById('savePaymentBtn');
    const amountInput = document.getElementById('paymentAmount');
    const openBalanceElement = document.getElementById('paymentOutstandingBalance');
    const openItemsElement = document.getElementById('paymentOpenItems');
    const journalApi = 'routes/journal.php';
    let referenceData = null;
    let reopenClientId = null;
    let openBalance = 0;
    let openItemsRequest = 0;

    function showAlert(element, message) {
        element.textContent = message;
        element.classList.remove('d-none');
    }

    function resetAlerts() {
        errorAlert.textContent = '';
        successAlert.textContent = '';
        errorAlert.classList.add('d-none');
        successAlert.classList.add('d-none');
    }

    function addOptions(select, records, placeholder, labelFor) {
        select.replaceChildren(new Option(placeholder, ''));
        records.forEach(record => {
            select.add(new Option(labelFor(record), record.id));
        });
    }

    async function loadReferenceData() {
        if (referenceData) return referenceData;

        const response = await fetch(`${journalApi}?action=reference-data`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Could not load payment accounts and clients.');
        }
        if (!(data.cash_accounts || []).length
            || !(data.receivable_accounts || []).length
            || !(data.payable_accounts || []).length) {
            throw new Error('Configure active cash/bank, Accounts Receivable, and Accounts Payable accounts before recording settlements.');
        }
        referenceData = data;
        addOptions(
            sourceSelect,
            data.cash_accounts || [],
            '-- Select cash or bank account --',
            account => account.name
        );
        updatePartyOptions();
        updateSettlementAccount();
        return referenceData;
    }

    function updatePartyOptions() {
        if (!referenceData) return;
        const previousId = clientSelect.value;
        const expectedType = typeSelect.value === 'ar' ? 'customer' : 'vendor';
        const parties = (referenceData.clients || []).filter(
            client => client.party_type === expectedType || client.party_type === 'both'
        );
        addOptions(
            clientSelect,
            parties,
            `-- Select ${expectedType} --`,
            client => client.company_name ? `${client.name} (${client.company_name})` : client.name
        );
        if (parties.some(client => String(client.id) === previousId)) {
            clientSelect.value = previousId;
        }
    }

    function updateSettlementAccount() {
        if (!referenceData) return;
        const isReceivable = typeSelect.value === 'ar';
        const accounts = isReceivable
            ? referenceData.receivable_accounts || []
            : referenceData.payable_accounts || [];
        addOptions(
            settlementSelect,
            accounts,
            isReceivable ? '-- Select Accounts Receivable --' : '-- Select Accounts Payable --',
            account => account.name
        );

        document.getElementById('paymentModalTitle').textContent =
            isReceivable ? 'Receive Payment (A/R)' : 'Make Payment (A/P)';
        const descriptionInput = document.getElementById('paymentDescription');
        if (!descriptionInput.value || ['Customer payment', 'Vendor payment'].includes(descriptionInput.value)) {
            descriptionInput.value = isReceivable ? 'Customer payment' : 'Vendor payment';
        }
    }

    async function loadOpenItems() {
        const requestId = ++openItemsRequest;
        const clientId = Number(clientSelect.value);
        if (!clientId || !referenceData) {
            openBalance = 0;
            openBalanceElement.textContent = 'Select a client or vendor';
            openItemsElement.textContent = 'Payments are applied to the oldest due invoice or bill first.';
            amountInput.removeAttribute('max');
            amountInput.disabled = true;
            submitButton.disabled = true;
            return;
        }

        const url = new URL(journalApi, window.location.href);
        url.searchParams.set('action', 'party-open-items');
        url.searchParams.set('client_id', String(clientId));
        url.searchParams.set('type', typeSelect.value);
        openBalanceElement.textContent = 'Loading outstanding balance...';
        openItemsElement.textContent = '';
        amountInput.disabled = true;
        submitButton.disabled = true;

        try {
            const response = await fetch(url);
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Could not load outstanding documents.');
            }
            if (requestId !== openItemsRequest) return;

            openBalance = Number(data.open_balance) || 0;
            openBalanceElement.textContent = formatPaymentCurrency(openBalance);
            amountInput.max = openBalance.toFixed(2);
            amountInput.disabled = openBalance <= 0.005;
            submitButton.disabled = amountInput.disabled;

            if (!(data.items || []).length) {
                openItemsElement.textContent = 'No outstanding invoices or bills are available for settlement.';
                return;
            }

            openItemsElement.replaceChildren();
            const heading = document.createElement('div');
            heading.className = 'mb-1';
            heading.textContent = 'Open documents — oldest due first:';
            const list = document.createElement('ul');
            list.className = 'mb-0 ps-3';
            data.items.forEach(item => {
                const row = document.createElement('li');
                const reference = item.reference_no ? ` · ${item.reference_no}` : '';
                const due = item.due_date ? ` · due ${item.due_date}` : '';
                row.textContent = `${item.description || 'Journal entry'}${reference}${due}: ${formatPaymentCurrency(item.remaining)}`;
                list.appendChild(row);
            });
            openItemsElement.append(heading, list);
        } catch (error) {
            if (requestId !== openItemsRequest) return;
            openBalance = 0;
            openBalanceElement.textContent = 'Unable to load balance';
            openItemsElement.textContent = error.message;
            amountInput.removeAttribute('max');
            amountInput.disabled = true;
            submitButton.disabled = true;
        }
    }

    window.openPaymentModal = async function (type = 'ar', clientId = null) {
        resetAlerts();
        paymentForm.reset();
        typeSelect.value = type === 'ap' ? 'ap' : 'ar';
        document.getElementById('paymentDate').value = new Date().toISOString().slice(0, 10);
        reopenClientId = clientId ? Number(clientId) : null;

        try {
            await loadReferenceData();
            updatePartyOptions();
            updateSettlementAccount();
            if (reopenClientId) clientSelect.value = String(reopenClientId);
            await loadOpenItems();

            const detailModalElement = document.getElementById('clientDetailModal');
            const detailModal = detailModalElement
                ? bootstrap.Modal.getInstance(detailModalElement)
                : null;
            if (detailModalElement?.classList.contains('show') && detailModal) {
                detailModalElement.addEventListener('hidden.bs.modal', () => paymentModal.show(), { once: true });
                detailModal.hide();
            } else {
                paymentModal.show();
            }
        } catch (error) {
            paymentModal.show();
            showAlert(errorAlert, error.message);
        }
    };

    typeSelect.addEventListener('change', () => {
        updatePartyOptions();
        updateSettlementAccount();
        loadOpenItems();
    });
    clientSelect.addEventListener('change', loadOpenItems);

    paymentForm.addEventListener('submit', async event => {
        event.preventDefault();
        await window.savePayment();
    });

    window.savePayment = async function () {
        resetAlerts();
        if (!paymentForm.reportValidity()) return;

        const amount = Number(document.getElementById('paymentAmount').value);
        if (!Number.isFinite(amount) || amount <= 0) {
            showAlert(errorAlert, 'Enter a payment amount greater than zero.');
            return;
        }
        if (openBalance <= 0.005) {
            showAlert(errorAlert, 'There are no outstanding invoices or bills to settle for this party.');
            return;
        }
        if (amount > openBalance + 0.005) {
            showAlert(errorAlert, `The payment cannot exceed the outstanding balance of ${formatPaymentCurrency(openBalance)}.`);
            return;
        }

        const sourceAccountId = Number(sourceSelect.value);
        const settlementAccountId = Number(settlementSelect.value);
        const clientId = Number(clientSelect.value);
        if (!sourceAccountId || !settlementAccountId || !clientId) {
            showAlert(errorAlert, 'Select a client or vendor and both accounts.');
            return;
        }

        const isReceivable = typeSelect.value === 'ar';
        const typeName = isReceivable ? 'Cash Inflow' : 'Cash Outflow';
        const transactionType = referenceData.types.find(
            item => item.name.toLowerCase() === typeName.toLowerCase()
        );
        if (!transactionType) {
            showAlert(errorAlert, `The ${typeName} transaction type is not configured.`);
            return;
        }

        const debitLines = isReceivable
            ? [{ account_id: sourceAccountId, amount }]
            : [{ account_id: settlementAccountId, amount }];
        const creditLines = isReceivable
            ? [{ account_id: settlementAccountId, amount }]
            : [{ account_id: sourceAccountId, amount }];
        const description = document.getElementById('paymentDescription').value.trim();

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('client_id', String(clientId));
        formData.append('transaction_type_id', String(transactionType.id));
        formData.append('transaction_date', document.getElementById('paymentDate').value);
        formData.append('description', description);
        formData.append('reference_no', document.getElementById('paymentReference').value.trim());
        formData.append('debit_lines', JSON.stringify(debitLines));
        formData.append('credit_lines', JSON.stringify(creditLines));

        submitButton.disabled = true;
        submitButton.textContent = 'Saving...';
        try {
            const response = await fetch(journalApi, { method: 'POST', body: formData });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Could not save payment.');
            }

            if (typeof loadRecentTransactions === 'function') loadRecentTransactions();
            if (typeof loadDashboardSummary === 'function') loadDashboardSummary();
            if (typeof loadDashboardAccounts === 'function') loadDashboardAccounts();
            if (typeof loadCashflowChart === 'function') loadCashflowChart();
            if (typeof loadReceivablesPayables === 'function') loadReceivablesPayables();
            showAlert(successAlert, data.message || 'Payment recorded successfully.');
            paymentForm.reset();
            window.setTimeout(() => {
                if (reopenClientId && typeof window.viewClient === 'function') {
                    modalElement.addEventListener('hidden.bs.modal', () => {
                        window.viewClient(reopenClientId);
                    }, { once: true });
                }
                paymentModal.hide();
            }, 700);
        } catch (error) {
            showAlert(errorAlert, error.message);
        } finally {
            submitButton.textContent = 'Save Payment';
            loadOpenItems();
        }
    };

    modalElement.addEventListener('show.bs.modal', () => {
        resetAlerts();
        loadReferenceData().catch(error => showAlert(errorAlert, error.message));
    });
})();

function formatPaymentCurrency(value) {
    const amount = Number(value);
    return `₱${(Number.isFinite(amount) ? amount : 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}
