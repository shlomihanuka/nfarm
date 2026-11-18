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
    placeholder:  i18n.placeholder || '\u05de\u05d4 \u05d0\u05ea\u05dd \u05de\u05d7\u05e4\u05e9\u05d9\u05dd? \u05dc\u05d3\u05d5\u05d2\u05de\u05d0: "\u05d0\u05e8\u05d5\u05d7\u05ea \u05e9\u05d1\u05ea \u05dc\u05d7\u05de\u05d9\u05e9\u05d4, \u05d1\u05de\u05d1\u05e6\u05e2"',
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
  }, header, aiBar, statusBar, resultsEl, chipsEl);

  document.body.appendChild(overlay);
  document.body.appendChild(drawer);

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
  }

  function closeDrawer() {
    if (!isOpen) return;
    isOpen = false;
    overlay.classList.remove('asc-visible');
    drawer.classList.remove('asc-open');
    document.body.style.overflow = '';
    cancelPending();
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
      return;
    }
    renderSkeletons(INITIAL_SHOW);
    searchTimer = setTimeout(function () { runSearch(q); }, 300);
  });

  /* ---- Two-track search ---- */
  function runSearch(q) {
    currentQuery = q;
    currentAll   = [];
    shownCount   = 0;
    fullDone     = false;
    cancelPending();
    clearChips();
    setStatus('');
    renderSkeletons(INITIAL_SHOW);
    hideAiBar();

    var abortA = newAbort();
    var abortB = newAbort();
    aborts = [abortA, abortB];

    /* Track B: full AI search — fires immediately */
    fetchSearch(q, { signal: abortB.signal, perPage: 12 })
      .then(function (data) {
        if (currentQuery !== q) return;
        fullDone = true;
        try { abortA.abort(); } catch(e) {}
        hideAiBar();
        renderResults(data.products || []);
        renderChips(data.follow_ups || []);
        if (!data.products || !data.products.length) {
          setStatus(i18n.noResults || '\u05dc\u05d0 \u05e0\u05de\u05e6\u05d0\u05d5 \u05de\u05d5\u05e6\u05e8\u05d9\u05dd');
        }
      })
      .catch(function (err) {
        if (currentQuery !== q || (err && err.name === 'AbortError')) return;
        hideAiBar();
        setStatus(i18n.errorAdding || '\u05e9\u05d2\u05d9\u05d0\u05d4', true);
      });

    /* Track A: provisional (fast, fewer results) — renders while B is in flight */
    fetchSearch(q, { signal: abortA.signal, perPage: INITIAL_SHOW, provisional: true })
      .then(function (data) {
        if (currentQuery !== q || fullDone) return;
        renderResults(data.products || []);
        /* Show AI indicator only when cloud is running */
        if (data.source === 'cloud' || data.source === 'ai') {
          showAiBar();
        }
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

    /* Quantity — pre-fill with AI suggest_qty if present */
    var initQty = (p.suggest_qty && p.suggest_qty > 1) ? String(p.suggest_qty) : '1';
    var qtyInput = el('input', {
      type:         'number',
      className:    'asc-qty-input',
      value:        initQty,
      min:          '1',
      max:          '99',
      'aria-label': i18n.quantity || '\u05db\u05de\u05d5\u05ea',
    });
    var qtyMinus = el('button', { type: 'button', className: 'asc-qty-btn', 'aria-label': '\u2212' }, '\u2212');
    var qtyPlus  = el('button', { type: 'button', className: 'asc-qty-btn', 'aria-label': '+' }, '+');
    qtyMinus.addEventListener('click', function () {
      var v = parseInt(qtyInput.value, 10) || 1;
      if (v > 1) qtyInput.value = v - 1;
    });
    qtyPlus.addEventListener('click', function () {
      var v = parseInt(qtyInput.value, 10) || 1;
      qtyInput.value = v + 1;
    });
    var qtyWrapper = el('div', { className: 'asc-qty-wrapper' }, qtyMinus, qtyInput, qtyPlus);

    var addBtn = el('button', {
      type:         'button',
      className:    'asc-add-btn',
      'data-id':    String(p.id),
      disabled:     p.in_stock ? null : 'disabled',
      'aria-label': (i18n.addToCart || '\u05d4\u05d5\u05e1\u05e3 \u05dc\u05e1\u05dc') + ': ' + p.name,
    }, i18n.addToCart || '\u05d4\u05d5\u05e1\u05e3 \u05dc\u05e1\u05dc');

    addBtn.addEventListener('click', function () {
      var qty = parseInt(qtyInput.value, 10) || 1;
      var variationId = findVariationId(p, selectedAttrs);
      if (p.type === 'variable' && !variationId) {
        setStatus(i18n.selectOptions || '\u05d1\u05d7\u05e8 \u05d0\u05ea \u05db\u05dc \u05d4\u05d0\u05e4\u05e9\u05e8\u05d5\u05d9\u05d5\u05ea', true);
        return;
      }
      addToCart(p, qty, variationId, addBtn, selectedAttrs);
    });

    bodyChildren.push(el('div', { className: 'asc-card-actions' }, qtyWrapper, addBtn));

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
