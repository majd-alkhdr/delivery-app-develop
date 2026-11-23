{{-- المرحلة 7F: تحديد جماعي بتفويض الأحداث (يعمل أيضاً حين يُحمَّل التبويب جزئياً) — يُعرَّف مرة واحدة --}}
<script>
    if (!window.__bulkSelect) {
        window.__bulkSelect = true;
        const boxes = id => document.querySelectorAll('input[form="' + id + '"][name="ids[]"]');
        const sync = id => {
            const form = document.getElementById(id);
            if (!form) return;
            const n = [...boxes(id)].filter(b => b.checked).length;
            form.querySelector('[data-bulk-count]').textContent = n;
            form.querySelector('[data-bulk-submit]').disabled = n === 0;
        };
        document.addEventListener('change', e => {
            const t = e.target;
            if (t.matches('[data-bulk-page]')) {
                boxes(t.dataset.bulkPage).forEach(b => { b.checked = t.checked; });
                sync(t.dataset.bulkPage);
            } else if (t.matches('input[name="ids[]"][form^="bulk-"]')) {
                sync(t.getAttribute('form'));
            }
        });
    }
</script>
