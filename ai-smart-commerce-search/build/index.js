/**
 * AI Smart Commerce Search — search drawer V3
 * Two-track: provisional index results appear immediately;
 * AI-ranked results (from cloud) replace them when ready.
 */
(function () {
  'use strict';

  /* ---- i18n helper ---- */
  const cfg  = window.ascConfig || {};
  const i18n = cfg.i18n || {};

  function __(str) {
    if (window.wp && window.wp.i18n && window.wp.i18n.__) {
      const t = window.wp.i18n.__(str, 'ai-smart-commerce-search');
      if (t !== str) return t;
    }
    return i18n[str] || str;
  }

  /* ---- DOM factory ---- */
  function el(tag, attrs) {
    const node = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (v == null) continue;
        if (k === 'className') node.className = v;
        else if (k.startsWith('on')) node.addEventListener(k.slice(2).toLowerCase(), v);
        else node.setAttribute(k, v);
      }
    }
    for (let i = 2; i < arguments.length; i++) {
      const c = arguments[i];
      if (c == null) continue;
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return node;
  }

  /* ---- Rotating placeholder (spec §2.1) ---- */
  var PLACEHOLDERS = [
    'מה תרצו למצוא היום? אפשר לשאול אותי חופשי...',
    'אני מחפש גבינות מיוחדות לאירוח...',
    'מה כדאי להכין לארוחת ערב?',
    'יש לכם משהו דומה אבל זול יותר?',
    'אני צריך מוצרים לארוחה של 6 אנשים...',
    'איזה מבצעים מתאימים לי היום?',
    'אני מחפש משהו טעים ללא גלוטן...',
  ];
  var phIdx   = 0;
  var phTimer = null;

  function rotatePlaceholder() {
    if (searchInput && !searchInput.value) {
      phIdx = (phIdx + 1) % PLACEHOLDERS.length;
      searchInput.setAttribute('placeholder', PLACEHOLDERS[phIdx]);
    }
  }

  /* ---- State ---- */
  let isOpen        = false;
  let searchTimer   = null;
  let currentQuery  = '';
  let currentAll    = [];   // full result set from last completed search
  let shownCount    = 0;
  let fullDone      = false;
  let aborts        = [];   // AbortControllers for in-flight requests
  let aiBarTimer    = null;
  let cartNonce     = cfg.nonce || '';

  const INITIAL_SHOW = 6;
  const AI_MSGS = [
    i18n.aiThinking  || 'AI \u05de\u05e0\u05ea\u05d7 \u05d0\u05ea \u05d4\u05d1\u05e7\u05e9\u05d4...',
    i18n.aiFetching  || 'AI \u05de\u05d7\u05e4\u05e9 \u05de\u05d5\u05e6\u05e8\u05d9\u05dd \u05e8\u05dc\u05d5\u05d5\u05e0\u05d8\u05d9\u05d9\u05dd...',
    i18n.aiRanking   || 'AI \u05de\u05d3\u05e8\u05d2 \u05ea\u05d5\u05e6\u05d0\u05d5\u05ea \u05e2\u05d1\u05d5\u05e8\u05da...',
  ];

  /* ---- Build drawer DOM ---- */
  const overlay = el('div', { className: 'asc-overlay', 'aria-hidden': 'true' });
  overlay.addEventListener('click', closeDrawer);

  const searchInput = el('input', {
    type:         'search',
    className:    'asc-search-input',
    placeholder:  PLACEHOLDERS[0],
    'aria-label': i18n.placeholder || '\u05d7\u05d9\u05e4\u05d5\u05e9',
    autocomplete: 'off',
    dir:          'rtl',
  });

  const closeBtn = el('button', {
    className:  'asc-close-btn',
    'aria-label': i18n.close || '\u05e1\u05d2\u05d5\u05e8 \u05d7\u05d9\u05e4\u05d5\u05e9',
    type: 'button',
  });
  closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  closeBtn.addEventListener('click', closeDrawer);

  const header = el('div', { className: 'asc-header', role: 'search' }, closeBtn, searchInput);

  const aiDot  = el('span', { className: 'asc-ai-dot', 'aria-hidden': 'true' });
  const aiText = el('span', { className: 'asc-ai-text' });
  const aiBar  = el('div',  { className: 'asc-ai-bar', role: 'status', 'aria-live': 'polite' }, aiDot, aiText);

  /* ---- Assistant message bar (R4) ---- */
  const assistantEl = el('div', {
    className:  'asc-assistant',
    role:       'status',
    'aria-live': 'polite',
    'aria-atomic': 'true',
  });

  const statusBar = el('div', { className: 'asc-status', 'aria-live': 'polite', 'aria-atomic': 'true' });

  const resultsEl = el('div', {
    className:  'asc-results',
    role:       'list',
    tabindex:   '-1',
    'aria-label': i18n.resultsLabel || '\u05ea\u05d5\u05e6\u05d0\u05d5\u05ea \u05d7\u05d9\u05e4\u05d5\u05e9',
  });

  const chipsEl = el('div', {
    className:  'asc-chips',
    role:       'group',
    'aria-label': '\u05d4\u05e6\u05e2\u05d5\u05ea \u05dc\u05d4\u05de\u05e9\u05da',
  });

  const drawer = el('div', {
    className:   'asc-drawer',
    role:        'dialog',
    'aria-modal': 'true',
    'aria-label': i18n.drawerLabel || '\u05d7\u05d9\u05e4\u05d5\u05e9 \u05de\u05d5\u05e6\u05e8\u05d9\u05dd',
    dir: 'rtl',
  }, header, aiBar, assistantEl, statusBar, resultsEl, chipsEl);

  /* ---- Options modal (R4) ---- */
  var modalOverlayEl = el('div', { className: 'asc-modal-overlay', 'aria-hidden': 'true' });
  var modalEl        = el('div', { className: 'asc-modal', role: 'dialog', 'aria-modal': 'true', dir: 'rtl' });
  modalOverlayEl.addEventListener('click', closeOptionsModal);

  document.body.appendChild(overlay);
  document.body.appendChild(drawer);
  document.body.appendChild(modalOverlayEl);
  document.body.appendChild(modalEl);

  /* ---- Open / Close ---- */
  function detectSiteHeaderHeight() {
    var sels = ['.site-header','#masthead','#site-header','header[role="banner"]','.header-main','#header','header.header'];
    for (var i = 0; i < sels.length; i++) {
      var h = document.querySelector(sels[i]);
      if (h) {
        var r = h.getBoundingClientRect();
        if (r.bottom > 10 && r.bottom < 300) return Math.round(r.bottom);
      }
    }
    return 0;
  }

  function openDrawer() {
    if (isOpen) return;
    isOpen = true;
    var hh = detectSiteHeaderHeight();
    document.documentElement.style.setProperty('--asc-header-offset', hh + 'px');
    overlay.classList.add('asc-visible');
    drawer.classList.add('asc-open');
    searchInput.focus();
    document.body.style.overflow = 'hidden';
    if (!phTimer) phTimer = setInterval(rotatePlaceholder, 3500);
  }

  function closeDrawer() {
    if (!isOpen) return;
    isOpen = false;
    overlay.classList.remove('asc-visible');
    drawer.classList.remove('asc-open');
    document.body.style.overflow = '';
    if (phTimer) { clearInterval(phTimer); phTimer = null; }
    cancelPending();
    clearAssistant();
    closeOptionsModal();
    var native = document.querySelector('.asc-native-hidden-input, input[name="s"]');
    if (native) native.focus();
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen) closeDrawer();
  });

  /* ---- Intercept native search fields ---- */
  function interceptSearchForms() {
    var selectors = [
      'input[type="search"][name="s"]',
      '.search-field',
      '.wp-block-search__input',
      'input.search-input',
    ];
    document.querySelectorAll(selectors.join(',')).forEach(function (input) {
      if (input.dataset.ascHijacked) return;
      input.dataset.ascHijacked = 'true';
      input.classList.add('asc-native-hidden-input');
      input.addEventListener('focus', function () {
        openDrawer();
        searchInput.value = input.value;
        if (input.value.trim().length >= 2) runSearch(input.value.trim());
      });
      var form = input.closest('form');
      if (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          openDrawer();
          if (searchInput.value.trim().length >= 2) runSearch(searchInput.value.trim());
        });
      }
    });
  }

  interceptSearchForms();
  if (window.MutationObserver) {
    new MutationObserver(interceptSearchForms).observe(document.body, { childList: true, subtree: true });
  }

  /* ---- Search input handler ---- */
  searchInput.addEventListener('input', function () {
    var q = searchInput.value.trim();
    if (searchTimer) clearTimeout(searchTimer);
    if (q.length < 2) {
      setStatus('');
      clearResults();
      hideAiBar();
      clearChips();
      clearAssistant();
      return;
    }
    renderSkeletons(INITIAL_SHOW);
    searchTimer = setTimeout(function () { runSearch(q); }, 300);
  });

  /* ---- Two-track search + assistant (R4) ---- */
  function runSearch(q) {
    currentQuery = q;
    currentAll   = [];
    shownCount   = 0;
    fullDone     = false;
    cancelPending();
    clearChips();
    clearAssistant();
    setStatus('');
    renderSkeletons(INITIAL_SHOW);
    showAiBar(); // show spinner until assistant or results land

    var abortA = newAbort();
    var abortB = newAbort();
    var abortC = newAbort();
    aborts = [abortA, abortB, abortC];

    /* Track C: assistant only — fastest, fires before products */
    fetchAssistant(q, { signal: abortC.signal })
      .then(function (data) {
        if (currentQuery !== q) return;
        hideAiBar();
        if (data && data.assistant) showAssistant(data.assistant);
      })
      .catch(function () { /* assistant failures are silent */ });

    /* Track B: full AI search — fires immediately */
    fetchSearch(q, { signal: abortB.signal, perPage: 12 })
      .then(function (data) {
        if (currentQuery !== q) return;
        fullDone = true;
        try { abortA.abort(); } catch(e) {}
        try { abortC.abort(); } catch(e) {}
        hideAiBar();
        // If search returned a richer assistant, replace the phase-C one
        if (data && data.assistant && data.assistant.tone !== 'none' && data.assistant.message) {
          showAssistant(data.assistant);
        }
        renderResults(data.products || []);
        // follow_ups from search response take precedence
        var chips = data.follow_ups || (data.assistant && data.assistant.follow_ups) || [];
        renderChips(chips);
        if (!data.products || !data.products.length) {
          setStatus(i18n.noResults || 'לא נמצאו מוצרים');
        }
      })
      .catch(function (err) {
        if (currentQuery !== q || (err && err.name === 'AbortError')) return;
        hideAiBar();
        setStatus(i18n.errorAdding || 'שגיאה', true);
      });

    /* Track A: provisional (fast, fewer results) — renders while B is in flight */
    fetchSearch(q, { signal: abortA.signal, perPage: INITIAL_SHOW, provisional: true })
      .then(function (data) {
        if (currentQuery !== q || fullDone) return;
        renderResults(data.products || []);
      })
      .catch(function () { /* provisional failures are silent */ });
  }

    function newAbort() {
    if (typeof AbortController !== 'undefined') return new AbortController();
    return { signal: undefined, abort: function() {} };
  }

  function cancelPending() {
    aborts.forEach(function (a) { try { a.abort(); } catch(e) {} });
    aborts = [];
    if (aiBarTimer) { clearInterval(aiBarTimer); aiBarTimer = null; }
  }

  function fetchSearch(q, opts) {
    var perPage = opts.perPage || 12;
    var url = (cfg.restUrl || '/wp-json/asc/v1') + '/search?q=' + encodeURIComponent(q) + '&per_page=' + perPage;
    if (opts.provisional) url += '&provisional=1';
    return fetch(url, {
      headers: { 'X-WP-Nonce': cartNonce },
      signal:  opts.signal,
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function fetchAssistant(q, opts) {
    var url = (cfg.restUrl || '/wp-json/asc/v1') + '/assistant?q=' + encodeURIComponent(q);
    return fetch(url, {
      headers: { 'X-WP-Nonce': cartNonce },
      signal:  opts && opts.signal,
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  /* ---- AI indicator ---- */
  function showAiBar() {
    var phase = 0;
    aiText.textContent = AI_MSGS[0];
    aiBar.classList.add('asc-active');
    aiBarTimer = setInterval(function () {
      phase = (phase + 1) % AI_MSGS.length;
      aiText.textContent = AI_MSGS[phase];
    }, 1300);
  }

  function hideAiBar() {
    aiBar.classList.remove('asc-active');
    if (aiBarTimer) { clearInterval(aiBarTimer); aiBarTimer = null; }
  }

  /* ---- Assistant message (R4) ---- */
  function showAssistant(asst) {
    if (!asst || asst.tone === 'none' || !asst.message) {
      assistantEl.classList.remove('asc-assistant-visible');
      assistantEl.textContent = '';
      return;
    }
    var icon = asst.tone === 'answer' ? 'ℹ️ ' : asst.tone === 'clarify' ? '❓ ' : '✨ ';
    assistantEl.textContent = icon + asst.message;
    assistantEl.classList.add('asc-assistant-visible');
  }

  function clearAssistant() {
    assistantEl.classList.remove('asc-assistant-visible');
    assistantEl.textContent = '';
  }

  /* ---- Render results ---- */
  function clearResults() {
    while (resultsEl.firstChild) resultsEl.removeChild(resultsEl.firstChild);
    currentAll = [];
    shownCount = 0;
  }

  function renderResults(products) {
    clearResults();
    if (!products.length) return;
    currentAll = products;

    var first = products.slice(0, INITIAL_SHOW);
    first.forEach(function (p) { resultsEl.appendChild(buildCard(p)); });
    shownCount = first.length;

    if (products.length > INITIAL_SHOW) {
      resultsEl.appendChild(buildShowMoreBtn(products.length - INITIAL_SHOW));
    }
  }

  function buildShowMoreBtn(remaining) {
    var label = (i18n.showMore || '\u05d4\u05e6\u05d2 {n} \u05ea\u05d5\u05e6\u05d0\u05d5\u05ea \u05e0\u05d5\u05e1\u05e4\u05d5\u05ea').replace('{n}', remaining);
    var btn = el('button', { type: 'button', className: 'asc-show-more' }, label);
    btn.addEventListener('click', function () {
      currentAll.slice(INITIAL_SHOW).forEach(function (p) {
        resultsEl.insertBefore(buildCard(p), btn);
      });
      btn.remove();
    });
    return btn;
  }

  /* ---- Skeletons ---- */
  function renderSkeletons(n) {
    clearResults();
    for (var i = 0; i < n; i++) resultsEl.appendChild(buildSkeleton());
  }

  function buildSkeleton() {
    var img  = el('div', { className: 'asc-card-image' });
    var body = el('div', { className: 'asc-card-body' },
      el('span', { className: 'asc-skel-line' }),
      el('span', { className: 'asc-skel-line asc-skel-sm' }),
      el('span', { className: 'asc-skel-line asc-skel-sm' }),
      el('span', { className: 'asc-skel-btn' })
    );
    return el('div', { className: 'asc-card asc-skeleton', role: 'listitem', 'aria-hidden': 'true' }, img, body);
  }

  /* ---- Build product card ---- */
  function buildCard(p) {
    var imgEl = el('img', {
      src:     p.image || '',
      alt:     p.name  || '',
      className: 'asc-card-image',
      loading: 'lazy',
      width:   '88',
      height:  '88',
    });

    var nameEl = el('div', { className: 'asc-card-name' },
      el('a', { href: p.permalink || '#' }, p.name || '')
    );

    var bodyChildren = [nameEl];

    /* AI micro-copy: short sentence per product, never a greeting */
    if (p.ai_reason) {
      bodyChildren.push(el('div', { className: 'asc-card-reason' }, p.ai_reason));
    }

    var priceEl = el('div', { className: 'asc-card-price' });
    priceEl.innerHTML = p.price_html || '';
    bodyChildren.push(priceEl);

    if (p.is_weighted && p.price_note) {
      bodyChildren.push(el('div', { className: 'asc-card-weight-note' }, '\u26a0 ' + p.price_note));
    }

    var stockClass = p.in_stock ? 'in-stock' : 'out-of-stock';
    var stockLabel = p.in_stock
      ? (i18n.inStock    || '\u05d1\u05de\u05dc\u05d0\u05d9')
      : (i18n.outOfStock || '\u05d0\u05d6\u05dc \u05de\u05d4\u05de\u05dc\u05d0\u05d9');
    bodyChildren.push(el('span', { className: 'asc-stock-badge ' + stockClass }, stockLabel));

    /* Variations */
    var selectedAttrs = {};
    if (p.type === 'variable' && p.attributes && p.attributes.length) {
      var varWrapper = el('div', { className: 'asc-variations' });
      p.attributes.forEach(function (attr) {
        var sel = el('select', {
          className:   'asc-variation-select',
          'aria-label': attr.label,
          'data-attr':  attr.name,
        });
        sel.appendChild(el('option', { value: '' }, attr.label));
        (attr.options || []).forEach(function (opt) {
          sel.appendChild(el('option', { value: opt }, opt));
        });
        sel.addEventListener('change', function () { selectedAttrs[attr.name] = sel.value; });
        varWrapper.appendChild(sel);
      });
      bodyChildren.push(varWrapper);
    }

    /* R4: requires_options → 'אפשרויות' button + modal; else folded qty+add */
    if (p.requires_options) {
      /* Weighted/option product — show אפשרויות button, no inline qty */
      var optBtn = el('button', {
        type:         'button',
        className:    'asc-options-btn',
        'aria-label': 'בחר אפשרויות עבור: ' + p.name,
      }, 'אפשרויות');
      optBtn.addEventListener('click', function () { openOptionsModal(p); });
      bodyChildren.push(el('div', { className: 'asc-card-actions' }, optBtn));
    } else {
      /* Standard product — folded qty stepper inside add-to-cart row */
      var initQty = (p.suggest_qty && p.suggest_qty > 1) ? String(p.suggest_qty) : '1';
      var qtyVal = parseInt(initQty, 10) || 1;
      var qtyDisplay = el('span', { className: 'asc-qty-num' }, String(qtyVal));
      var qtyMinus = el('button', { type: 'button', className: 'asc-qty-side asc-qty-minus', 'aria-label': 'פחת' }, '−');
      var qtyPlus  = el('button', { type: 'button', className: 'asc-qty-side asc-qty-plus',  'aria-label': 'יותר' }, '+');
      qtyMinus.addEventListener('click', function (e) {
        e.stopPropagation();
        if (qtyVal > 1) { qtyVal--; qtyDisplay.textContent = String(qtyVal); }
      });
      qtyPlus.addEventListener('click', function (e) {
        e.stopPropagation();
        qtyVal++;
        qtyDisplay.textContent = String(qtyVal);
      });
      var addLabel = el('span', { className: 'asc-add-label' },
        i18n.addToCart || 'הוסף לסל'
      );
      var addBtn = el('button', {
        type:         'button',
        className:    'asc-add-btn asc-add-qty-row',
        'data-id':    String(p.id),
        disabled:     p.in_stock ? null : 'disabled',
        'aria-label': (i18n.addToCart || 'הוסף לסל') + ': ' + p.name,
      }, qtyMinus, addLabel, qtyDisplay, qtyPlus);
      addBtn.addEventListener('click', function (e) {
        // ignore clicks on the +/- side buttons (they have their own listeners)
        if (e.target === qtyMinus || e.target === qtyPlus) return;
        addToCart(p, qtyVal, null, addBtn, {});
      });
      bodyChildren.push(el('div', { className: 'asc-card-actions' }, addBtn));
    }

    var body = el.apply(null, ['div', { className: 'asc-card-body' }].concat(bodyChildren));
    return el('div', { className: 'asc-card', role: 'listitem', tabindex: '0' }, imgEl, body);
  }

  function findVariationId(product, selectedAttrs) {
    if (!product.variations || !product.variations.length) return null;
    for (var i = 0; i < product.variations.length; i++) {
      var v = product.variations[i];
      var match = true;
      var keys = Object.keys(v.attributes || {});
      for (var j = 0; j < keys.length; j++) {
        var attrName = keys[j];
        var attrVal  = v.attributes[attrName];
        var selected = selectedAttrs[attrName];
        if (!selected) { match = false; break; }
        if (attrVal && attrVal !== selected) { match = false; break; }
      }
      if (match) return v.variation_id;
    }
    return null;
  }

  /* ---- Options modal (R4) ---- */
  function openOptionsModal(product) {
    modalEl.innerHTML = '';

    // Header
    var closeBtn = el('button', { type: 'button', className: 'asc-modal-close', 'aria-label': 'סגור' }, '×');
    closeBtn.addEventListener('click', closeOptionsModal);
    var title = el('div', { className: 'asc-modal-title' }, product.name || '');
    var hdr = el('div', { className: 'asc-modal-header' }, closeBtn, title);

    var body = el('div', { className: 'asc-modal-body' });
    var loadMsg = el('div', { className: 'asc-modal-loading' }, 'טוען...');
    body.appendChild(loadMsg);

    modalEl.appendChild(hdr);
    modalEl.appendChild(body);
    modalOverlayEl.classList.add('asc-modal-open');
    modalEl.classList.add('asc-modal-open');
    document.body.style.overflow = 'hidden';

    // Load full product details
    var url = (cfg.restUrl || '/wp-json/asc/v1') + '/product/' + product.id;
    fetch(url, { headers: { 'X-WP-Nonce': cartNonce } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (p) {
        body.innerHTML = '';
        // Price
        var priceEl = el('div', { className: 'asc-modal-price' });
        priceEl.innerHTML = p.price_display || p.price_html || '';
        body.appendChild(priceEl);
        // Attributes (ברירת חיתוך etc.)
        var selectedAttrs = {};
        if (p.attributes && p.attributes.length) {
          p.attributes.forEach(function (attr) {
            var label = el('label', { className: 'asc-modal-attr-label' }, attr.label || attr.name || '');
            var sel = el('select', { className: 'asc-modal-attr-select', 'aria-label': attr.label || attr.name || '' });
            sel.appendChild(el('option', { value: '' }, (attr.label || attr.name) + '...'));
            (attr.options || []).forEach(function (opt) {
              sel.appendChild(el('option', { value: opt }, opt));
            });
            sel.addEventListener('change', function () { selectedAttrs[attr.name] = sel.value; });
            body.appendChild(label);
            body.appendChild(sel);
          });
        } else {
          // No WC attributes — link to product page
          var link = el('a', { href: product.options_url || p.permalink, className: 'asc-modal-link', target: '_blank' }, 'ראה מוצר מלא ↗️');
          body.appendChild(link);
        }
        // Add to cart
        var addBtn = el('button', { type: 'button', className: 'asc-modal-add-btn', disabled: p.in_stock ? null : 'disabled' }, 'הוסף לסל');
        addBtn.addEventListener('click', function () {
          addToCart(p, 1, null, addBtn, selectedAttrs);
        });
        body.appendChild(addBtn);
      })
      .catch(function () {
        body.innerHTML = '';
        var link = el('a', { href: product.options_url || product.permalink, className: 'asc-modal-link', target: '_blank' }, 'ראה מוצר מלא ↗️');
        body.appendChild(link);
      });
  }

  function closeOptionsModal() {
    modalOverlayEl.classList.remove('asc-modal-open');
    modalEl.classList.remove('asc-modal-open');
    if (isOpen) return; // keep body overflow hidden if drawer is open
    document.body.style.overflow = '';
  }

  /* ---- Follow-up chips ---- */
  function renderChips(chips) {
    while (chipsEl.firstChild) chipsEl.removeChild(chipsEl.firstChild);
    if (!chips || !chips.length) {
      chipsEl.classList.remove('asc-visible');
      return;
    }
    chips.forEach(function (chip) {
      var label = typeof chip === 'string' ? chip : chip.label;
      var query = typeof chip === 'string' ? chip : (chip.query || chip.label);
      var btn = el('button', { type: 'button', className: 'asc-chip' }, label);
      btn.addEventListener('click', function () {
        searchInput.value = query;
        runSearch(query);
      });
      chipsEl.appendChild(btn);
    });
    chipsEl.classList.add('asc-visible');
  }

  function clearChips() {
    while (chipsEl.firstChild) chipsEl.removeChild(chipsEl.firstChild);
    chipsEl.classList.remove('asc-visible');
  }

  /* ---- Add to cart (WC Store API) ---- */
  function addToCart(product, qty, variationId, btn, attrs) {
    btn.disabled = true;
    btn.textContent = i18n.addingToCart || '\u05de\u05d5\u05e1\u05d9\u05e3...';

    var storeApi = (cfg.storeApi || '/wp-json/wc/store/v1') + '/cart/add-item';
    var body = { id: variationId || product.id, quantity: qty };
    if (variationId && attrs && Object.keys(attrs).length) {
      body.variation = Object.entries(attrs).map(function (kv) {
        return { attribute: kv[0], value: kv[1] };
      });
    }

    fetch(storeApi, {
      method:  'POST',
      headers: {
        'Content-Type':          'application/json',
        'Nonce':                  cartNonce,
        'X-WC-Store-API-Nonce':   cartNonce,
      },
      body: JSON.stringify(body),
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        btn.textContent = i18n.addedToCart || '\u05e0\u05d5\u05e1\u05e3 \u05dc\u05e1\u05dc';
        btn.classList.add('asc-added');
        btn.disabled = false;
        updateCartCount(data.items_count);
        setTimeout(function () {
          btn.textContent = i18n.addToCart || '\u05d4\u05d5\u05e1\u05e3 \u05dc\u05e1\u05dc';
          btn.classList.remove('asc-added');
        }, 2000);
      })
      .catch(function () {
        btn.textContent = i18n.errorAdding || '\u05e9\u05d2\u05d9\u05d0\u05d4';
        btn.classList.add('asc-error');
        btn.disabled = false;
        setTimeout(function () {
          btn.textContent = i18n.addToCart || '\u05d4\u05d5\u05e1\u05e3 \u05dc\u05e1\u05dc';
          btn.classList.remove('asc-error');
        }, 2500);
      });
  }

  function updateCartCount(count) {
    var selectors = [
      '.cart-count', '.woocommerce-mini-cart-item-count', 'a.cart-contents .count',
      '.menu-cart-btn span', '[data-cart-total]',
    ];
    document.querySelectorAll(selectors.join(',')).forEach(function (el) {
      if (!isNaN(count)) el.textContent = count;
    });
    if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
  }

  /* ---- Helpers ---- */
  function setStatus(msg, isError) {
    statusBar.textContent = msg || '';
    statusBar.className = 'asc-status' + (isError ? ' asc-error' : '');
  }

})();
