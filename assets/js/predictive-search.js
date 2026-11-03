/**
 * Smart Search - Predictive Search
 *
 * - Short queries (below the minimum length) filter the preloaded suggested
 *   keywords in the browser, without any request.
 * - Longer queries fetch matching posts/terms from admin-ajax.php (debounced,
 *   previous request aborted, responses cached per query for the page view).
 *
 * All untrusted values (titles, keywords, popular searches typed by visitors)
 * are written with textContent / DOM APIs, never concatenated into HTML.
 */
(function () {
    'use strict';

    const settings = Object.assign({
        enabled: true,
        minChars: 3,
        delay: 300,
        ajaxUrl: '',
        nonce: '',
        preloadedKeywords: { manual: [], popular: [] },
        maxDisplayKeywords: 0,
        typeLabels: {},
        i18n: {}
    }, window.cikSearchPredictive || {});

    const SVG_NS = 'http://www.w3.org/2000/svg';
    const ICON_DOC = 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z';
    const ICON_TAG = 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z';
    const ICON_ARROW = 'M9 5l7 7-7 7';

    /**
     * Remove Greek accents for accent-insensitive matching.
     */
    function removeAccents(str) {
        const accents = {
            'ά': 'α', 'έ': 'ε', 'ή': 'η', 'ί': 'ι', 'ό': 'ο', 'ύ': 'υ', 'ώ': 'ω',
            'Ά': 'Α', 'Έ': 'Ε', 'Ή': 'Η', 'Ί': 'Ι', 'Ό': 'Ο', 'Ύ': 'Υ', 'Ώ': 'Ω',
            'ΐ': 'ι', 'ΰ': 'υ', 'ϊ': 'ι', 'ϋ': 'υ'
        };
        return str.replace(/[άέήίόύώΆΈΉΊΌΎΏΐΰϊϋ]/g, function (match) {
            return accents[match] || match;
        });
    }

    function normalize(str) {
        return removeAccents(String(str).toLowerCase());
    }

    /**
     * Build an inline SVG icon (static paths only).
     */
    function createIcon(className, pathData, strokeWidth) {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        if (className) {
            svg.setAttribute('class', className);
        }

        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
        path.setAttribute('stroke-width', strokeWidth);
        path.setAttribute('d', pathData);
        svg.appendChild(path);

        return svg;
    }

    function createElement(tag, className, text) {
        const el = document.createElement(tag);
        if (className) {
            el.className = className;
        }
        if (typeof text !== 'undefined' && text !== null) {
            el.textContent = String(text);
        }
        return el;
    }

    /**
     * Wire up one search box (the shortcode can be used more than once per page).
     */
    function initSearchBox(wrapper) {
        const searchForm = wrapper.querySelector('.cik-search-form');
        const searchInput = searchForm ? searchForm.querySelector('input[type="search"]') : null;
        if (!searchInput) {
            return;
        }

        const clearBtn = searchForm.querySelector('.search-clear-btn');
        const tagsContent = wrapper.querySelector('.cik-search-tags-list');
        const resultsWrapper = wrapper.querySelector('.cik-search-predictive-results');
        const resultsContainer = resultsWrapper ? resultsWrapper.querySelector('.predictive-results-container') : null;

        // Keep a copy of the server-rendered tags so they can be restored.
        const originalTags = tagsContent ? Array.prototype.map.call(tagsContent.childNodes, function (node) {
            return node.cloneNode(true);
        }) : [];

        const responseCache = {};
        let debounceTimer;
        let currentRequest;
        let selectedIndex = -1;

        function restoreTags() {
            if (!tagsContent || originalTags.length === 0) {
                return;
            }
            tagsContent.textContent = '';
            originalTags.forEach(function (node) {
                tagsContent.appendChild(node.cloneNode(true));
            });
        }

        function hideResults() {
            if (resultsWrapper) {
                resultsWrapper.classList.add('hidden');
            }
            selectedIndex = -1;
        }

        function toggleClearButton() {
            if (clearBtn) {
                clearBtn.style.display = searchInput.value.trim().length > 0 ? 'flex' : 'none';
            }
        }

        /**
         * Filter the preloaded keywords in the browser (no request).
         */
        function filterKeywords(query) {
            if (!query) {
                return;
            }

            const preloaded = settings.preloadedKeywords || {};
            const maxDisplay = parseInt(settings.maxDisplayKeywords, 10) || 0;
            const normalizedQuery = normalize(query);
            const matches = function (item) {
                return item && typeof item.text === 'string' && normalize(item.text).indexOf(normalizedQuery) !== -1;
            };

            let popular = (preloaded.popular || []).filter(matches);
            let manual = (preloaded.manual || []).filter(matches);

            // Display limit: popular searches first, then manual keywords.
            if (maxDisplay > 0) {
                popular = popular.slice(0, maxDisplay);
                manual = manual.slice(0, Math.max(0, maxDisplay - popular.length));
            }

            displaySuggestedKeywords(manual.concat(popular));
        }

        /**
         * Render suggested keywords in place of the static tags.
         */
        function displaySuggestedKeywords(items) {
            if (!tagsContent) {
                return;
            }

            if (items.length === 0) {
                restoreTags();
                return;
            }

            tagsContent.textContent = '';
            items.forEach(function (item) {
                const tag = createElement('div', 'cik-search-tag suggestion-keyword-item');
                tag.setAttribute('role', 'button');
                tag.setAttribute('tabindex', '0');
                tag.appendChild(createElement('span', '', item.text));

                const submitKeyword = function (e) {
                    e.preventDefault();
                    searchInput.value = item.text;
                    searchForm.submit();
                };
                tag.addEventListener('click', submitKeyword);
                tag.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        submitKeyword(e);
                    }
                });

                tagsContent.appendChild(tag);
            });
        }

        function showLoading() {
            if (!resultsContainer) {
                return;
            }

            const loading = createElement('div', 'cik-search-predictive-loading');
            loading.appendChild(createElement('div', 'cik-search-predictive-spinner'));
            loading.appendChild(createElement('span', 'cik-search-predictive-loading-text', settings.i18n.searching || 'Searching...'));

            resultsContainer.textContent = '';
            resultsContainer.appendChild(loading);
            resultsWrapper.classList.remove('hidden');
        }

        /**
         * Fetch predictive results from the server.
         */
        function fetchResults(query) {
            if (Object.prototype.hasOwnProperty.call(responseCache, query)) {
                displayResults(responseCache[query]);
                return;
            }

            showLoading();

            if (currentRequest) {
                currentRequest.abort();
            }

            const url = settings.ajaxUrl +
                '?action=cik_search_predictive_search' +
                '&query=' + encodeURIComponent(query) +
                '&nonce=' + encodeURIComponent(settings.nonce);

            const request = new XMLHttpRequest();
            currentRequest = request;
            request.open('GET', url);

            request.onload = function () {
                if (request !== currentRequest) {
                    return; // A newer request has been sent meanwhile.
                }
                currentRequest = null;

                let response = null;
                if (request.status === 200) {
                    try {
                        response = JSON.parse(request.responseText);
                    } catch (e) {
                        response = null;
                    }
                }

                if (response && response.success && Array.isArray(response.data)) {
                    responseCache[query] = response.data;
                    displayResults(response.data);
                } else {
                    hideResults();
                }
            };

            request.onerror = function () {
                if (request === currentRequest) {
                    currentRequest = null;
                    hideResults();
                }
            };

            request.send();
        }

        /**
         * Build one result row.
         */
        function createResultItem(result, index) {
            const isTaxonomy = result.taxonomy === true;

            const link = createElement('a', 'cik-search-result-item');
            link.href = result.url;
            link.setAttribute('data-index', String(index));

            if (!isTaxonomy && result.image) {
                const thumb = createElement('div', 'cik-search-result-thumb');
                const img = document.createElement('img');
                img.src = result.image;
                img.alt = '';
                img.loading = 'lazy';
                thumb.appendChild(img);
                link.appendChild(thumb);
            } else {
                const modifier = isTaxonomy ? 'taxonomy' : 'doc';
                const icon = createElement('div', 'cik-search-result-icon cik-search-result-icon--' + modifier);
                icon.appendChild(createIcon('', isTaxonomy ? ICON_TAG : ICON_DOC, isTaxonomy ? '2' : '1.5'));
                link.appendChild(icon);
            }

            const body = createElement('div', 'cik-search-result-body');

            const title = createElement('div', 'cik-search-result-title', result.title);
            if (isTaxonomy && result.count) {
                title.appendChild(document.createTextNode(' '));
                title.appendChild(createElement('span', 'cik-search-result-count', '(' + parseInt(result.count, 10) + ')'));
            }
            body.appendChild(title);

            if (result.matched_content) {
                // matched_content is built on the server from esc_html()-escaped
                // segments; the only markup it contains is <mark> and <strong>.
                const snippet = createElement('div', 'cik-search-result-snippet');
                snippet.innerHTML = result.matched_content;
                body.appendChild(snippet);
            } else if (result.excerpt) {
                body.appendChild(createElement('div', 'cik-search-result-snippet', result.excerpt));
            }

            const typeLabel = (settings.typeLabels && settings.typeLabels[result.post_type]) || result.type;
            if (typeLabel) {
                body.appendChild(createElement('span', 'cik-search-result-badge', typeLabel));
            }

            link.appendChild(body);
            link.appendChild(createIcon('cik-search-result-arrow', ICON_ARROW, '2'));

            return link;
        }

        function displayResults(results) {
            selectedIndex = -1;

            if (!resultsContainer || !results || results.length === 0) {
                hideResults();
                return;
            }

            resultsContainer.textContent = '';
            results.forEach(function (result, index) {
                resultsContainer.appendChild(createResultItem(result, index));
            });
            resultsWrapper.classList.remove('hidden');
        }

        function updateSelection(items) {
            Array.prototype.forEach.call(items, function (item, index) {
                item.classList.toggle('selected', index === selectedIndex);
            });
        }

        function handleKeyNavigation(e) {
            if (!resultsContainer) {
                return;
            }

            const items = resultsContainer.querySelectorAll('a[data-index]');
            if (items.length === 0) {
                return;
            }

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
                    // Without a selected row the form submits normally.
                    if (selectedIndex >= 0) {
                        e.preventDefault();
                        window.location.href = items[selectedIndex].getAttribute('href');
                    }
                    break;

                case 'Escape':
                    hideResults();
                    break;
            }
        }

        searchInput.addEventListener('input', function () {
            const query = this.value.trim();

            toggleClearButton();
            clearTimeout(debounceTimer);

            if (query.length === 0) {
                hideResults();
                restoreTags();
                return;
            }

            // Keywords are filtered instantly in the browser.
            filterKeywords(query);

            if (query.length < settings.minChars || !settings.enabled) {
                hideResults();
                return;
            }

            debounceTimer = setTimeout(function () {
                fetchResults(query);
            }, parseInt(settings.delay, 10) || 300);
        });

        // Restore the original tags when the field is left empty.
        searchInput.addEventListener('blur', function () {
            setTimeout(function () {
                if (searchInput.value.trim().length === 0) {
                    restoreTags();
                }
            }, 200);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                searchInput.value = '';
                hideResults();
                toggleClearButton();
                restoreTags();
            });
        }

        searchInput.addEventListener('keydown', handleKeyNavigation);

        // Close results when clicking outside this search box.
        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) {
                hideResults();
                if (searchInput.value.trim().length === 0) {
                    restoreTags();
                }
            }
        });

        searchForm.addEventListener('submit', hideResults);
    }

    Array.prototype.forEach.call(document.querySelectorAll('.cik-search-wrapper'), initSearchBox);
})();
