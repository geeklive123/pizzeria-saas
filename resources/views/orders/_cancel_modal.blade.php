<dialog data-cancel-order-modal>
    <form method=POST data-cancel-order-form>
        @csrf
        <h2>Anular pedido <span data-cancel-order-number></span></h2>
        <p>La anulación conservará comandas, pagos y movimientos históricos.</p>
        <label>Motivo de anulación<textarea class=input name=reason maxlength=500 required data-cancel-order-reason></textarea></label>
        <button type=button data-cancel-order-close>Volver</button>
        <button class=btn-danger type=submit>Anular pedido</button>
    </form>
</dialog>
<script>
    (() => {
        const modal = document.querySelector('[data-cancel-order-modal]');
        if (! modal) return;
        const form = modal.querySelector('[data-cancel-order-form]');
        const reason = modal.querySelector('[data-cancel-order-reason]');
        const number = modal.querySelector('[data-cancel-order-number]');
        document.querySelectorAll('[data-cancel-order-open]').forEach((button) => button.addEventListener('click', () => {
            form.action = button.dataset.cancelOrderUrl;
            number.textContent = button.dataset.cancelOrderNumber || '';
            reason.value = '';
            modal.showModal();
            reason.focus();
        }));
        modal.querySelectorAll('[data-cancel-order-close]').forEach((button) => button.addEventListener('click', () => modal.close()));
    })();
</script>
