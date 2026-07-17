document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-ai-system-settings]');
    if (!form) return;

    const assistant = form.querySelector('[data-ai-assistant-toggle]');
    const gemini = form.querySelector('[data-ai-gemini-toggle]');
    const geminiRow = form.querySelector('[data-gemini-setting]');
    const note = form.querySelector('[data-ai-settings-note]');
    if (!assistant || !gemini || assistant.disabled) return;

    const geminiConfigured = !gemini.disabled;
    const syncDependencies = () => {
        const assistantEnabled = assistant.checked;
        gemini.disabled = !assistantEnabled || !geminiConfigured;
        geminiRow.classList.toggle('is-disabled', gemini.disabled);
        if (!assistantEnabled) gemini.checked = false;
        if (note) {
            note.textContent = assistantEnabled
                ? 'Saving will enable the assistant for all authorized scheduling users.'
                : 'Saving will hide AI controls; manual scheduling will remain available.';
        }
    };

    assistant.addEventListener('change', syncDependencies);
    syncDependencies();
});
