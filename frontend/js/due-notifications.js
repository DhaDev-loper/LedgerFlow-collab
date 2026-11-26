(() => {
    const list = document.getElementById('dueNotificationsList');
    const countBadge = document.getElementById('dueNotificationsCount');
    const notificationButton = document.getElementById('dueNotificationsButton');
    const modal = document.getElementById('dueNotificationsModal');
    if (!list || !countBadge || !notificationButton || !modal) return;

    const formatAmount = value => {
        const amount = Number(value) || 0;
        return `${amount < 0 ? '-' : ''}\u20B1${Math.abs(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };
    const formatDate = value => window.formatDateMDY(value, '—');

    async function loadPaymentNotifications() {
        list.replaceChildren();
        const loading = document.createElement('p');
        loading.className = 'text-center py-4 mb-0';
        loading.textContent = 'Loading payment notifications...';
        list.appendChild(loading);

        try {
            const response = await fetch('routes/journal.php?action=due-notifications', {
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Could not load payment due notifications.');
            }

            const items = Array.isArray(data.items) ? data.items : [];
            countBadge.textContent = items.length > 99 ? '99+' : String(items.length);
            countBadge.classList.toggle('d-none', items.length === 0);
            notificationButton.setAttribute(
                'aria-label',
                items.length ? `${items.length} payment due notifications` : 'No payment due notifications'
            );
            list.replaceChildren();

            if (items.length === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'text-center py-4 mb-0';
                emptyMessage.textContent = 'No overdue payments or items due in the next 7 days.';
                list.appendChild(emptyMessage);
                return;
            }

            const renderSection = (title, sectionItems) => {
                if (!sectionItems.length) return;
                const section = document.createElement('section');
                section.className = 'mb-4';
                const heading = document.createElement('h6');
                heading.className = 'mb-2';
                heading.textContent = title;
                const itemList = document.createElement('div');
                itemList.className = 'd-flex flex-column gap-2';

                sectionItems.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'd-flex flex-wrap justify-content-between align-items-center gap-3 rounded border p-3';

                    const details = document.createElement('div');
                    details.className = 'flex-grow-1';
                    const party = document.createElement('div');
                    party.className = 'fw-semibold';
                    party.textContent = item.company_name
                        ? `${item.party_name} (${item.company_name})`
                        : item.party_name;
                    const description = document.createElement('div');
                    description.className = 'small';
                    description.textContent = [
                        item.type === 'ar' ? 'A/R · Customer invoice' : 'A/P · Vendor bill',
                        item.reference_no ? `Ref ${item.reference_no}` : item.description,
                        `Due ${formatDate(item.due_date)}`,
                    ].filter(Boolean).join(' · ');
                    details.append(party, description);

                    const right = document.createElement('div');
                    right.className = 'd-flex align-items-center gap-3';
                    const amount = document.createElement('strong');
                    amount.className = 'money text-nowrap';
                    amount.textContent = formatAmount(item.remaining);
                    const link = document.createElement('a');
                    link.className = 'btn quick-action-btn';
                    link.href = `clients.php?client_id=${encodeURIComponent(Number(item.client_id))}`;
                    link.textContent = 'View';
                    link.setAttribute(
                        'aria-label',
                        `View ${item.type === 'ar' ? 'customer' : 'vendor'} ${item.party_name}`
                    );
                    right.append(amount, link);
                    row.append(details, right);
                    itemList.appendChild(row);
                });

                section.append(heading, itemList);
                list.appendChild(section);
            };

            renderSection('Overdue', items.filter(item => item.is_overdue));
            renderSection('Due in the next 7 days', items.filter(item => !item.is_overdue));
        } catch (error) {
            countBadge.classList.add('d-none');
            notificationButton.setAttribute('aria-label', 'Payment notifications unavailable');
            list.replaceChildren();
            const errorMessage = document.createElement('p');
            errorMessage.className = 'text-center text-danger py-4 mb-0';
            errorMessage.textContent = error.message || 'Could not load payment due notifications.';
            list.appendChild(errorMessage);
            console.error('Failed to load payment due notifications:', error);
        }
    }

    modal.addEventListener('show.bs.modal', loadPaymentNotifications);
    loadPaymentNotifications();
})();
