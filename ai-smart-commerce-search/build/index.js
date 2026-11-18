/**
 * AI Smart Commerce Search — search drawer
 * Vanilla JS + WooCommerce Store API cart.
 * Uses wp.i18n when available; falls back to ascConfig.i18n.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------------ */
  /* i18n helper                                                          */
  /* ------------------------------------------------------------------ */
  const cfg = window.ascConfig || {};
  const i18n = cfg.i18n || {};

  function __(str, domain) {
    if (window.wp && window.wp.i18n && window.wp.i18n.__) {
      const t = window.wp.i18n.__(str, domain || 'ai-smart-commerce-search');
      if (t !== str) return t;
    }
    return i18n[str] || str;
  }

  /* ------------------------------------------------------------------ */
  /* DOM helpers                                                          */
  /* ------------------------------------------------------------------ */
  function el(tag, attrs, ...children) {
    const node = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (k === 'className') node.className = v;
        else if (k.startsWith('on')) node.addEventListener(k.slice(2).toLowerCase(), v);
        else node.setAttribute(k, v);
      }
    }
    for (const c of children) {
      if (c == null) continue;
      if (typeof c === 'string') node.appendChild(document.createTextNode(c));
      else node.appendChild(c);
    }
    return node;
  }

  /* ------------------------------------------------------------------ */
  /* State                                                                */
  /* ------------------------------------------------------------------ */
  let isOpen = false;
  let searchTimeout = null;
  let currentQuery = '';
  let currentResults = [];
  let cartNonce = cfg.nonce || '';

  /* ------------------------------------------------------------------ */
  /* Build drawer DOM                                                     */
  /* ------------------------------------------------------------------ */
  const overlay = el('div', { className: 'asc-overlay', 'aria-hidden': 'true' });
  overlay.addEventListener('click', closeDrawer);

  const searchInput = el('input', {
    type: 'search',
    className: 'asc-search-input',
    placeholder: i18n.placeholder || __('Search for products', 'ai-smart-commerce-search'),
    'aria-label': i18n.placeholder || __('Search for products', 'ai-smart-commerce-search'),
    autocomplete: 'off',
    dir: 'rtl',
  });

  const closeBtn = el('button', {
    className: 'asc-close-btn',
    'aria-label': i18n.close || __('Close search', 'ai-smart-commerce-search'),
    type: 'button',
  });
  closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  closeBtn.addEventListener('click', closeDrawer);

  const header = el('div', { className: 'asc-header', role: 'search' }, closeBtn, searchInput);

  const statusBar = el('div', { className: 'asc-status', 'aria-live': 'polite', 'aria-atomic': 'true' });

  const resultsEl = el('div', {
    className: 'asc-results',
    role: 'list',
    tabindex: '-1',
    'aria-label': i18n.placeholder || 'Search results',
  });

  const drawer = el('div', {
    className: 'asc-drawer',
    role: 'dialog',
    'aria-modal': 'true',
    'aria-label': i18n.placeholder || __('Search for products', 'ai-smart-commerce-search'),
    dir: 'rtl',
  }, header, statusBar, resultsEl);

  document.body.appendChild(overlay);
  document.body.appendChild(drawer);

  /* ------------------------------------------------------------------ */
  /* Open / Close                                                         */
  /* ------------------------------------------------------------------ */
  function openDrawer() {
    if (isOpen) return;
    isOpen = true;
    overlay.classList.add('asc-visible');
    drawer.classList.add('asc-open');
    searchInput.focus();
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    if (!isOpen) return;
    isOpen = false;
    overlay.classList.remove('asc-visible');
    drawer.classList.remove('asc-open');
    document.body.style.overflow = '';
    // Return focus to the native search field that triggered the drawer.
    const native = document.querySelector('.asc-native-hidden-input, input[name="s"]');
    if (native) native.focus();
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen) closeDrawer();
  });

  /* ------------------------------------------------------------------ */
  /* Intercept the site's native search fields                           */
  /* ------------------------------------------------------------------ */
  function interceptSearchForms() {
    const selectors = [
      'input[type="search"][name="s"]',
      '.search-field',
      '.wp-block-search__input',
      'input.search-input',
    ];
    document.querySelectorAll(selectors.join(',')).forEach(function (input) {
      if (input.dataset.ascHijacked) return;
      input.dataset.ascHijacked = 'true';
      input.classList.add('asc-native-hidden-input');

      input.addEventListener('focus', function (e) {
        openDrawer();
        // Sync the native field value into the drawer.
        searchInput.value = input.value;
        if (input.value.trim().length >= 2) {
          runSearch(input.value.trim());
        }
      });

      // Prevent the native form from submitting.
      const form = input.closest('form');
      if (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          openDrawer();
          if (searchInput.value.trim().length >= 2) {
            runSearch(searchInput.value.trim());
          }
        });
      }
    });
  }

  interceptSearchForms();
  // Re-run after any DOM mutations (e.g. sticky header inserted late).
  if (window.MutationObserver) {
    new MutationObserver(interceptSearchForms).observe(document.body, { childList: true, subtree: true });
  }

  /* ------------------------------------------------------------------ */
  /* Search                                                               */
  /* ------------------------------------------------------------------ */
  searchInput.addEventListener('input', function () {
    const q = searchInput.value.trim();
    if (searchTimeout) clearTimeout(searchTimeout);
    if (q.length < 2) {
      setStatus('');
      clearResults();
      return;
    }
    setStatus(spinner() + (i18n.searching || __('Searching…', 'ai-smart-commerce-search')));
    searchTimeout = setTimeout(function () { runSearch(q); }, 320);
  });

  function runSearch(q) {
    currentQuery = q;
    const url = (cfg.restUrl || '/wp-json/asc/v1') + '/search?q=' + encodeURIComponent(q) + '&per_page=12';
    fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce || '' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (currentQuery !== q) return; // stale
        renderResults(data.products || []);
        if (!data.products || !data.products.length) {
          setStatus(i18n.noResults || __('No products found', 'ai-smart-commerce-search'));
        } else {
          setStatus('');
        }
      })
      .catch(function () {
        if (currentQuery !== q) return;
        setStatus((i18n.errorAdding || 'Error'), true);
      });
  }

  /* ------------------------------------------------------------------ */
  /* Render                                                               */
  /* ------------------------------------------------------------------ */
  function clearResults() {
    while (resultsEl.firstChild) resultsEl.removeChild(resultsEl.firstChild);
    currentResults = [];
  }

  function renderResults(products) {
    clearResults();
    currentResults = products;
    if (!products.length) return;
    products.forEach(function (p, idx) {
      resultsEl.appendChild(buildCard(p, idx));
    });
  }

  function buildCard(p, idx) {
    const imgEl = el('img', {
      src: p.image || '',
      alt: p.name || '',
      className: 'asc-card-image',
      loading: 'lazy',
      width: '72',
      height: '72',
    });

    const nameLink = el('a', { href: p.permalink || '#' }, p.name || '');
    const nameEl = el('div', { className: 'asc-card-name' }, nameLink);

    const priceEl = el('div', { className: 'asc-card-price' });
    priceEl.innerHTML = p.price_html || '';

    const bodyChildren = [nameEl, priceEl];

    if (p.is_weighted && p.price_note) {
      bodyChildren.push(el('div', { className: 'asc-card-weight-note' }, '⚠ ' + p.price_note));
    }

    const stockEl = el('div', {
      className: 'asc-card-stock ' + (p.in_stock ? 'in-stock' : 'out-of-stock'),
    }, p.in_stock
      ? (i18n.inStock || __('In stock', 'ai-smart-commerce-search'))
      : (i18n.outOfStock || __('Out of stock', 'ai-smart-commerce-search')));
    bodyChildren.push(stockEl);

    // Variations.
    const selectedAttrs = {};
    if (p.type === 'variable' && p.attributes && p.attributes.length) {
      const varWrapper = el('div', { className: 'asc-variations' });
      p.attributes.forEach(function (attr) {
        const sel = el('select', {
          className: 'asc-variation-select',
          'aria-label': attr.label,
          'data-attr': attr.name,
        });
        const placeholder = el('option', { value: '' }, attr.label);
        sel.appendChild(placeholder);
        (attr.options || []).forEach(function (opt) {
          sel.appendChild(el('option', { value: opt }, opt));
        });
        sel.addEventListener('change', function () {
          selectedAttrs[attr.name] = sel.value;
        });
        varWrapper.appendChild(sel);
      });
      bodyChildren.push(varWrapper);
    }

    // Quantity + Add-to-cart.
    const qtyInput = el('input', {
      type: 'number',
      className: 'asc-qty-input',
      value: '1',
      min: '1',
      max: '99',
      'aria-label': i18n.quantity || __('Quantity', 'ai-smart-commerce-search'),
    });
    const qtyMinus = el('button', {
      type: 'button',
      className: 'asc-qty-btn',
      'aria-label': '−',
    }, '−');
    const qtyPlus = el('button', {
      type: 'button',
      className: 'asc-qty-btn',
      'aria-label': '+',
    }, '+');
    qtyMinus.addEventListener('click', function () {
      const v = parseInt(qtyInput.value, 10) || 1;
      if (v > 1) qtyInput.value = v - 1;
    });
    qtyPlus.addEventListener('click', function () {
      const v = parseInt(qtyInput.value, 10) || 1;
      qtyInput.value = v + 1;
    });
    const qtyWrapper = el('div', { className: 'asc-qty-wrapper' }, qtyMinus, qtyInput, qtyPlus);

    const addBtn = el('button', {
      type: 'button',
      className: 'asc-add-btn',
      'data-id': String(p.id),
      disabled: p.in_stock ? null : 'disabled',
      'aria-label': (i18n.addToCart || __('Add to cart', 'ai-smart-commerce-search')) + ': ' + p.name,
    }, i18n.addToCart || __('Add to cart', 'ai-smart-commerce-search'));

    addBtn.addEventListener('click', function () {
      const qty = parseInt(qtyInput.value, 10) || 1;
      const variationId = findVariationId(p, selectedAttrs);
      if (p.type === 'variable' && !variationId) {
        setStatus(i18n.selectOptions || __('Please select all options', 'ai-smart-commerce-search'), true);
        return;
      }
      addToCart(p, qty, variationId, addBtn, selectedAttrs);
    });

    const actionsEl = el('div', { className: 'asc-card-actions' }, qtyWrapper, addBtn);
    bodyChildren.push(actionsEl);

    const body = el('div', { className: 'asc-card-body' }, ...bodyChildren);
    const card = el('div', {
      className: 'asc-card',
      role: 'listitem',
      tabindex: '0',
    }, imgEl, body);

    return card;
  }

  function findVariationId(product, selectedAttrs) {
    if (!product.variations || !product.variations.length) return null;
    for (const v of product.variations) {
      let match = true;
      for (const [attrName, attrVal] of Object.entries(v.attributes)) {
        const selected = selectedAttrs[attrName];
        if (!selected) { match = false; break; }
        if (attrVal && attrVal !== selected) { match = false; break; }
      }
      if (match) return v.variation_id;
    }
    return null;
  }

  /* ------------------------------------------------------------------ */
  /* Add to cart (WC Store API)                                          */
  /* ------------------------------------------------------------------ */
  function addToCart(product, qty, variationId, btn, attrs) {
    btn.disabled = true;
    btn.textContent = i18n.addingToCart || __('Adding…', 'ai-smart-commerce-search');

    const storeApi = (cfg.storeApi || '/wp-json/wc/store/v1') + '/cart/add-item';
    const body = {
      id: variationId || product.id,
      quantity: qty,
    };
    if (variationId && attrs && Object.keys(attrs).length) {
      body.variation = Object.entries(attrs).map(function ([k, v]) {
        return { attribute: k, value: v };
      });
    }

    fetch(storeApi, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Nonce': cartNonce,
        'X-WC-Store-API-Nonce': cartNonce,
      },
      body: JSON.stringify(body),
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        btn.textContent = i18n.addedToCart || __('Added to cart', 'ai-smart-commerce-search');
        btn.classList.add('asc-added');
        btn.disabled = false;
        // Update cart count in the theme header.
        updateCartCount(data.items_count);
        setTimeout(function () {
          btn.textContent = i18n.addToCart || __('Add to cart', 'ai-smart-commerce-search');
          btn.classList.remove('asc-added');
        }, 2000);
      })
      .catch(function (err) {
        btn.textContent = i18n.errorAdding || __('Error adding to cart', 'ai-smart-commerce-search');
        btn.classList.add('asc-error');
        btn.disabled = false;
        setTimeout(function () {
          btn.textContent = i18n.addToCart || __('Add to cart', 'ai-smart-commerce-search');
          btn.classList.remove('asc-error');
        }, 2500);
      });
  }

  function updateCartCount(count) {
    // Update common theme cart counter selectors.
    const selectors = [
      '.cart-count',
      '.woocommerce-mini-cart-item-count',
      '.widget_shopping_cart_content .woocommerce-mini-cart__total',
      'a.cart-contents .count',
      '.menu-cart-btn span',
      '[data-cart-total]',
    ];
    document.querySelectorAll(selectors.join(',')).forEach(function (el) {
      if (!isNaN(count)) el.textContent = count;
    });
    // Also trigger the standard WooCommerce cart fragment refresh.
    if (window.jQuery && window.jQuery(document).trigger) {
      window.jQuery(document.body).trigger('wc_fragment_refresh');
    }
  }

  /* ------------------------------------------------------------------ */
  /* Helpers                                                              */
  /* ------------------------------------------------------------------ */
  function setStatus(msg, isError) {
    statusBar.textContent = '';
    if (!msg) return;
    if (typeof msg === 'string' && msg.includes('<')) {
      statusBar.innerHTML = msg;
    } else {
      statusBar.textContent = msg;
    }
    statusBar.className = 'asc-status' + (isError ? ' asc-error' : '');
  }

  function spinner() {
    return '<span class="asc-spinner" aria-hidden="true"></span>';
  }

  /* ================================================================== */
  /* Voice — Google Cloud STT                                            */
  /* ================================================================== */

  // ---- Voice context (session-scoped, persists across recordings) ----
  function newVoiceCtx() {
    return { turns: [], accumulated: '' };
  }

  function loadVoiceCtx() {
    try {
      var raw = sessionStorage.getItem('asc_voice_ctx');
      return raw ? JSON.parse(raw) : newVoiceCtx();
    } catch (e) {
      return newVoiceCtx();
    }
  }

  function saveVoiceCtx(ctx) {
    try { sessionStorage.setItem('asc_voice_ctx', JSON.stringify(ctx)); } catch (e) {}
  }

  var voiceCtx = loadVoiceCtx();

  // ---- Capability check ----
  var isSecureCtx = !!(window.isSecureContext || location.protocol === 'https:' || location.hostname === 'localhost');
  var hasMicApi   = !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  var voiceReady  = isSecureCtx && hasMicApi && !!(cfg.voiceEnabled) && !!(cfg.transcribeUrl);

  // ---- Recording state ----
  var mediaRecorder    = null;
  var audioChunks      = [];
  var isRecording      = false;
  var currentMimeType  = '';
  var autoStopTimer    = null;
  var confirmTimer     = null;

  // ---- Build voice preview bar (between header and statusBar) ----
  var voicePreviewBar = el('div', { className: 'asc-voice-preview' });
  drawer.insertBefore(voicePreviewBar, statusBar);

  // ---- Build mic button (only when voice is ready) ----
  var micBtn = null;
  if (voiceReady) {
    micBtn = el('button', {
      type: 'button',
      className: 'asc-mic-btn asc-mic-idle',
      'aria-label': i18n.voiceSearch || 'Voice search',
      'aria-pressed': 'false',
      title: i18n.voiceSearch || 'Voice search',
    });
    micBtn.innerHTML = micIdleSvg();
    header.appendChild(micBtn);
    micBtn.addEventListener('click', toggleVoice);
  }

  // ---- SVG icons ----
  function micIdleSvg() {
    return '<svg class="asc-mic-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="9" y="1" width="6" height="13" rx="3"/>' +
      '<path d="M19 10a7 7 0 0 1-14 0"/><line x1="12" y1="19" x2="12" y2="23"/>' +
      '<line x1="8" y1="23" x2="16" y2="23"/></svg>';
  }

  function micRecordingSvg() {
    return '<svg class="asc-mic-icon asc-mic-pulse" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
      '<rect x="9" y="1" width="6" height="13" rx="3"/>' +
      '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M19 10a7 7 0 0 1-14 0"/>' +
      '<line stroke="currentColor" stroke-width="2" x1="12" y1="19" x2="12" y2="23"/>' +
      '<line stroke="currentColor" stroke-width="2" x1="8" y1="23" x2="16" y2="23"/></svg>';
  }

  function micSpinnerSvg() {
    return '<span class="asc-spinner" aria-hidden="true"></span>';
  }

  // ---- Mic state management ----
  function setMicState(state) {
    if (!micBtn) return;
    micBtn.classList.remove('asc-mic-idle', 'asc-mic-recording', 'asc-mic-processing');
    micBtn.classList.add('asc-mic-' + state);
    if (state === 'recording') {
      micBtn.innerHTML = micRecordingSvg();
      micBtn.setAttribute('aria-label', i18n.stopRecording || 'Stop recording');
      micBtn.setAttribute('aria-pressed', 'true');
    } else if (state === 'processing') {
      micBtn.innerHTML = micSpinnerSvg();
      micBtn.setAttribute('aria-label', i18n.voiceProcessing || 'Processing…');
      micBtn.setAttribute('aria-pressed', 'false');
    } else {
      micBtn.innerHTML = micIdleSvg();
      micBtn.setAttribute('aria-label', i18n.voiceSearch || 'Voice search');
      micBtn.setAttribute('aria-pressed', 'false');
    }
  }

  // ---- Toggle ----
  function toggleVoice() {
    if (isRecording) {
      stopRecording();
    } else {
      startRecording();
    }
  }

  // ---- Recording ----
  function getSupportedMimeType() {
    var types = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/ogg;codecs=opus',
      'audio/ogg',
    ];
    for (var i = 0; i < types.length; i++) {
      if (MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }

  function startRecording() {
    if (!voiceReady) {
      handleVoiceError('unsupported_browser');
      return;
    }
    openDrawer();
    navigator.mediaDevices.getUserMedia({ audio: true, video: false })
      .then(function (stream) {
        audioChunks = [];
        currentMimeType = getSupportedMimeType();

        if (!currentMimeType) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          handleVoiceError('unsupported_browser');
          return;
        }

        setMicState('recording');
        setStatus(spinner() + (i18n.voiceListening || 'Listening…'));

        try {
          mediaRecorder = new MediaRecorder(stream, { mimeType: currentMimeType });
        } catch (e) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          handleVoiceError('unsupported_browser');
          return;
        }

        mediaRecorder.addEventListener('dataavailable', function (e) {
          if (e.data && e.data.size > 0) audioChunks.push(e.data);
        });

        mediaRecorder.addEventListener('stop', function () {
          stream.getTracks().forEach(function (t) { t.stop(); }); // mic off immediately
          if (autoStopTimer) { clearTimeout(autoStopTimer); autoStopTimer = null; }
          processAudio();
        });

        isRecording = true;
        mediaRecorder.start(250); // collect chunks every 250ms for VAD-friendly slicing

        // Hard limit: 10 seconds
        autoStopTimer = setTimeout(function () {
          if (isRecording) stopRecording();
        }, 10000);
      })
      .catch(function (err) {
        var type = (err && err.name === 'NotFoundError') ? 'no_microphone' : 'permission_denied';
        handleVoiceError(type);
      });
  }

  function stopRecording() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    isRecording = false;
    if (autoStopTimer) { clearTimeout(autoStopTimer); autoStopTimer = null; }
    setMicState('processing');
    setStatus(spinner() + (i18n.voiceProcessing || 'Processing…'));
    mediaRecorder.stop();
  }

  // ---- Process recorded audio ----
  function processAudio() {
    if (!audioChunks.length) {
      handleVoiceError('silence');
      return;
    }
    var blob   = new Blob(audioChunks, { type: currentMimeType });
    var reader = new FileReader();
    reader.onloadend = function () {
      var parts  = reader.result.split(',');
      if (parts.length < 2) { handleVoiceError('service_unavailable'); return; }
      sendToSTT(parts[1]);
    };
    reader.onerror = function () { handleVoiceError('service_unavailable'); };
    reader.readAsDataURL(blob);
  }

  function sendToSTT(audioBase64) {
    fetch(cfg.transcribeUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || '',
      },
      body: JSON.stringify({
        audio_base64: audioBase64,
        language: 'he-IL',
        mime_type: currentMimeType,
      }),
    })
      .then(function (r) {
        if (!r.ok) {
          if (r.status === 503) throw new Error('service_unavailable');
          if (r.status === 400) throw new Error('unsupported_browser');
          throw new Error('service_unavailable');
        }
        return r.json();
      })
      .then(function (data) {
        setMicState('idle');
        setStatus('');
        if (data.error) { handleVoiceError(data.error); return; }
        var transcript = (data.transcript || '').trim();
        if (!transcript) { handleVoiceError('silence'); return; }
        showTranscriptPreview(transcript, data.confidence || 0);
      })
      .catch(function (err) {
        setMicState('idle');
        var type = (err && err.message) ? err.message : 'service_unavailable';
        handleVoiceError(type);
      });
  }

  // ---- Transcript preview ----
  function showTranscriptPreview(transcript, confidence) {
    clearConfirmTimer();

    var prevAccumulated = voiceCtx.accumulated || '';

    // Context label (shown when there's prior context)
    var contextRow = null;
    if (prevAccumulated) {
      contextRow = el('div', { className: 'asc-voice-context-row' },
        el('span', { className: 'asc-voice-context-label' }, i18n.voiceContext || 'Context: '),
        el('span', { className: 'asc-voice-context-text' }, prevAccumulated)
      );
    }

    var transcriptInput = el('input', {
      type: 'text',
      className: 'asc-voice-transcript-input',
      value: transcript,
      'aria-label': i18n.voiceTranscriptLabel || 'Transcript — tap to edit',
      dir: 'rtl',
    });

    var countdownEl  = el('span', { className: 'asc-voice-countdown', 'aria-hidden': 'true' }, '3');
    var confirmBtn   = el('button', {
      type: 'button',
      className: 'asc-voice-confirm-btn',
      'aria-label': i18n.voiceConfirm || 'Search',
    }, i18n.voiceConfirm || 'Search');
    var cancelBtn = el('button', {
      type: 'button',
      className: 'asc-voice-cancel-btn',
      'aria-label': i18n.voiceCancel || 'Cancel',
    }, '✕');

    var actionsRow = el('div', { className: 'asc-voice-actions-row' },
      transcriptInput, countdownEl, confirmBtn, cancelBtn
    );

    voicePreviewBar.textContent = '';
    if (contextRow) voicePreviewBar.appendChild(contextRow);
    voicePreviewBar.appendChild(actionsRow);
    voicePreviewBar.classList.add('asc-voice-preview-open');

    // Focus the editable transcript so user can correct it immediately
    transcriptInput.focus();
    transcriptInput.select();

    // 3-second auto-confirm countdown
    var countdown = 3;
    confirmTimer = setInterval(function () {
      countdown--;
      countdownEl.textContent = String(countdown);
      if (countdown <= 0) {
        clearConfirmTimer();
        confirmTranscript(transcriptInput.value || transcript);
      }
    }, 1000);

    confirmBtn.addEventListener('click', function () {
      clearConfirmTimer();
      confirmTranscript(transcriptInput.value || transcript);
    });

    cancelBtn.addEventListener('click', function () {
      clearConfirmTimer();
      hideTranscriptPreview();
      // Mic button returns to idle; search input retains its previous value.
    });

    // Typing in the transcript field resets the countdown to give the user time
    transcriptInput.addEventListener('input', function () {
      clearConfirmTimer();
      countdownEl.textContent = '';
    });

    // Enter confirms
    transcriptInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        clearConfirmTimer();
        confirmTranscript(transcriptInput.value || transcript);
      }
      if (e.key === 'Escape') {
        clearConfirmTimer();
        hideTranscriptPreview();
      }
    });
  }

  function confirmTranscript(text) {
    hideTranscriptPreview();
    var trimmed = text.trim();
    if (!trimmed) return;

    // Accumulate context: append to previous turns
    var newAccumulated = voiceCtx.accumulated
      ? voiceCtx.accumulated + ' ' + trimmed
      : trimmed;

    voiceCtx.turns.push({ text: trimmed, ts: Date.now() });
    voiceCtx.accumulated = newAccumulated;
    saveVoiceCtx(voiceCtx);

    // Update the search input and fire the search
    searchInput.value = newAccumulated;
    runSearch(newAccumulated);
  }

  function hideTranscriptPreview() {
    clearConfirmTimer();
    voicePreviewBar.classList.remove('asc-voice-preview-open');
    voicePreviewBar.textContent = '';
    setMicState('idle');
  }

  function clearConfirmTimer() {
    if (confirmTimer) { clearInterval(confirmTimer); confirmTimer = null; }
  }

  // ---- Failure handling — always degrade to typing without data loss ----
  function handleVoiceError(type) {
    setMicState('idle');
    hideTranscriptPreview();
    // The search input is never touched here — user keeps what they typed.

    var msgs = {
      permission_denied:    i18n.voicePermissionDenied   || 'Microphone access denied.',
      no_microphone:        i18n.voicePermissionDenied   || 'No microphone found.',
      silence:              i18n.voiceSilence             || 'No speech detected. Try again.',
      unintelligible:       i18n.voiceUnintelligible      || 'Could not understand. Try again or type.',
      service_unavailable:  i18n.voiceServiceUnavailable  || 'Voice service unavailable. Please type.',
      not_configured:       i18n.voiceNotConfigured       || 'Voice search not configured.',
      unsupported_browser:  i18n.voiceUnsupportedBrowser  || 'Voice not supported in this browser.',
      unsupported_format:   i18n.voiceUnsupportedBrowser  || 'Audio format not supported.',
    };

    setStatus(msgs[type] || msgs.service_unavailable, true);

    // Permanently disable the mic button when hardware/permission is unavailable
    if ((type === 'permission_denied' || type === 'no_microphone') && micBtn) {
      micBtn.disabled = true;
      micBtn.classList.add('asc-mic-disabled');
      micBtn.title = msgs[type];
    }
  }

  // ---- Reset voice context when user manually edits the search field ----
  // User taking over the input means they want a fresh start, not a continuation.
  searchInput.addEventListener('input', function () {
    if (!isRecording && voiceCtx.accumulated) {
      voiceCtx = newVoiceCtx();
      saveVoiceCtx(voiceCtx);
    }
  });

  // ---- Hide transcript preview when drawer closes ----
  var _origClose = closeDrawer;
  closeDrawer = function () {
    hideTranscriptPreview();
    if (isRecording) {
      isRecording = false;
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
      }
    }
    _origClose();
  };

})();
