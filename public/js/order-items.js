/**
 * Order item rows (product picker, price, quantity, live totals).
 * Used on the order page and in the "Create order" dialog in the inbox.
 */
window.OrderItems = (() => {
    function money(currency, n) {
        const v = Math.round((Number(n) || 0) * 100) / 100;
        return `${currency} ${v.toLocaleString('en-IN', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 })}`;
    }

    function mount(form, opts) {
        const box = form.querySelector('[data-items]');
        const rows = box.querySelector('[data-item-rows]');
        const template = rows.querySelector('[data-item-row]').cloneNode(true);
        let products = opts.products || [];

        const available = () => {
            const page = opts.pageId();
            return products.filter(p => p.page_id === null || p.page_id === page);
        };

        function fillSelect(row) {
            const select = row.querySelector('[data-product]');
            const current = row.querySelector('[data-product-id]').value;
            select.innerHTML = '<option value="">Custom item…</option>' + available()
                .map(p => `<option value="${p.id}">${p.name.replace(/</g, '&lt;')} (${money(opts.currency, p.unit_price)})</option>`)
                .join('');
            select.value = available().some(p => String(p.id) === current) ? current : '';
        }

        function renumber() {
            [...rows.querySelectorAll('[data-item-row]')].forEach((row, i) => {
                row.querySelectorAll('[name^="items["]').forEach(input => {
                    input.name = input.name.replace(/items\[\d+\]/, `items[${i}]`);
                });
            });
        }

        function recalc() {
            let subtotal = 0;
            rows.querySelectorAll('[data-item-row]').forEach(row => {
                const line = (Number(row.querySelector('[data-price]').value) || 0) * (Number(row.querySelector('[data-qty]').value) || 0);
                subtotal += line;
                row.querySelector('[data-line]').textContent = money(opts.currency, line);
            });
            box.querySelector('[data-subtotal]').textContent = money(opts.currency, subtotal);
            const delivery = Number(form.querySelector('[name="delivery_charge"]')?.value) || 0;
            const discount = Number(form.querySelector('[name="discount"]')?.value) || 0;
            const grand = form.querySelector('[data-grand-total]');
            if (grand) grand.textContent = money(opts.currency, Math.max(0, subtotal + delivery - discount));
        }

        function addRow(values = {}) {
            const row = template.cloneNode(true);
            row.querySelector('[data-product-id]').value = values.product_id ?? '';
            row.querySelector('[data-name]').value = values.name ?? '';
            row.querySelector('[data-price]').value = values.unit_price ?? '';
            row.querySelector('[data-qty]').value = values.quantity ?? 1;
            rows.appendChild(row);
            fillSelect(row);
            renumber();
            recalc();
            return row;
        }

        box.addEventListener('change', e => {
            if (!e.target.matches('[data-product]')) return;
            const row = e.target.closest('[data-item-row]');
            const p = products.find(x => String(x.id) === e.target.value);
            row.querySelector('[data-product-id]').value = p ? p.id : '';
            if (p) {
                row.querySelector('[data-name]').value = p.name;
                row.querySelector('[data-price]').value = p.unit_price || '';
                row.querySelector('[data-qty]').focus();
            } else {
                row.querySelector('[data-name]').focus();
            }
            recalc();
        });
        box.addEventListener('click', e => {
            if (e.target.closest('[data-add-item]')) addRow();
            const remove = e.target.closest('[data-remove-item]');
            if (remove) {
                const all = rows.querySelectorAll('[data-item-row]');
                if (all.length > 1) remove.closest('[data-item-row]').remove();
                else addRow() && all[0].remove();
                renumber();
                recalc();
            }
        });
        // Typing a different name turns a product row into a custom item.
        box.addEventListener('input', e => {
            if (e.target.matches('[data-name]')) {
                const row = e.target.closest('[data-item-row]');
                const p = products.find(x => String(x.id) === row.querySelector('[data-product-id]').value);
                if (p && p.name !== e.target.value) {
                    row.querySelector('[data-product-id]').value = '';
                    row.querySelector('[data-product]').value = '';
                }
            }
            recalc();
        });
        form.querySelectorAll('[data-total-input]').forEach(i => i.addEventListener('input', recalc));
        document.getElementById('page-select')?.addEventListener('change', () => rows.querySelectorAll('[data-item-row]').forEach(fillSelect));

        rows.querySelectorAll('[data-item-row]').forEach(fillSelect);
        recalc();

        return {
            setProducts(list) { products = list; rows.querySelectorAll('[data-item-row]').forEach(fillSelect); },
            reset(first) {
                rows.innerHTML = '';
                addRow(first || {});
            },
            addRow,
            recalc,
        };
    }

    return { mount, money };
})();
