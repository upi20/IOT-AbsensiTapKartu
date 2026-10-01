// Panel admin Absensi RFID — skrip kecil tanpa build step.

// Konfirmasi sebelum mengirim form berbahaya: <form data-confirm="Yakin?"> atau <button type="submit" data-confirm="Yakin?">
document.addEventListener('submit', (event) => {
    const message = event.submitter?.dataset.confirm ?? event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Tombol salin: <button data-copy="#id-elemen">
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    const text = document.querySelector(button.dataset.copy)?.textContent.trim() ?? '';
    try {
        await navigator.clipboard.writeText(text);
        button.textContent = 'Tersalin';
    } catch {
        window.prompt('Salin teks ini:', text);
    }
});

// Penghitung karakter: <textarea maxlength="160" data-count="#id-penghitung">
document.querySelectorAll('[data-count]').forEach((field) => {
    const output = document.querySelector(field.dataset.count);
    const update = () => { output.textContent = `${field.value.length}/${field.maxLength}`; };
    field.addEventListener('input', update);
    update();
});

// Aksi massal: <form id="x" data-bulk> berisi [data-bulk-count] dan tombol name="action";
// kotak centang baris <input name="ids[]" form="x">, "pilih semua" <input data-select-all form="x">.
document.querySelectorAll('form[data-bulk]').forEach((form) => {
    const boxes = () => [...form.elements].filter((element) => element.type === 'checkbox');
    const rows = () => boxes().filter((box) => box.name === 'ids[]');
    const update = () => {
        const count = rows().filter((box) => box.checked).length;
        form.querySelector('[data-bulk-count]').textContent = `${count} dipilih`;
        form.querySelectorAll('button[name="action"]').forEach((button) => {
            button.disabled = count === 0;
            if (button.dataset.confirmTemplate) {
                button.dataset.confirm = button.dataset.confirmTemplate.replace(':count', count);
            }
        });
        boxes().filter((box) => 'selectAll' in box.dataset).forEach((box) => {
            box.checked = count > 0 && count === rows().length;
            box.indeterminate = count > 0 && count < rows().length;
        });
    };
    document.addEventListener('change', (event) => {
        if (event.target.form !== form) return;
        if ('selectAll' in event.target.dataset) {
            rows().forEach((box) => { box.checked = event.target.checked; });
        }
        update();
    });
    window.addEventListener('pageshow', update);
    update();
});

// Dasbor: ambil ulang bagian <div data-live="url"> setiap 10 detik.
const live = document.querySelector('[data-live]');
if (live) {
    setInterval(async () => {
        try {
            const response = await fetch(live.dataset.live, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.redirected) {
                window.location.reload(); // sesi habis -> halaman login
            } else if (response.ok) {
                live.innerHTML = await response.text();
            }
        } catch {
            // Jaringan putus: coba lagi di putaran berikutnya.
        }
    }, 10000);
}
