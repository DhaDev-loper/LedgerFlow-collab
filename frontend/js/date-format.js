(() => {
    const pad = value => String(value).padStart(2, '0');

    function formatDateMDY(value, fallback = '\u2014') {
        if (value instanceof Date) {
            if (Number.isNaN(value.getTime())) return fallback;
            return `${pad(value.getMonth() + 1)}/${pad(value.getDate())}/${value.getFullYear()}`;
        }

        const raw = String(value ?? '').trim();
        if (!raw) return fallback;

        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (match) return `${match[2]}/${match[3]}/${match[1]}`;

        const usMatch = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
        if (usMatch) return `${pad(usMatch[1])}/${pad(usMatch[2])}/${usMatch[3]}`;

        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) return raw;
        return `${pad(date.getMonth() + 1)}/${pad(date.getDate())}/${date.getFullYear()}`;
    }

    function formatDateTimeMDY(value, fallback = '\u2014') {
        const raw = String(value ?? '').trim();
        if (!raw) return fallback;

        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (match) {
            const hour24 = Number(match[4]);
            const suffix = hour24 >= 12 ? 'PM' : 'AM';
            const hour12 = hour24 % 12 || 12;
            return `${match[2]}/${match[3]}/${match[1]} ${hour12}:${match[5]} ${suffix}`;
        }

        return formatDateMDY(value, fallback);
    }

    function initializeDatePickers() {
        if (typeof window.flatpickr !== 'function') return;

        const valueDescriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        document.querySelectorAll('input[type="date"]:not([data-date-picker-ready])').forEach(input => {
            const inputId = input.id;
            const required = input.required;
            const control = document.createElement('div');
            control.className = `date-picker-control${input.classList.contains('w-100') ? ' is-full-width' : ''}`;
            input.parentNode.insertBefore(control, input);
            control.appendChild(input);

            const instance = window.flatpickr(input, {
                altInput: true,
                altFormat: 'm/d/Y',
                dateFormat: 'Y-m-d',
                allowInput: false,
                disableMobile: true,
                onReady(_selectedDates, _dateString, picker) {
                    const visibleInput = picker.altInput;
                    if (!visibleInput) return;

                    visibleInput.placeholder = 'MM/DD/YYYY';
                    visibleInput.required = required;
                    visibleInput.setAttribute('lang', 'en-US');
                    visibleInput.setAttribute('aria-label', input.labels?.[0]?.textContent.trim() || 'Date (MM/DD/YYYY)');
                    if (inputId) {
                        visibleInput.id = `${inputId}Display`;
                        input.labels?.forEach(label => {
                            if (label.htmlFor === inputId) label.htmlFor = visibleInput.id;
                        });
                    }
                    input.required = false;

                    const calendarButton = document.createElement('button');
                    calendarButton.type = 'button';
                    calendarButton.className = 'date-picker-button';
                    calendarButton.setAttribute('aria-label', 'Choose date');
                    calendarButton.innerHTML = '<i class="bi bi-calendar3" aria-hidden="true"></i>';
                    calendarButton.addEventListener('click', event => {
                        event.preventDefault();
                        picker.open();
                    });
                    control.appendChild(calendarButton);

                    if (!required) {
                        const clearButton = document.createElement('button');
                        clearButton.type = 'button';
                        clearButton.className = 'date-picker-button date-picker-clear';
                        clearButton.setAttribute('aria-label', 'Clear date');
                        clearButton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
                        clearButton.hidden = picker.selectedDates.length === 0;
                        clearButton.addEventListener('click', event => {
                            event.preventDefault();
                            picker.clear();
                        });
                        control.appendChild(clearButton);
                        picker.config.onChange.push(() => {
                            clearButton.hidden = picker.selectedDates.length === 0;
                        });
                    }
                },
            });

            input.dataset.datePickerReady = 'true';
            let settingPicker = false;
            Object.defineProperty(input, 'value', {
                configurable: true,
                get() {
                    return valueDescriptor.get.call(input);
                },
                set(value) {
                    if (settingPicker) {
                        valueDescriptor.set.call(input, value);
                        return;
                    }

                    settingPicker = true;
                    if (value) instance.setDate(value, false, 'Y-m-d');
                    else instance.clear(false);
                    settingPicker = false;
                },
            });

            input.form?.addEventListener('reset', () => {
                window.setTimeout(() => {
                    const resetValue = valueDescriptor.get.call(input);
                    if (resetValue) instance.setDate(resetValue, false, 'Y-m-d');
                    else instance.clear(false);
                }, 0);
            });
        });
    }

    function loadDatePicker() {
        if (!document.querySelector('input[type="date"]')) return;

        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css';
        document.head.appendChild(stylesheet);

        const themeStylesheet = document.createElement('link');
        themeStylesheet.rel = 'stylesheet';
        themeStylesheet.href = '../frontend/css/date-picker.css?v=aligned-controls-1';
        document.head.appendChild(themeStylesheet);

        if (typeof window.flatpickr === 'function') {
            initializeDatePickers();
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js';
        script.onload = initializeDatePickers;
        document.head.appendChild(script);
    }

    window.formatDateMDY = formatDateMDY;
    window.formatDateTimeMDY = formatDateTimeMDY;
    document.addEventListener('DOMContentLoaded', loadDatePicker);
})();
