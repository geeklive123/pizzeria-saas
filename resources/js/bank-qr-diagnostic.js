document.querySelectorAll('[data-bank-qr-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const section = button.closest('section');
        const textarea = section?.querySelector('[data-bank-qr-original]');
        const status = section?.querySelector('[data-bank-qr-copy-status]');
        if (!textarea || !status) return;

        try {
            const original = JSON.parse(textarea.dataset.originalJson);
            await navigator.clipboard.writeText(original);
            status.textContent = 'Base64 completo copiado.';
        } catch {
            status.textContent = 'No se pudo copiar. Selecciona el contenido y cópialo manualmente.';
            textarea.focus();
            textarea.select();
        }
    });
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted && document.querySelector('[data-bank-qr-original]')) {
        document.querySelectorAll('[data-bank-qr-original]').forEach((textarea) => {
            textarea.value = '';
            delete textarea.dataset.originalJson;
        });
        window.location.reload();
    }
});
