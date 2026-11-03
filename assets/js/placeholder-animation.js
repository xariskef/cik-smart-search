/**
 * Smart Search - Placeholder Animation
 * Typewriter effect for search placeholder
 */
(function() {
    'use strict';
    
    // Get the search input
    const searchInput = document.querySelector('.cik-search-form input[type="search"]');
    
    if (!searchInput) {
        return;
    }
    
    // Get placeholder texts from localized data
    const placeholders = window.cikSearchPlaceholders || [];
    
    if (placeholders.length === 0) {
        return;
    }
    
    let currentIndex = 0;
    let currentText = '';
    let isDeleting = false;
    let charIndex = 0;
    let typingSpeed = 100;
    let deletingSpeed = 50;
    let delayBetweenWords = 2000;
    let animationTimeout = null;
    let isAnimating = false;
    
    // Check if we have only one placeholder
    const singlePlaceholder = placeholders.length === 1;
    
    function type() {
        const currentPlaceholder = placeholders[currentIndex];
        
        if (isDeleting) {
            // Deleting
            currentText = currentPlaceholder.substring(0, charIndex - 1);
            charIndex--;
        } else {
            // Typing
            currentText = currentPlaceholder.substring(0, charIndex + 1);
            charIndex++;
        }
        
        // Update placeholder
        searchInput.setAttribute('placeholder', currentText);
        
        // Determine speed
        let speed = isDeleting ? deletingSpeed : typingSpeed;
        
        // Check if word is complete
        if (!isDeleting && charIndex === currentPlaceholder.length) {
            // If single placeholder, stop here - don't delete or loop
            if (singlePlaceholder) {
                isAnimating = false;
                return; // Stop animation
            }
            
            // Pause at end of word (for multiple placeholders)
            speed = delayBetweenWords;
            isDeleting = true;
        } else if (isDeleting && charIndex === 0) {
            // Move to next word
            isDeleting = false;
            currentIndex = (currentIndex + 1) % placeholders.length;
            speed = 500; // Small pause before starting next word
        }
        
        animationTimeout = setTimeout(type, speed);
    }
    
    function stopAnimation() {
        if (animationTimeout) {
            clearTimeout(animationTimeout);
            animationTimeout = null;
        }
        isAnimating = false;
    }
    
    function startAnimation() {
        if (isAnimating) return; // Already running
        
        // Reset to initial state
        currentIndex = 0;
        charIndex = 0;
        isDeleting = false;
        currentText = '';
        isAnimating = true;
        
        type();
    }
    
    // Only start animation if input is not focused
    searchInput.addEventListener('focus', function() {
        // Stop animation and show full placeholder
        stopAnimation();
        searchInput.setAttribute('placeholder', placeholders[0]);
    });
    
    searchInput.addEventListener('blur', function() {
        // Resume animation if input is empty
        if (searchInput.value === '') {
            startAnimation();
        }
    });
    
    // Start the animation on page load
    if (document.activeElement !== searchInput) {
        startAnimation();
    }
    
})();
