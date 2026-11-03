/**
 * Smart Search - Placeholder Animation
 *
 * Typewriter effect that cycles through the placeholder texts set in the
 * admin. With a single text it is typed once and stays. The animation pauses
 * while the field has focus.
 */
(function () {
    'use strict';

    const placeholders = Array.isArray(window.cikSearchPlaceholders) ? window.cikSearchPlaceholders : [];
    if (placeholders.length === 0) {
        return;
    }

    const TYPING_SPEED = 100;
    const DELETING_SPEED = 50;
    const PAUSE_AFTER_WORD = 2000;
    const PAUSE_BEFORE_WORD = 500;

    // Respect users who prefer reduced motion: show the first text, no animation.
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function animate(searchInput) {
        let currentIndex = 0;
        let charIndex = 0;
        let isDeleting = false;
        let timeout = null;
        let isAnimating = false;

        function type() {
            const text = placeholders[currentIndex];
            charIndex += isDeleting ? -1 : 1;
            searchInput.setAttribute('placeholder', text.substring(0, charIndex));

            let speed = isDeleting ? DELETING_SPEED : TYPING_SPEED;

            if (!isDeleting && charIndex === text.length) {
                if (placeholders.length === 1) {
                    isAnimating = false;
                    return; // Single text: stop once fully typed.
                }
                speed = PAUSE_AFTER_WORD;
                isDeleting = true;
            } else if (isDeleting && charIndex === 0) {
                isDeleting = false;
                currentIndex = (currentIndex + 1) % placeholders.length;
                speed = PAUSE_BEFORE_WORD;
            }

            timeout = setTimeout(type, speed);
        }

        function stop() {
            clearTimeout(timeout);
            timeout = null;
            isAnimating = false;
        }

        function start() {
            if (isAnimating) {
                return;
            }
            currentIndex = 0;
            charIndex = 0;
            isDeleting = false;
            isAnimating = true;
            type();
        }

        searchInput.addEventListener('focus', function () {
            stop();
            searchInput.setAttribute('placeholder', placeholders[0]);
        });

        searchInput.addEventListener('blur', function () {
            if (searchInput.value === '') {
                start();
            }
        });

        if (document.activeElement !== searchInput) {
            start();
        }
    }

    document.querySelectorAll('.cik-search-form input[type="search"]').forEach(function (input) {
        if (reduceMotion) {
            input.setAttribute('placeholder', placeholders[0]);
        } else {
            animate(input);
        }
    });
})();
