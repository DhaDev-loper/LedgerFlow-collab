(() => {
    const currencyInputSelector = '[data-currency-input]';

    function parseCurrencyValue(value) {
        const normalized = String(value ?? '').replace(/,/g, '').trim();
        if (normalized === '') return NaN;
        if (!/^(?:\d+(?:\.\d{0,2})?|\.\d{1,2})$/.test(normalized)) return NaN;
        const amount = Number(normalized);
        return Number.isFinite(amount) ? amount : NaN;
    }

    function formatCurrencyValue(value) {
        const amount = parseCurrencyValue(value);
        if (!Number.isFinite(amount)) return String(value ?? '');
        return amount.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function formatWhileTyping(value) {
        const cleaned = String(value ?? '').replace(/[^\d.]/g, '');
        if (!cleaned) return '';

        const decimalPosition = cleaned.indexOf('.');
        const hasDecimal = decimalPosition !== -1;
        let whole = hasDecimal ? cleaned.slice(0, decimalPosition) : cleaned;
        const fraction = hasDecimal
            ? cleaned.slice(decimalPosition + 1).replace(/\./g, '').slice(0, 2)
            : '';

        if (!whole && hasDecimal) whole = '0';
        whole = whole.replace(/^0+(?=\d)/, '');
        whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return `${whole}${hasDecimal ? `.${fraction}` : ''}`;
    }

    function positionAfterTokenCount(value, tokenCount) {
        if (tokenCount <= 0) return 0;
        let seen = 0;
        for (let index = 0; index < value.length; index += 1) {
            if (/[\d.]/.test(value[index])) seen += 1;
            if (seen >= tokenCount) return index + 1;
        }
        return value.length;
    }

    function formatCurrencyInput(input) {
        if (!input) return;
        input.value = formatCurrencyValue(input.value);
    }

    function initializeCurrencyInputs(root = document) {
        const inputs = root.matches?.(currencyInputSelector)
            ? [root]
            : root.querySelectorAll(currencyInputSelector);
        inputs.forEach(input => {
            if (input.dataset.currencyInputReady === 'true') return;
            input.dataset.currencyInputReady = 'true';
            input.type = 'text';
            input.inputMode = 'decimal';
            input.autocomplete = 'off';
            input.addEventListener('input', () => {
                const tokenCount = input.value.slice(0, input.selectionStart ?? input.value.length)
                    .replace(/[^\d.]/g, '').length;
                input.value = formatWhileTyping(input.value);
                const caret = positionAfterTokenCount(input.value, tokenCount);
                input.setSelectionRange(caret, caret);
            });
            input.addEventListener('focus', () => {
                if (parseCurrencyValue(input.value) === 0) input.select();
            });
            input.addEventListener('blur', () => {
                if (input.value.trim() !== '') input.value = formatCurrencyValue(input.value);
            });
            if (input.value.trim() !== '') input.value = formatCurrencyValue(input.value);
        });
    }

    window.parseCurrencyValue = parseCurrencyValue;
    window.formatCurrencyInput = formatCurrencyInput;
    window.initializeCurrencyInputs = initializeCurrencyInputs;

    initializeCurrencyInputs();

    document.addEventListener('submit', event => {
        const fields = [...event.target.querySelectorAll(currencyInputSelector)];
        const restoreValues = fields.map(input => {
            const displayValue = input.value;
            const parsed = parseCurrencyValue(displayValue);
            if (Number.isFinite(parsed)) input.value = parsed.toFixed(2);
            return { input, displayValue, submittedValue: input.value };
        });

        window.setTimeout(() => {
            restoreValues.forEach(({ input, displayValue, submittedValue }) => {
                if (!input.isConnected || input.value !== submittedValue) return;
                input.value = displayValue.trim() === '' ? '' : formatCurrencyValue(displayValue);
            });
        }, 0);
    }, true);
})();
