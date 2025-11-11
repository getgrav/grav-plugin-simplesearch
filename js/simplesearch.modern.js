/**
 * SimpleSearch Modern JavaScript
 * Enhanced search experience with instant search, autocomplete, and keyboard shortcuts
 */

class SimpleSearch {
    constructor(options = {}) {
        this.options = {
            instantSearch: options.instantSearch || false,
            instantSearchDelay: options.instantSearchDelay || 300,
            minCharacters: options.minCharacters || 3,
            autocomplete: options.autocomplete || false,
            autocompleteMax: options.autocompleteMax || 5,
            searchRoute: options.searchRoute || '/search',
            paramSeparator: options.paramSeparator || ':',
            enableKeyboardShortcuts: options.enableKeyboardShortcuts || true,
            ...options
        };

        this.searchInputs = [];
        this.autocompleteCache = new Map();
        this.debounceTimer = null;
        this.currentFocus = -1;

        this.init();
    }

    init() {
        // Find all search forms
        const forms = document.querySelectorAll('form[data-simplesearch-form]');

        forms.forEach(form => {
            const input = form.querySelector('input[name="searchfield"][data-search-input]');
            if (!input) return;

            this.searchInputs.push(input);
            this.setupSearchField(input, form);
        });

        // Setup keyboard shortcuts
        if (this.options.enableKeyboardShortcuts) {
            this.setupKeyboardShortcuts();
        }

        // Setup ARIA live region for screen readers
        this.setupAccessibility();
    }

