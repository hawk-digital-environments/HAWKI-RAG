// Core variables
let page = 1;
let loading = false;
let searchMode = false;
let hasMoreItems = true;

// Utility functions
function debounce(func, delay) {
    let debounceTimer;
    return function () {
        const context = this;
        const args = arguments;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => func.apply(context, args), delay);
    }
}

function updateShownCount() {
    const tbody = document.querySelector('#embeddingsTable tbody');
    const actualRows = tbody.querySelectorAll('tr').length;
    const resultsText = document.querySelector('#results');
    if (resultsText) {
        const totalCount = parseInt(resultsText.getAttribute('data-total-count').replace(/,/g, ''));
        const shownCount = Math.min(actualRows, totalCount);
        resultsText.textContent = `${totalCount.toLocaleString()} items (${shownCount.toLocaleString()} shown).`;
    }
}

// Modal functions
// function editEmbedding(id, text) {
//     document.getElementById('edit_id').value = id;
//     const decodedText = text.replace(/\\u([0-9a-fA-F]{4})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)))
//                           .replace(/\\r\\n/g, '\n')
//                           .replace(/\\n/g, '\n');
//     document.getElementById('edit_text').value = decodedText;
//     document.getElementById('editModal').style.display = 'block';
// }

// function hideModal() {
//     document.getElementById('editModal').style.display = 'none';
// }

// Data loading function
function loadMore() {
    if (loading || searchMode || (!hasMoreItems && page > 1)) return;

    loading = true;
    const loadingSpinner = document.createElement('tr');
    loadingSpinner.id = 'loading-spinner';
    loadingSpinner.innerHTML = '<td colspan="9" style="text-align: center; padding: 20px;">Loading more items...</td>';
    document.querySelector('#embeddingsTable tbody').appendChild(loadingSpinner);

    fetch(`/loadmore?page=${page}`, {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer localhost',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            const tbody = document.querySelector('#embeddingsTable tbody');
            document.getElementById('loading-spinner')?.remove();

            if (!data.data || data.data.length === 0) {
                hasMoreItems = false;
                loading = false;
                return;
            }

            data.data.forEach(entry => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                <td>${entry.id}</td>
                <td>${entry.title}</td>
                <td>${entry.content}</td>
                <td><a href="${entry.meta_img_url}" target="_blank">${entry.meta_img_url}</a></td>
                <td><a href="${entry.page_url}" target="_blank">${entry.page_url}</a></td>
                <td><a href="${entry.source_url}" target="_blank">${entry.source_url}</a></td>
                <td>${entry.source_format}</td>
                <td>${entry.date}</td>
                <td><pre style="white-space: pre-wrap;">${entry.tags}</pre></td>
                <td><pre style="white-space: pre-wrap;">${entry.intermediate_formatting}</pre></td>
                <td>N/A</td>
            `;
                // <td><button class="edit-button" data-id="${entry.id}" data-content="${entry.content}">Edit</button></td>
                tbody.appendChild(tr);
            });

            updateShownCount();

            const totalCount = parseInt(document.querySelector('#results').getAttribute('data-total-count').replace(/,/g, ''));
            const currentlyShown = tbody.querySelectorAll('tr').length;

            hasMoreItems = currentlyShown < totalCount;

            page++;
            loading = false;
        })
        .catch(error => {
            document.getElementById('loading-spinner')?.remove();
            loading = false;
        });
}

// Event listeners
// document.addEventListener('click', function(e) {
//     if (e.target.classList.contains('edit-button')) {
//         editEmbedding(e.target.dataset.id, e.target.dataset.content);
//     }
// });

document.getElementById('searchBox').addEventListener('input', debounce(function () {
    const searchText = this.value;
    searchMode = searchText.length > 0;
    hasMoreItems = true;

    if (!searchMode) {
        page = 1;
        document.querySelector('#embeddingsTable tbody').innerHTML = '';
        const resultsText = document.querySelector('#results');
        if (resultsText) {
            resultsText.textContent = `${resultsText.getAttribute('data-total-count')} items (0 shown).`;
        }
        loadMore();
        return;
    }

    fetch('/api/search', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer localhost',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ term: searchText })
    })
        .then(response => response.json())
        .then(data => {
            const entries = data.data.items || [];
            const tbody = document.querySelector('#embeddingsTable tbody');
            tbody.innerHTML = entries.length ? '' : '<tr><td colspan="9" style="text-align: center;">No results found</td></tr>';

            entries.forEach(entry => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                <td>${entry.id}</td>
                <td>${entry.title}</td>
                <td>${entry.content}</td>
                <td><a href="${entry.meta_img_url}" target="_blank">${entry.meta_img_url}</a></td>
                <td><a href="${entry.page_url}" target="_blank">${entry.page_url}</a></td>
                <td><a href="${entry.source_url}" target="_blank">${entry.source_url}</a></td>
                <td>${entry.source_format}</td>
                <td>${entry.date}</td>
                <td>${entry.tags}</td>
                <td><pre style='white-space: pre-wrap;'>${entry.intermediate_formatting}</pre></td>
                <td>${entry.similarity.toFixed(4)}</td>
            `;
                // <td><button class="edit-button" data-id="${entry.id}" data-content="${entry.content}">Edit</button></td>
                tbody.appendChild(tr);
            });

            const resultsText = document.querySelector('#results');
            if (resultsText) {
                resultsText.textContent = `${entries.length} items (${entries.length} shown).`;
            }
        });
}, 500));

window.addEventListener('scroll', () => {
    if (!hasMoreItems || loading || searchMode) return;

    const scrollPosition = window.innerHeight + window.scrollY;
    const documentHeight = document.body.offsetHeight;

    if (scrollPosition >= documentHeight - 500) {
        loadMore();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const totalCount = parseInt(document.querySelector('#results').getAttribute('data-total-count').replace(/,/g, ''));
    hasMoreItems = totalCount > 500;
    loading = false;
    loadMore();
    updateShownCount();
});