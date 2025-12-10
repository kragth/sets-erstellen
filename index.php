<?php 
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Set-Artikel Generator</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

<h1>Set-Artikel Generator</h1>
<img src="keenberk-logo.png" height="29px" width="126px" alt="Logo" class="centered">

<form id="add-set-job-form" autocomplete="off">
    <div class="input-group">
        <label for="requested_by">Bearbeiter:</label>
        <select id="requested_by" name="requested_by" required>
            <option value="">Bitte wählen</option>
            <option value="8">Andreas</option>
            <option value="47">Emily</option>
            <option value="2">Kristin</option>
            <option value="60">Katrin</option>
            <option value="9">Tino</option>
        </select>
    </div>

    <div class="input-group">
        <label>Set-Typ:</label>
        <select id="set_type" name="set_type" required>
            <option value="">Bitte wählen</option>
            <option value="423;476">Backofenset</option>
            <option value="423;428">Herdset</option>
            <option value="430;432">Spülenset</option>
            <option value="mikrowellenset">Mikrowellenset</option>
        </select>
    </div>

    <div class="input-group">
        <label>VariantIDs für Set (min. 2):</label>
        <div class="variant-list" id="variant-inputs">
            <div class="variant-row"><input type="number" name="variant_ids[]" required placeholder="VariantID 1"></div>
            <div class="variant-row"><input type="number" name="variant_ids[]" required placeholder="VariantID 2"></div>
        </div>
        <button type="button" id="add-variant">+ Weitere VariantID</button>
    </div>

    <button type="submit" id="submit-button">Set-Job anlegen</button>
</form>

<h2>Erstellte Sets</h2>
<div id="set-jobs-table"></div>

<script>
// ==========================
// Dynamisches Bearbeiter-Mapping (von PHP geliefert)
// ==========================
let bearbeiterMap = {};

// ==========================
// Statusübersetzung
// ==========================
function getGermanStatus(status) {
    switch (status) {
        case "offen": return "Offen";
        case "in_bearbeitung": return "In Bearbeitung";
        case "fehler": return "Fehler";
        case "fertig": return "Fertig";
        case "Warte auf Set-Komponenten":
        case "warte_auf_set_komponenten": return "Warte auf Set-Komponenten";
        case "Komponenten hinzugefügt":
        case "komponenten_hinzugefügt": return "Komponenten hinzugefügt";
        default: return status;
    }
}

// ==========================
// Sichere HTML-Ausgabe
// ==========================
function escapeHtml(text) {
    if (typeof text !== 'string') return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ==========================
// Set-Jobs aus DB laden
// ==========================
function renderSetJobs() {
    fetch('ajax_set_jobs.php?action=list')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert("Fehler beim Laden!");
                return;
            }

            bearbeiterMap = data.bearbeiterMap || {};

            let html = '<table><tr><th>Bearbeiter</th><th>Erstellt am</th><th>Status</th><th>VariantIDs</th><th>Set VariantID</th><th>Aktionen</th></tr>';

            data.jobs.forEach(job => {
                // ✅ Richtiges Mapping: ID → Name
                const req_by = bearbeiterMap[job.requested_by_id] || 'Unbekannt';
                const setVariant = job.new_variant_id ? escapeHtml(String(job.new_variant_id)) : '-';

                let variants = '-';
                if (Array.isArray(job.variant_ids) && job.variant_ids.length > 0) {
                    variants = job.variant_ids
                        .filter(v => v !== null && v !== undefined && v !== '')
                        .map(id => `<span>${escapeHtml(String(id))}</span>`)
                        .join(',<br>');
                }

                const isEditable = (job.status === 'Offen');
                const rowClass = job.status ? `status-${job.status.replace(/\s+/g, '-')}` : '';

                html += `
                    <tr class="${rowClass}">
                        <td>${escapeHtml(req_by)}</td>
                        <td>${escapeHtml(job.requested_at)}</td>
                        <td>${escapeHtml(getGermanStatus(job.status))}</td>
                        <td>${variants}</td>
                        <td>${setVariant}</td>
                        <td class="actions">`;

                // ✅ Übergabe der ID, nicht des Namens
                if (isEditable) {
                    html += `<button onclick="editJob(${job.id}, ${JSON.stringify(job.variant_ids)}, '${job.requested_by_id}', '${escapeHtml(job.set_type)}')">Bearbeiten</button>`;
                } else {
                    html += `<button class="disabled-btn" disabled>Bearbeiten (gesperrt)</button>`;
                }

                html += `<button onclick="deleteJob(${job.id})">Löschen</button></td></tr>`;
            });

            html += '</table>';
            if (data.jobs.length === 0) html = "<em>Noch keine Set-Jobs vorhanden.</em>";

            document.getElementById('set-jobs-table').innerHTML = html;
        });
}

