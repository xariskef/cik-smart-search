/**
 * Smart Search - Voice Search
 *
 * Uses the Web Speech API (SpeechRecognition) to fill the search field from
 * the microphone button. The button is hidden in browsers without support.
 *
 * Browser support: Chrome, Edge, Safari (iOS 14.5+), Android Chrome.
 */
(function () {
    'use strict';

    const BUTTON_SELECTOR = '.cik-search-wrapper .cik-search-voice-search';
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
        document.querySelectorAll(BUTTON_SELECTOR).forEach(function (button) {
            button.hidden = true;
            button.style.display = 'none';
        });
        return;
    }

    const settings = Object.assign({
        autoSubmit: false,
        listeningBgColor: '#22c55e',
        language: '',
        i18n: {}
    }, window.cikSearchVoice || {});

    const messages = Object.assign({
        permissionDenied: 'Please allow microphone access.',
        permissionDeniedIOS: 'Please allow microphone access: Settings > Safari > Microphone.',
        network: 'Network error. Please check your connection.',
        noMicrophone: 'Microphone is not available.'
    }, settings.i18n);

    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

    function startListeningState(button) {
        button.dataset.listening = 'true';
        button.setAttribute('aria-pressed', 'true');
        button.style.backgroundColor = settings.listeningBgColor;
        button.classList.add('cik-search-pulse-active');
    }

    function stopListeningState(button, originalBg) {
        button.dataset.listening = 'false';
        button.setAttribute('aria-pressed', 'false');
        button.style.backgroundColor = originalBg;
        button.classList.remove('cik-search-pulse-active');
    }

    function showError(error) {
        if (error === 'not-allowed' || error === 'permission-denied' || error === 'service-not-allowed') {
            window.alert(isIOS ? messages.permissionDeniedIOS : messages.permissionDenied);
        } else if (error === 'network') {
            window.alert(messages.network);
        } else if (error === 'audio-capture') {
            window.alert(messages.noMicrophone);
        }
        // 'no-speech' and 'aborted' are silent: the user can simply try again.
    }

    function handleVoiceSearch(e) {
        e.preventDefault();
        e.stopPropagation();

        const button = e.currentTarget;
        const searchForm = button.closest('form');
        const searchInput = searchForm ? searchForm.querySelector('input[type="search"]') : null;

        if (!searchInput || button.dataset.listening === 'true') {
            return;
        }

        const recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.lang = settings.language || document.documentElement.lang || 'en-US';

        const originalBg = window.getComputedStyle(button).backgroundColor;

        recognition.onresult = function (event) {
            searchInput.value = event.results[0][0].transcript;

            // Let the predictive search react to the new value.
            searchInput.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
            searchInput.focus();

            if (settings.autoSubmit) {
                // iOS needs a little longer before the form can be submitted.
                setTimeout(function () {
                    searchForm.submit();
                }, isIOS ? 800 : 500);
            }
        };

        recognition.onend = function () {
            stopListeningState(button, originalBg);
        };

        recognition.onerror = function (event) {
            stopListeningState(button, originalBg);
            showError(event.error);
        };

        // iOS Safari does not always stop on its own after speech ends.
        recognition.onspeechend = function () {
            if (isIOS) {
                try {
                    recognition.stop();
                } catch (err) {
                    // Already stopped.
                }
            }
        };

        try {
            recognition.start();
            startListeningState(button);
        } catch (err) {
            stopListeningState(button, originalBg);
            showError(err && err.name === 'NotAllowedError' ? 'not-allowed' : '');
        }
    }

    document.querySelectorAll(BUTTON_SELECTOR).forEach(function (button) {
        // touchstart responds faster on mobile; click everywhere else.
        if ('ontouchstart' in window) {
            button.addEventListener('touchstart', handleVoiceSearch, { passive: false });
        }
        button.addEventListener('click', handleVoiceSearch);
    });
})();
