const SUPPORTED_THEMES = new Set(['light', 'dark']);
const THEME_PREFERENCE_VERSION = '2';

function applyTheme(theme) {
    const selectedTheme = SUPPORTED_THEMES.has(theme) ? theme : 'dark';
    document.documentElement.setAttribute('data-theme', selectedTheme);

    try {
        localStorage.setItem('theme', selectedTheme);
        localStorage.setItem('theme-preference-version', THEME_PREFERENCE_VERSION);
    } catch (error) {
        console.warn('Could not save the appearance preference in this browser.', error);
    }
}

function initTheme() {
    let savedTheme = 'dark';
    try {
        const preferenceVersion = localStorage.getItem('theme-preference-version');
        if (preferenceVersion !== THEME_PREFERENCE_VERSION) {
            localStorage.setItem('theme', 'dark');
            localStorage.setItem('theme-preference-version', THEME_PREFERENCE_VERSION);
        }
        const storedTheme = localStorage.getItem('theme');
        if (SUPPORTED_THEMES.has(storedTheme)) {
            savedTheme = storedTheme;
        }
    } catch (error) {
        console.warn('Could not read the appearance preference in this browser.', error);
    }

    document.documentElement.setAttribute('data-theme', savedTheme);
}

initTheme();