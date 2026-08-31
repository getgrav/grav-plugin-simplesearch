((function(){
    if (!Element.prototype.matches) {
        Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
    }
    var findAncestor = function(el, selector) {
        while ((el = el.parentElement) && !((el.matches || el.matchesSelector).call(el, selector))) {}
        return el;
    };

    const forms = document.querySelectorAll('form[data-simplesearch-form]');

    forms.forEach(function(form) {
        const field = form.querySelector('input[data-search-input]');
        if (!field) return;

        const minChars = field.getAttribute('data-min') || false;
        const searchBaseUrl = field.dataset.searchInput; // e.g., ".../search/query" - used for non-AJAX
        const paramSep = field.dataset.searchSeparator;
        const ajaxEnabled = form.dataset.ajaxSearchEnabled === 'true';
        
        // For AJAX full results, the base URL for query needs to be constructed slightly differently
        // It should not contain '/query' itself if we append '/query:searchTerm'
        // Example: if data-search-input is "/base/search/query", we want "/base/search"
        let ajaxBaseUrl = searchBaseUrl;
        if (ajaxBaseUrl.endsWith('/query')) {
            ajaxBaseUrl = ajaxBaseUrl.substring(0, ajaxBaseUrl.lastIndexOf('/query'));
        }


        if (minChars) {
            const invalidMessage = field.getAttribute('data-search-invalid');
            field.addEventListener('input', function() { // Changed from keydown to input for better UX with custom validity
                field.setCustomValidity(field.value.length >= minChars ? '' : invalidMessage);
            });
        }

        form.addEventListener('submit', function(event) {
            const query = field.value.trim();
            if (!field.checkValidity() || !query) {
                // If query is empty or field is invalid, prevent AJAX and let browser handle (or do nothing)
                if(!query && field.hasAttribute('required')) event.preventDefault(); // Prevent empty required field submission
                else if (!field.checkValidity()) event.preventDefault(); // Prevent invalid submission
                return; 
            }

            event.preventDefault(); // Prevent default for all valid submissions initially

            if (ajaxEnabled) {
                let ajaxUrl = ajaxBaseUrl + paramSep + encodeURIComponent(query) + '/ajax_results:1';
                fetchResults(ajaxUrl, query); // Pass query for display purposes
            } else {
                // Fallback to original behavior if AJAX is not enabled
                window.location.href = searchBaseUrl + paramSep + encodeURIComponent(query);
            }
        });

        // --- Search suggestions logic (mostly as before) ---
        let suggestionsContainer;
        let debounceTimer;
        const minLengthSuggestions = parseInt(field.dataset.minSuggestions) || 3;
        const suggestionsUrl = field.dataset.suggestionsUrl;

        if (suggestionsUrl && field.parentNode) { // Check parentNode for safety
            suggestionsContainer = document.createElement('div');
            suggestionsContainer.classList.add('simplesearch-suggestions-container');
            // Insert after the parent of the input field (div.search-wrapper)
            if (field.parentNode.parentNode) { // Ensure div.search-wrapper exists
                 field.parentNode.parentNode.insertBefore(suggestionsContainer, field.parentNode.nextSibling);
            } else { // Fallback if structure is simpler
                field.parentNode.insertBefore(suggestionsContainer, field.nextSibling);
            }

            field.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const query = this.value.trim();

                if (query.length < minLengthSuggestions) {
                    if (suggestionsContainer) clearSuggestions();
                    return;
                }

                debounceTimer = setTimeout(function() {
                    fetch(suggestionsUrl + '?query=' + encodeURIComponent(query), {
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok for suggestions');
                        return response.json();
                    })
                    .then(data => {
                        if (suggestionsContainer) displaySuggestions(data);
                    })
                    .catch(error => {
                        console.error('Error fetching search suggestions:', error);
                        if (suggestionsContainer) clearSuggestions();
                    });
                }, 250);
            });

            document.addEventListener('click', function(event) {
                if (suggestionsContainer && !field.contains(event.target) && !suggestionsContainer.contains(event.target)) {
                    clearSuggestions();
                }
            });
        }
        
        function displaySuggestions(suggestions) {
            if (!suggestionsContainer) return;
            clearSuggestions();
            if (suggestions && suggestions.length > 0) {
                const ul = document.createElement('ul');
                ul.classList.add('simplesearch-suggestions-list');
                suggestions.forEach(function(suggestion) {
                    const li = document.createElement('li');
                    li.classList.add('simplesearch-suggestion-item');
                    const a = document.createElement('a');
                    a.href = suggestion.url;
                    a.textContent = suggestion.title;
                    li.appendChild(a);
                    ul.appendChild(li);
                });
                suggestionsContainer.appendChild(ul);
            }
        }

        function clearSuggestions() {
            if (suggestionsContainer) {
                suggestionsContainer.innerHTML = '';
            }
        }
    });

    // --- AJAX Full Results Functions ---
    function fetchResults(url, queryForDisplay) {
        const resultsContainer = document.getElementById('simplesearch-ajax-results-container');
        if (!resultsContainer) {
            console.error('AJAX results container (#simplesearch-ajax-results-container) not found.');
            // Fallback to standard navigation if container is missing and it's a form submission context
            // This is tricky here, as fetchResults is called after preventDefault.
            // A more robust solution would be to check for container existence *before* preventDefault.
            // For now, just log error.
            return;
        }
        resultsContainer.innerHTML = '<p>Loading...</p>'; // Simple loading indicator

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok for full results.');
                return response.json();
            })
            .then(data => {
                renderSearchResults(data, resultsContainer, url);
            })
            .catch(error => {
                console.error('Error fetching search results:', error);
                resultsContainer.innerHTML = `<p>Error loading results. Query: ${queryForDisplay || ''}</p>`;
            });
    }

    function renderSearchResults(data, container, baseUrlForPagination) {
        container.innerHTML = ''; // Clear loading/previous results

        if (data.results && data.results.length > 0) {
            const summary = document.createElement('p');
            summary.className = 'simplesearch-results-summary'; // Added class for styling
            // Use the query from data if available, otherwise it might be undefined if not passed to fetchResults
            const displayQuery = data.query || (baseUrlForPagination.includes(paramSep) ? decodeURIComponent(baseUrlForPagination.split(paramSep).pop().split('/')[0]) : '');
            
            if (data.pagination.total_results === 1) {
                 summary.innerHTML = `Query: <strong>${displayQuery}</strong> found one result`; // Mimic Twig
            } else {
                 summary.innerHTML = `Query: <strong>${displayQuery}</strong> found ${data.pagination.total_results} results`; // Mimic Twig
            }
            container.appendChild(summary);

            data.results.forEach(result => {
                const itemDiv = document.createElement('div');
                itemDiv.classList.add('search-item');

                const titleH3 = document.createElement('h3');
                titleH3.classList.add('search-title');
                const link = document.createElement('a');
                link.href = result.url;
                link.innerHTML = result.title; // Changed from textContent to innerHTML
                titleH3.appendChild(link);
                itemDiv.appendChild(titleH3);

                const snippetP = document.createElement('p');
                snippetP.innerHTML = result.content_snippet; // Use innerHTML as snippet might have highlights later
                itemDiv.appendChild(snippetP);

                container.appendChild(itemDiv);
            });

            if (data.pagination && data.pagination.total_pages > 0) {
                 renderPagination(data.pagination, container, baseUrlForPagination);
            }
        } else {
            const displayQuery = data.query || (baseUrlForPagination.includes(paramSep) ? decodeURIComponent(baseUrlForPagination.split(paramSep).pop().split('/')[0]) : '');
            container.innerHTML = `<p>No results found for "${displayQuery}".</p>`;
        }
    }

    function renderPagination(paginationData, container, baseUrl) {
        if (paginationData.total_pages <= 1) return; // No pagination if only one page

        const paginationDiv = document.createElement('div');
        paginationDiv.classList.add('simplesearch-pagination');
        
        const paramSep = document.querySelector('input[data-search-input]')?.dataset.searchSeparator || ':';


        if (paginationData.current_page > 1) {
            const prevLink = document.createElement('a');
            prevLink.href = '#'; 
            prevLink.textContent = '<< Previous';
            prevLink.addEventListener('click', (e) => {
                e.preventDefault();
                fetchResults(updateQueryParam(baseUrl, 'page', paginationData.current_page - 1, paramSep), null);
            });
            paginationDiv.appendChild(prevLink);
            paginationDiv.appendChild(document.createTextNode(' '));
        }

        const pageInfo = document.createElement('span');
        pageInfo.textContent = `Page ${paginationData.current_page} of ${paginationData.total_pages}`;
        paginationDiv.appendChild(pageInfo);

        if (paginationData.current_page < paginationData.total_pages) {
            paginationDiv.appendChild(document.createTextNode(' '));
            const nextLink = document.createElement('a');
            nextLink.href = '#';
            nextLink.textContent = 'Next >>';
            nextLink.addEventListener('click', (e) => {
                e.preventDefault();
                fetchResults(updateQueryParam(baseUrl, 'page', paginationData.current_page + 1, paramSep), null);
            });
            paginationDiv.appendChild(nextLink);
        }
        container.appendChild(paginationDiv);
    }

    function updateQueryParam(url, key, value, paramSeparator) {
        // Ensure paramSeparator is defined, default to ':' if not.
        const sep = paramSeparator || ':';
        // Remove existing key parameter, ensuring it's a whole segment
        const keyPattern = new RegExp("(\\/)" + key + sep + "[^\\/]+", "i");
        let newUrl = url.replace(keyPattern, "");
    
        // Add the new key parameter
        // Ensure no double slashes if url ends with / after stripping
        if (newUrl.slice(-1) === '/') newUrl = newUrl.slice(0, -1);
        newUrl += "/" + key + sep + value;
        return newUrl;
    }

})());