// ==========================
// Job löschen
// ==========================
function deleteJob(id) {
    if (!confirm('Diesen Set-Job wirklich löschen?')) return;
    fetch('ajax_set_jobs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id=${id}`
    })
    .then(res => res.json())
    .then(() => renderSetJobs());
}

// ==========================
// Variantenfelder verwalten
// ==========================
function updateRemoveButtons() {
    let variantRows = document.querySelectorAll('#variant-inputs .variant-row');
    variantRows.forEach((row, idx) => {
        let btn = row.querySelector('.remove-variant');
        if (btn) btn.remove();
        if (variantRows.length > 2) {
            let removeBtn = document.createElement('button');
            removeBtn.type = "button";
            removeBtn.className = "remove-variant";
            removeBtn.textContent = "–";
            removeBtn.title = "Dieses Feld entfernen";
            removeBtn.onclick = function() {
                row.remove();
                updateRemoveButtons();
            };
            row.appendChild(removeBtn);
        }
    });
}

document.getElementById('add-variant').addEventListener('click', function() {
    let variantInputs = document.getElementById('variant-inputs');
    let idx = variantInputs.querySelectorAll('.variant-row').length + 1;
    let div = document.createElement('div');
    div.className = 'variant-row';
    let input = document.createElement('input');
    input.type = "number";
    input.name = "variant_ids[]";
    input.required = true;
    input.placeholder = "VariantID " + idx;
    div.appendChild(input);
    variantInputs.appendChild(div);
    updateRemoveButtons();
});

// ==========================
// Job bearbeiten
// ==========================
function editJob(id, variant_ids, requested_by, set_type) {
    const form = document.getElementById('add-set-job-form');
    form.scrollIntoView({behavior:'smooth'});
    form.dataset.editId = id;

    // Hier korrekt mit Bearbeiter-ID arbeiten
    document.getElementById('requested_by').value = requested_by || '';
    document.getElementById('set_type').value = set_type || '';

    let variantInputs = document.getElementById('variant-inputs');
    variantInputs.innerHTML = '';
    (variant_ids || []).forEach((val, idx) => {
        let div = document.createElement('div');
        div.className = 'variant-row';
        let input = document.createElement('input');
        input.type = "number";
        input.name = "variant_ids[]";
        input.value = val;
        input.required = true;
        input.placeholder = "VariantID " + (idx + 1);
        div.appendChild(input);
        variantInputs.appendChild(div);
    });
    updateRemoveButtons();
    document.getElementById('submit-button').textContent = 'Set-Job aktualisieren';
}

// ==========================
// Formular absenden
// ==========================
document.getElementById('add-set-job-form').addEventListener('submit', function(e) {
    e.preventDefault();
    let form = e.target;
    let formData = new FormData(form);
    let action = form.dataset.editId ? 'edit' : 'add';
    if (form.dataset.editId) formData.append('id', form.dataset.editId);
    formData.append('action', action);

    fetch('ajax_set_jobs.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || "Fehler!");
            return;
        }

        form.reset();
        form.removeAttribute('data-edit-id');
        document.getElementById('submit-button').textContent = 'Set-Job anlegen';
        document.getElementById('requested_by').value = '';
        document.getElementById('set_type').value = '';
        let variantInputs = document.getElementById('variant-inputs');
        variantInputs.innerHTML =
            '<div class="variant-row"><input type="number" name="variant_ids[]" required placeholder="VariantID 1"></div>' +
            '<div class="variant-row"><input type="number" name="variant_ids[]" required placeholder="VariantID 2"></div>';
        updateRemoveButtons();
        renderSetJobs();
    });
});

window.onload = function() {
    renderSetJobs();
    updateRemoveButtons();
};
</script>
</body>
</html>
