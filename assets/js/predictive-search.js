/**
 * Smart Search - Predictive Search
 * Live search suggestions with AJAX
 */
(function() {
    'use strict';
    
    const searchForm = document.querySelector('.cik-search-form');
    if (!searchForm) return;
    
    const searchInput = searchForm.querySelector('input[type="search"]');
    if (!searchInput) return;
    
    const clearBtn = searchForm.querySelector('.search-clear-btn');
    
    // Get tags container for suggested keywords
    const tagsContainer = document.querySelector('.cik-search-tags');
    const tagsContent = tagsContainer ? tagsContainer.querySelector('.cik-search-tags-list') : null;
    let originalTagsHTML = tagsContent ? tagsContent.innerHTML : '';
    
    // Get predictive results container (inline, not dropdown)
    const predictiveResultsWrapper = document.querySelector('.cik-search-predictive-results');
    const predictiveResultsContainer = predictiveResultsWrapper ? predictiveResultsWrapper.querySelector('.predictive-results-container') : null;
    
    // Keep old dropdown for backwards compatibility (hidden)
    const suggestionsContainer = document.createElement('div');
    suggestionsContainer.className = 'cik-search-suggestions';
    suggestionsContainer.style.display = 'none';
    searchForm.appendChild(suggestionsContainer);
    
    let debounceTimer;
    let currentRequest;
    let selectedIndex = -1;
    
    // Settings from WordPress
    const settings = window.cikSearchPredictive || {
        enabled: true,
        minChars: 3,
        delay: 300,
        maxResults: 5,
        postTypes: ['post', 'page', 'product'],
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: ''
    };
    
    
    /**
     * Toggle clear button visibility
     */
    function toggleClearButton() {
        if (clearBtn) {
            clearBtn.style.display = searchInput.value.trim().length > 0 ? 'flex' : 'none';
        }
    }
    
    /**
     * Remove Greek accents for matching
     */
    function removeAccents(str) {
        const accents = {
            'ά': 'α', 'έ': 'ε', 'ή': 'η', 'ί': 'ι', 'ό': 'ο', 'ύ': 'υ', 'ώ': 'ω',
            'Ά': 'Α', 'Έ': 'Ε', 'Ή': 'Η', 'Ί': 'Ι', 'Ό': 'Ο', 'Ύ': 'Υ', 'Ώ': 'Ω',
            'ΐ': 'ι', 'ΰ': 'υ', 'ϊ': 'ι', 'ϋ': 'υ'
        };
        return str.replace(/[άέήίόύώΆΈΉΊΌΎΏΐΰϊϋ]/g, function(match) {
            return accents[match] || match;
        });
    }
    
    /**
     * Check if text matches query (accent-insensitive)
     */
    function matchesQuery(text, query) {
        const normalizedText = removeAccents(text.toLowerCase());
        const normalizedQuery = removeAccents(query.toLowerCase());
        return normalizedText.includes(normalizedQuery);
    }
    
    /**
     * Filter preloaded keywords on the frontend (instant, no AJAX)
     * @param {string} query - Query to filter keywords
     */
    function filterKeywords(query) {
        // Normalize query
        query = query || '';
        
        // Don't show keywords for empty query - only when user types
        if (query.length === 0) {
            return;
        }
        
        // Get preloaded keywords from settings
        const preloaded = settings.preloadedKeywords || { manual: [], popular: [] };
        const maxDisplay = parseInt(settings.maxDisplayKeywords) || 0;
        
        // Normalize query once for all comparisons
        const normalizedQuery = removeAccents(query.toLowerCase());
        
        // Filter keywords on frontend
        const filtered = {
            manual: [],
            popular: []
        };
        
        // Filter popular searches
        preloaded.popular.forEach(item => {
            const normalizedText = removeAccents(item.text.toLowerCase());
            if (normalizedText.includes(normalizedQuery)) {
                filtered.popular.push(item);
            }
        });
        
        // Filter manual keywords
        preloaded.manual.forEach(item => {
            const normalizedText = removeAccents(item.text.toLowerCase());
            if (normalizedText.includes(normalizedQuery)) {
                filtered.manual.push(item);
            }
        });
        
        // Apply display limit: popular first, then manual
        if (maxDisplay > 0) {
            const popCount = filtered.popular.length;
            if (popCount >= maxDisplay) {
                filtered.popular = filtered.popular.slice(0, maxDisplay);
                filtered.manual = [];
            } else {
                filtered.manual = filtered.manual.slice(0, maxDisplay - popCount);
            }
        }
        
        // Display filtered results
        displaySuggestedKeywords(filtered);
    }
    
    /**
     * Display suggested keywords (manual + popular searches)
     * Server-side filtering already applied, just render
     */
    function displaySuggestedKeywords(data) {
        
        if (!tagsContent) {
            return; // No tags container available
        }
        
        const manual = data.manual || [];
        const popular = data.popular || [];
        
        // Check if we have any results
        if (manual.length === 0 && popular.length === 0) {
            // Restore original tags
            tagsContent.innerHTML = originalTagsHTML;
            return;
        }
        
        let html = '';
        
        // Manual Keywords
        if (manual.length > 0) {
            manual.forEach((item) => {
                html += `
                    <div class="cik-search-tag suggestion-keyword-item" data-query="${item.text}">
                        <span>${item.text}</span>
                    </div>
                `;
            });
        }
        
        // Popular Searches
        if (popular.length > 0) {
            popular.forEach((item) => {
                html += `
                    <div class="cik-search-tag suggestion-keyword-item" data-query="${item.text}">
                        <span>${item.text}</span>
                    </div>
                `;
            });
        }
        
        // Replace tags content
        tagsContent.innerHTML = html;
        
        // Add click handlers
        const keywordItems = tagsContent.querySelectorAll('.suggestion-keyword-item');
        keywordItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const query = this.dataset.query;
                searchInput.value = query;
                searchForm.submit();
            });
        });
    }
    
    /**
     * Show loading state
     */
    function showLoading() {
        if (predictiveResultsContainer) {
            predictiveResultsContainer.innerHTML = `
                <div class="cik-search-predictive-loading">
                    <div class="cik-search-predictive-spinner"></div>
                    <span class="cik-search-predictive-loading-text">${(settings.i18n && settings.i18n.searching) ? settings.i18n.searching : 'Search...'}</span>
                </div>
            `;
            predictiveResultsWrapper.classList.remove('hidden');
        }
    }
    
    /**
     * Fetch search suggestions
     */
    function fetchSuggestions(query) {
        // Show loading state
        showLoading();
        
        // Cancel previous request
        if (currentRequest) {
            currentRequest.abort();
        }
        
        // Build AJAX URL
        const ajaxUrl = settings.ajaxUrl || '/wp-admin/admin-ajax.php';
        const nonce = settings.nonce || '';
        const url = ajaxUrl + '?action=cik_search_predictive_search&query=' + encodeURIComponent(query) + '&nonce=' + nonce;
        
        // Create new request
        currentRequest = new XMLHttpRequest();
        currentRequest.open('GET', url);
        
        currentRequest.onload = function() {
            if (currentRequest.status === 200) {
                try {
                    const response = JSON.parse(currentRequest.responseText);
                    
                    if (response.success) {
                        displaySuggestions(response.data);
                    } else {
                        if (predictiveResultsWrapper) {
                            predictiveResultsWrapper.classList.add('hidden');
                        }
                    }
                } catch (e) {
                    console.error('Error parsing search results:', e);
                    if (predictiveResultsWrapper) {
                        predictiveResultsWrapper.classList.add('hidden');
                    }
                }
            } else {
                if (predictiveResultsWrapper) {
                    predictiveResultsWrapper.classList.add('hidden');
                }
            }
        };
        
        currentRequest.onerror = function() {
            if (predictiveResultsWrapper) {
                predictiveResultsWrapper.classList.add('hidden');
            }
        };
        
        currentRequest.send();
    }
    
    /**
     * Display search suggestions
     */
    function displaySuggestions(results) {
        selectedIndex = -1;
        
        if (!results || results.length === 0) {
            if (predictiveResultsWrapper) {
                predictiveResultsWrapper.classList.add('hidden');
            }
            return;
        }
        
        if (!predictiveResultsContainer) return;
        
        let html = '';
        
        results.forEach((result, index) => {
            const isTaxonomy = result.taxonomy === true;
            const taxonomyBadge = isTaxonomy && result.count ? `<span class="cik-search-result-count">(${result.count})</span>` : '';
            
            html += `
                <a href="${result.url}" class="cik-search-result-item" data-index="${index}">
                    ${result.image && !isTaxonomy ? `
                        <div class="cik-search-result-thumb">
                            <img src="${result.image}" alt="${result.title}">
                        </div>
                    ` : ''}
                    ${!result.image && !isTaxonomy ? `
                        <div class="cik-search-result-icon cik-search-result-icon--doc">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                    ` : ''}
                    ${isTaxonomy ? `
                        <div class="cik-search-result-icon cik-search-result-icon--taxonomy">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                        </div>
                    ` : ''}
                    <div class="cik-search-result-body">
                        <div class="cik-search-result-title">
                            ${result.title} ${taxonomyBadge}
                        </div>
                        ${result.matched_content ? `
                            <div class="cik-search-result-snippet">
                                ${result.matched_content}
                            </div>
                        ` : ''}
                        ${result.excerpt && !result.matched_content ? `
                            <div class="cik-search-result-snippet">
                                ${result.excerpt}
                            </div>
                        ` : ''}
                        <span class="cik-search-result-badge">
                            ${(settings.typeLabels && result.post_type && settings.typeLabels[result.post_type]) ? settings.typeLabels[result.post_type] : result.type}
                        </span>
                    </div>
                    <svg class="cik-search-result-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            `;
        });
        
        predictiveResultsContainer.innerHTML = html;
        predictiveResultsWrapper.classList.remove('hidden');
    }
    
    /**
     * Handle keyboard navigation
     */
    function handleKeyNavigation(e) {
        if (!predictiveResultsContainer) return;
        
        const items = predictiveResultsContainer.querySelectorAll('a[data-index]');
        
        if (items.length === 0) return;
        
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                updateSelection(items);
                break;
                
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(items);
                break;
                
            case 'Enter':
                if (selectedIndex >= 0) {
                    e.preventDefault();
                    window.location.href = items[selectedIndex].getAttribute('href');
                }
                // Otherwise let form submit normally
                break;
                
            case 'Escape':
                if (predictiveResultsWrapper) {
                    predictiveResultsWrapper.classList.add('hidden');
                }
                selectedIndex = -1;
                break;
        }
    }
    
    /**
     * Update visual selection
     */
    function updateSelection(items) {
        items.forEach((item, index) => {
            if (index === selectedIndex) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        });
    }
    
    /**
     * Input event handler
     */
    searchInput.addEventListener('input', function(e) {
        const query = this.value.trim();
        
        toggleClearButton();
        
        clearTimeout(debounceTimer);
        
        // If empty, restore original tags and hide predictive results
        if (query.length === 0) {
            suggestionsContainer.style.display = 'none';
            if (predictiveResultsWrapper) {
                predictiveResultsWrapper.classList.add('hidden');
            }
            if (tagsContent && originalTagsHTML) {
                tagsContent.innerHTML = originalTagsHTML;
            }
            return;
        }
        
        // If query is short (1-2 chars), filter keywords instantly (no debounce, no AJAX)
        if (query.length < settings.minChars) {
            suggestionsContainer.style.display = 'none';
            if (predictiveResultsWrapper) {
                predictiveResultsWrapper.classList.add('hidden');
            }
            
            // Instant frontend filtering (no delay needed)
            filterKeywords(query);
            return;
        }
        
        // If query is long enough (3+ chars), use predictive search + keywords
        if (query.length >= settings.minChars && settings.enabled) {
            // Filter keywords instantly (no debounce, no AJAX)
            filterKeywords(query);
            
            // Fetch predictive search results (posts/products) with debounce
            debounceTimer = setTimeout(() => {
                fetchSuggestions(query);
            }, settings.delay);
        }
    });
    
    /**

     * Blur event handler - restore original tags only if input is empty
     */
    searchInput.addEventListener('blur', function(e) {
        // Delay to allow click on suggestions
        setTimeout(() => {
            // Only restore original tags if input is empty
            if (searchInput.value.trim().length === 0 && tagsContent && originalTagsHTML) {
                tagsContent.innerHTML = originalTagsHTML;
            }
        }, 200);
    });
    
    /**
     * Clear button click handler
     */
    if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            suggestionsContainer.style.display = 'none';
            if (predictiveResultsWrapper) {
                predictiveResultsWrapper.classList.add('hidden');
            }
            toggleClearButton();
            
            // Restore original tags after clear
            if (tagsContent && originalTagsHTML) {
                tagsContent.innerHTML = originalTagsHTML;
            }
        });
    }
    
    /**
     * Keyboard navigation
     */
    searchInput.addEventListener('keydown', handleKeyNavigation);
    
    /**
     * Close suggestions when clicking outside
     */
    document.addEventListener('click', function(e) {
        if (!searchForm.contains(e.target)) {
            suggestionsContainer.style.display = 'none';
            if (predictiveResultsWrapper) {
                predictiveResultsWrapper.classList.add('hidden');
            }
            // Restore original tags if clicking outside
            if (tagsContent && originalTagsHTML && searchInput.value.trim().length === 0) {
                tagsContent.innerHTML = originalTagsHTML;
            }
        }
    });
    
    /**
     * Close suggestions on form submit
     */
    searchForm.addEventListener('submit', function() {
        suggestionsContainer.style.display = 'none';
        if (predictiveResultsWrapper) {
            predictiveResultsWrapper.classList.add('hidden');
        }
    });
    
})();