    setupSearchField(input, form) {
        const minChars = parseInt(input.getAttribute('data-min')) || this.options.minCharacters;
        const invalidMsg = input.getAttribute('data-search-invalid') || 'Please enter at least ' + minChars + ' characters';

        // Instant search
        if (this.options.instantSearch) {
            input.addEventListener('input', (e) => {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.performInstantSearch(input, e.target.value);
                }, this.options.instantSearchDelay);
            });
        }

        // Autocomplete
        if (this.options.autocomplete) {
            this.setupAutocomplete(input, form);
        }

        // Form validation
        input.addEventListener('keydown', () => {
            if (input.value.length >= minChars) {
                input.setCustomValidity('');
            } else {
                input.setCustomValidity(invalidMsg);
            }
        });

        // Form submission
        form.addEventListener('submit', (e) => {
            e.preventDefault();

            if (input.checkValidity() && input.value.trim()) {
                this.navigateToSearch(input.value);
            }
        });

        // Clear button
        this.addClearButton(input);

        // Loading indicator
        this.addLoadingIndicator(input);
    }

    setupAutocomplete(input, form) {
        // Create autocomplete container
        const container = document.createElement('div');
        container.className = 'search-autocomplete';
        container.setAttribute('role', 'listbox');
        container.style.display = 'none';
        input.parentNode.insertBefore(container, input.nextSibling);

        // Listen for input
        input.addEventListener('input', (e) => {
            const query = e.target.value.trim();

            if (query.length < this.options.minCharacters) {
                container.style.display = 'none';
                return;
            }

            this.showAutocomplete(input, container, query);
        });

        // Handle keyboard navigation
        input.addEventListener('keydown', (e) => {
            const items = container.querySelectorAll('.autocomplete-item');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.currentFocus++;
                this.setActiveItem(items, this.currentFocus);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.currentFocus--;
                this.setActiveItem(items, this.currentFocus);
            } else if (e.key === 'Enter' && this.currentFocus > -1) {
                e.preventDefault();
                if (items[this.currentFocus]) {
                    items[this.currentFocus].click();
                }
            } else if (e.key === 'Escape') {
                container.style.display = 'none';
                this.currentFocus = -1;
            }
        });

        // Close on click outside
        document.addEventListener('click', (e) => {
            if (!input.contains(e.target) && !container.contains(e.target)) {
                container.style.display = 'none';
                this.currentFocus = -1;
            }
        });
    }

    async showAutocomplete(input, container, query) {
        // Check cache first
        if (this.autocompleteCache.has(query)) {
            this.renderAutocomplete(container, this.autocompleteCache.get(query), query, input);
            return;
        }

        // Fetch suggestions (this would need a backend endpoint)
        try {
            const response = await fetch(`${this.options.searchRoute}/autocomplete.json?q=${encodeURIComponent(query)}`);

            if (response.ok) {
                const suggestions = await response.json();
                this.autocompleteCache.set(query, suggestions);
                this.renderAutocomplete(container, suggestions, query, input);
            }
        } catch (error) {
            // Fallback: use recent searches from localStorage
            const recent = this.getRecentSearches().filter(s =>
                s.toLowerCase().includes(query.toLowerCase())
            );
            this.renderAutocomplete(container, recent.map(s => ({ title: s })), query, input);
        }
    }

    renderAutocomplete(container, suggestions, query, input) {
        container.innerHTML = '';

        if (!suggestions || suggestions.length === 0) {
            container.style.display = 'none';
            return;
        }

        const items = suggestions.slice(0, this.options.autocompleteMax);

        items.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            div.setAttribute('role', 'option');
            div.setAttribute('aria-selected', 'false');

            // Highlight matching text
            const text = item.title || item;
            const highlightedText = this.highlightText(text, query);
            div.innerHTML = highlightedText;

            div.addEventListener('click', () => {
                input.value = text;
                this.saveRecentSearch(text);
                this.navigateToSearch(text);
                container.style.display = 'none';
            });

            container.appendChild(div);
        });

        container.style.display = 'block';
        this.currentFocus = -1;
    }

    setActiveItem(items, index) {
        if (!items || items.length === 0) return;

        // Remove active class from all
        items.forEach(item => {
            item.classList.remove('active');
            item.setAttribute('aria-selected', 'false');
        });

        // Wrap around
        if (index >= items.length) this.currentFocus = 0;
        if (index < 0) this.currentFocus = items.length - 1;

        // Set active
        if (items[this.currentFocus]) {
            items[this.currentFocus].classList.add('active');
            items[this.currentFocus].setAttribute('aria-selected', 'true');
            items[this.currentFocus].scrollIntoView({ block: 'nearest' });
        }
    }

    performInstantSearch(input, query) {
        if (query.length < this.options.minCharacters) {
            this.hideInstantResults();
            return;
        }

        // Show loading
        this.showLoading(input);

        // Fetch results
        fetch(`${this.options.searchRoute}.json/query${this.options.paramSeparator}${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                this.hideLoading(input);
                this.displayInstantResults(data, query);
            })
            .catch(error => {
                this.hideLoading(input);
                console.error('Instant search error:', error);
            });
    }

    displayInstantResults(results, query) {
        let container = document.querySelector('.instant-search-results');

        if (!container) {
            container = document.createElement('div');
            container.className = 'instant-search-results';
            document.querySelector('.simplesearch').appendChild(container);
        }

        if (!results || results.length === 0) {
            container.innerHTML = '<p class="no-results">No instant results found.</p>';
            return;
        }

        let html = '<div class="instant-results-header">Quick Results</div>';
        results.slice(0, 5).forEach(result => {
            html += `
                <div class="instant-result-item">
                    <a href="${result.url}">
                        <div class="result-title">${this.highlightText(result.title, query)}</div>
                        ${result.excerpt ? `<div class="result-excerpt">${this.highlightText(result.excerpt, query)}</div>` : ''}
                    </a>
                </div>
            `;
        });

        container.innerHTML = html;
        container.style.display = 'block';
    }

    hideInstantResults() {
        const container = document.querySelector('.instant-search-results');
        if (container) {
            container.style.display = 'none';
        }
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl+K or Cmd+K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                if (this.searchInputs.length > 0) {
                    this.searchInputs[0].focus();
                    this.searchInputs[0].select();
                }
            }

            // Forward slash to focus search (like GitHub)
            if (e.key === '/' && !this.isTyping(e)) {
                e.preventDefault();
                if (this.searchInputs.length > 0) {
                    this.searchInputs[0].focus();
                }
            }

            // Escape to clear and blur search
            if (e.key === 'Escape') {
                this.searchInputs.forEach(input => {
                    if (document.activeElement === input) {
                        input.value = '';
                        input.blur();
                        this.hideInstantResults();
                    }
                });
            }
        });
    }

    isTyping(event) {
        const target = event.target;
        return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
               target.isContentEditable;
    }

    addClearButton(input) {
        const wrapper = input.parentElement;
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'search-clear';
        clearBtn.innerHTML = '&times;';
        clearBtn.setAttribute('aria-label', 'Clear search');
        clearBtn.style.display = 'none';

        input.addEventListener('input', () => {
            clearBtn.style.display = input.value ? 'inline-block' : 'none';
        });

        clearBtn.addEventListener('click', () => {
            input.value = '';
            input.focus();
            clearBtn.style.display = 'none';
            this.hideInstantResults();
        });

        wrapper.appendChild(clearBtn);
    }

    addLoadingIndicator(input) {
        const wrapper = input.parentElement;
        const loader = document.createElement('div');
        loader.className = 'search-loading';
        loader.innerHTML = '<div class="spinner"></div>';
        loader.style.display = 'none';
        wrapper.appendChild(loader);
    }

    showLoading(input) {
        const loader = input.parentElement.querySelector('.search-loading');
        if (loader) loader.style.display = 'inline-block';
    }

    hideLoading(input) {
        const loader = input.parentElement.querySelector('.search-loading');
        if (loader) loader.style.display = 'none';
    }

    navigateToSearch(query) {
        this.saveRecentSearch(query);
        const url = `${this.options.searchRoute}/query${this.options.paramSeparator}${encodeURIComponent(query)}`;
        window.location.href = url;
    }

    highlightText(text, query) {
        if (!query) return text;

        const regex = new RegExp(`(${query.split(',').map(q =>
            q.trim().replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
        ).join('|')})`, 'gi');

        return text.replace(regex, '<mark class="search-highlight">$1</mark>');
    }

    saveRecentSearch(query) {
        if (!query || !window.localStorage) return;

        let recent = this.getRecentSearches();
        recent = recent.filter(s => s !== query); // Remove duplicates
        recent.unshift(query); // Add to beginning
        recent = recent.slice(0, 10); // Keep only last 10

        localStorage.setItem('simplesearch_recent', JSON.stringify(recent));
    }

    getRecentSearches() {
        if (!window.localStorage) return [];

        try {
            const stored = localStorage.getItem('simplesearch_recent');
            return stored ? JSON.parse(stored) : [];
        } catch (e) {
            return [];
        }
    }

    setupAccessibility() {
        // Create ARIA live region for screen reader announcements
        const liveRegion = document.createElement('div');
        liveRegion.setAttribute('role', 'status');
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        liveRegion.className = 'sr-only';
        document.body.appendChild(liveRegion);

        this.liveRegion = liveRegion;
    }

    announceToScreenReader(message) {
        if (this.liveRegion) {
            this.liveRegion.textContent = message;
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSimpleSearch);
} else {
    initSimpleSearch();
}

function initSimpleSearch() {
    // Get config from data attributes or defaults
    const config = {
        instantSearch: document.body.dataset.instantSearch === 'true',
        autocomplete: document.body.dataset.autocomplete === 'true',
        searchRoute: document.body.dataset.searchRoute || '/search',
        paramSeparator: document.body.dataset.paramSeparator || ':',
        minCharacters: parseInt(document.body.dataset.minChars) || 3,
    };

    window.simpleSearch = new SimpleSearch(config);
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SimpleSearch;
}
