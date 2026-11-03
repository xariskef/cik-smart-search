/**
 * Smart Search - Voice Search with Advanced Features
 * 
 * Features:
 * - Animated microphone icon while listening
 * - Customizable background color
 * - Optional auto-submit on speech end
 * - Enhanced mobile and iOS Safari support
 * 
 * Browser Support: Chrome, Edge, Safari (iOS 14.5+), Android Chrome
 */

(function() {
    'use strict';
    
    // Check if browser supports speech recognition
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    
    if (!SpeechRecognition) {
        console.log('Smart Search: Voice search not supported in this browser');
        // Hide voice buttons if not supported
        document.addEventListener('DOMContentLoaded', function() {
            const voiceButtons = document.querySelectorAll('.cik-search-wrapper [title*="Voice"], .cik-search-wrapper [aria-label*="Voice"]');
            voiceButtons.forEach(btn => btn.style.display = 'none');
        });
        return;
    }
    
    // Get settings from localized script (passed from PHP)
    const settings = window.cikSearchVoice || {
        autoSubmit: false,
        listeningBgColor: '#22c55e',  // Default: green
        language: 'el-GR'  // Default: Greek
    };
    
    // Detect iOS
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        initVoiceSearch();
    });
    
    function initVoiceSearch() {
        const voiceButtons = document.querySelectorAll('.cik-search-wrapper [title*="Voice"], .cik-search-wrapper [aria-label*="Voice"]');
        
        if (voiceButtons.length === 0) {
            return;
        }
        
        voiceButtons.forEach(function(button) {
            // Use touchstart for better mobile responsiveness, fallback to click
            if ('ontouchstart' in window) {
                button.addEventListener('touchstart', handleVoiceSearch, { passive: false });
            } else {
                button.addEventListener('click', handleVoiceSearch);
            }
            button.style.cursor = 'pointer';
            button.style.transition = 'all 0.3s ease';
            button.style.WebkitTapHighlightColor = 'transparent'; // Remove iOS tap highlight
        });
    }
    
    function handleVoiceSearch(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const micIcon = this;
        const searchForm = micIcon.closest('form');
        if (!searchForm) return;
        
        const searchInput = searchForm.querySelector('input[type="search"]');
        if (!searchInput) return;
        
        // Check if already listening
        if (micIcon.dataset.listening === 'true') {
            console.log('🛑 Already listening...');
            return;
        }
        
        // Create recognition instance
        const recognition = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
        
        // iOS Safari specific configuration
        if (isIOS || isSafari) {
            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.maxAlternatives = 1;
        } else {
            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.maxAlternatives = 1;
        }
        
        // Set language
        recognition.lang = settings.language || document.documentElement.lang || 'el-GR';
        
        // Store original styles
        const originalBg = window.getComputedStyle(micIcon).backgroundColor;
        
        // Start listening
        try {
            recognition.start();
            console.log('🎤 Voice search: Listening... (Device: ' + (isIOS ? 'iOS' : isSafari ? 'Safari' : 'Other') + ')');
            
            // Apply listening state
            startListeningAnimation(micIcon);
            
        } catch (error) {
            console.error('Voice search error:', error);
            stopListeningAnimation(micIcon, originalBg);
            
            // iOS specific error handling
            if (isIOS && error.message.includes('not-allowed')) {
                alert('Please allow microphone access from your Safari settings (Settings > Safari > Microphone).');
            }
            return;
        }
        
        // On result
        recognition.onresult = function(event) {
            const transcript = event.results[0][0].transcript;
            const confidence = event.results[0][0].confidence;
            
            searchInput.value = transcript;
            console.log('✅ Voice search: "' + transcript + '" (confidence: ' + (confidence * 100).toFixed(1) + '%)');
            
            // Trigger input event for predictive search
            const inputEvent = new Event('input', { bubbles: true, cancelable: true });
            searchInput.dispatchEvent(inputEvent);
            
            // Focus input for user feedback
            searchInput.focus();
            
            // Auto-submit if enabled (with longer delay for iOS)
            if (settings.autoSubmit) {
                console.log('🚀 Auto-submitting search...');
                setTimeout(function() {
                    searchForm.submit();
                }, isIOS ? 800 : 500); // Longer delay for iOS
            }
        };
        
        // On end
        recognition.onend = function() {
            stopListeningAnimation(micIcon, originalBg);
            console.log('🛑 Voice search: Stopped');
        };
        
        // On error
        recognition.onerror = function(event) {
            console.error('❌ Voice search error:', event.error);
            stopListeningAnimation(micIcon, originalBg);
            
            // Show user-friendly error messages
            if (event.error === 'not-allowed' || event.error === 'permission-denied') {
                if (isIOS) {
                    alert('Please allow microphone access:\nSettings > Safari > Microphone > Allow for this website');
                } else {
                    alert('Please allow microphone access.');
                }
            } else if (event.error === 'no-speech') {
                console.log('⚠️ No speech detected. Please try again.');
                // Don't alert, just log - better UX
            } else if (event.error === 'aborted') {
                console.log('🛑 Voice search was cancelled.');
            } else if (event.error === 'network') {
                alert('Network error. Please check your connection.');
            } else if (event.error === 'audio-capture') {
                alert('Microphone is not available.');
            }
        };
        
        // On speech start
        recognition.onspeechstart = function() {
            console.log('🗣️ Speech detected...');
        };
        
        // On speech end
        recognition.onspeechend = function() {
            console.log('🤐 Speech ended...');
            // iOS sometimes needs manual stop
            if (isIOS) {
                try {
                    recognition.stop();
                } catch (e) {
                    // Already stopped
                }
            }
        };
        
        // On audio start (iOS specific)
        recognition.onaudiostart = function() {
            console.log('🎵 Audio capture started');
        };
        
        // On audio end (iOS specific)
        recognition.onaudioend = function() {
            console.log('🎵 Audio capture ended');
        };
    }
    
    /**
     * Start listening animation
     */
    function startListeningAnimation(micIcon) {
        // Change background color
        micIcon.style.backgroundColor = settings.listeningBgColor;
        micIcon.dataset.listening = 'true';
        
        // Add pulsing animation
        addPulseAnimation(micIcon);
    }
    
    /**
     * Stop listening animation
     */
    function stopListeningAnimation(micIcon, originalBg) {
        micIcon.style.backgroundColor = originalBg;
        micIcon.dataset.listening = 'false';
        
        // Remove animation
        removePulseAnimation(micIcon);
    }
    
    /**
     * Add pulse animation to icon
     */
    function addPulseAnimation(element) {
        // Create keyframes if not exists
        if (!document.getElementById('cik-search-pulse-animation')) {
            const style = document.createElement('style');
            style.id = 'cik-search-pulse-animation';
            style.textContent = `
                @keyframes cik-search-pulse {
                    0%, 100% {
                        opacity: 1;
                        transform: scale(1);
                    }
                    50% {
                        opacity: 0.7;
                        transform: scale(0.95);
                    }
                }
                .cik-search-pulse-active {
                    animation: cik-search-pulse 1s ease-in-out infinite;
                }
            `;
            document.head.appendChild(style);
        }
        
        element.classList.add('cik-search-pulse-active');
    }
    
    /**
     * Remove pulse animation
     */
    function removePulseAnimation(element) {
        element.classList.remove('cik-search-pulse-active');
    }
    
})();
