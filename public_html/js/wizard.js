/**
 * onFilesSelected recibe siempre un array de File, aunque el input no sea "multiple".
 */
function setupDropzone(dropzoneEl, inputEl, onFilesSelected) {
    dropzoneEl.addEventListener('click', () => inputEl.click());

    inputEl.addEventListener('change', () => {
        if (inputEl.files.length > 0) onFilesSelected(Array.from(inputEl.files));
    });

    ['dragenter', 'dragover'].forEach((evt) => {
        dropzoneEl.addEventListener(evt, (e) => {
            e.preventDefault();
            dropzoneEl.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach((evt) => {
        dropzoneEl.addEventListener(evt, (e) => {
            e.preventDefault();
            dropzoneEl.classList.remove('dragover');
        });
    });

    dropzoneEl.addEventListener('drop', (e) => {
        const files = Array.from(e.dataTransfer.files);
        if (files.length > 0) onFilesSelected(files);
    });
}

function showAlert(container, message, type = 'error') {
    container.innerHTML = '';
    if (!message) return;
    const div = document.createElement('div');
    div.className = `alert alert-${type}`;
    div.textContent = message;
    container.appendChild(div);
}
