<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="paymentModalTitle">Record Settlement</h5>
                    <p class="small mb-0 text-muted">Posts a balanced double-entry journal transaction.</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="paymentForm">
                <div class="modal-body">
                    <div id="paymentError" class="alert alert-danger py-2 px-3 small d-none" role="alert"></div>
                    <div id="paymentSuccess" class="alert alert-success py-2 px-3 small d-none" role="status"></div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="paymentType" class="form-label small">Settlement Type</label>
                            <select class="form-select form-control-dark" id="paymentType" required>
                                <option value="ar">Receive Payment (A/R)</option>
                                <option value="ap">Make Payment (A/P)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="paymentClient" class="form-label small">Client / Vendor</label>
                            <select class="form-select form-control-dark" id="paymentClient" required>
                                <option value="">Loading clients...</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="rounded border p-3" style="border-color: var(--dark-border) !important; background: rgba(255,255,255,.025);">
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="small client-detail-muted">Outstanding balance</span>
                                    <strong id="paymentOutstandingBalance">Select a client or vendor</strong>
                                </div>
                                <div id="paymentOpenItems" class="small mt-2 client-detail-muted">
                                    Payments are applied to the oldest due invoice or bill first.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="paymentSourceAccount" class="form-label small">Payment Source Account</label>
                            <select class="form-select form-control-dark" id="paymentSourceAccount" required>
                                <option value="">Loading accounts...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="paymentSettlementAccount" class="form-label small">Settlement Account</label>
                            <select class="form-select form-control-dark" id="paymentSettlementAccount" required></select>
                        </div>
                        <div class="col-md-4">
                            <label for="paymentAmount" class="form-label small">Amount (₱)</label>
                            <input type="number" class="form-control form-control-dark" id="paymentAmount" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label for="paymentDate" class="form-label small">Date</label>
                            <input type="date" class="form-control form-control-dark" id="paymentDate" required>
                        </div>
                        <div class="col-md-4">
                            <label for="paymentReference" class="form-label small">Reference #</label>
                            <input type="text" class="form-control form-control-dark" id="paymentReference" maxlength="50" placeholder="Check # / OR #">
                        </div>
                        <div class="col-12">
                            <label for="paymentDescription" class="form-label small">Description / Notes</label>
                            <input type="text" class="form-control form-control-dark" id="paymentDescription" maxlength="255" required>
                        </div>
                    </div>
                    <div class="form-text mt-3" style="color: var(--dark-text-muted);">
                        A/R receipts debit the payment source and credit Accounts Receivable. A/P payments debit Accounts Payable and credit the payment source.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn quick-action-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn quick-action-btn primary" id="savePaymentBtn">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
