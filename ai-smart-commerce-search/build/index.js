/**
 * AI Smart Commerce Search — Frontend Drawer v0.1.0
 * Vanilla JS, no external dependencies.
 */
(function () {
  'use strict';

  if (typeof window.AICS_Config === 'undefined') return;

  var Cfg = window.AICS_Config;
  var L   = Cfg.strings;

  if (!Cfg.enabled) return;

  /* ------------------------------------------------------------------ */
  /* State                                                                */
  /* ------------------------------------------------------------------ */
  var _drawer       = null;
  var _input        = null;
  var _results      = null;
  var _clearBtn     = null;
  var _debounce     = null;
  var _currentQuery = '';
  var _controller   = null;
  var _cartNonce    = null;

  /* ------------------------------------------------------------------ */
  /* Helpers                                                              */
  /* ------------------------------------------------------------------ */
  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ------------------------------------------------------------------ */
  /* Cart nonce                                                           */
  /* ------------------------------------------------------------------ */
  function fetchCartNonce() {
    return fetch(Cfg.storeUrl + '/cart', {
      headers: { 'X-WP-Nonce': Cfg.nonce },
      credentials: 'same-origin'
    })
    .then(function (r) {
      _cartNonce = r.headers.get('X-WC-Store-API-Nonce') ||
                  r.headers.get('Nonce') || null;
    })
    .catch(function () {});
  }

  /* ------------------------------------------------------------------ */
  /* Drawer creation                                                      */
  /* ------------------------------------------------------------------ */
  function buildDrawer() {
    var overlay = document.createElement('div');
    overlay.id = 'aics-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', L.searchPlaceholder);
    overlay.setAttribute('dir', 'rtl');

    overlay.innerHTML =
      '<div id="aics-panel" role="search">' +
        '<div id="aics-bar">' +
          '<div id="aics-input-wrap">' +
            '<span class="aics-icon-search" aria-hidden="true">' +
              '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
            '</span>' +
            '<input id="aics-input" type="text"' +
              ' placeholder="' + esc(L.searchPlaceholder) + '"' +
              ' autocomplete="off" autocorrect="off" spellcheck="false"' +
              ' aria-label="' + esc(L.searchPlaceholder) + '" />' +
            '<button id="aics-clear" type="button" aria-label="מחק" style="display:none">' +
              '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
          '</div>' +
          '<button id="aics-close" type="button" aria-label="' + esc(L.close) + '">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
          '</button>' +
        '</div>' +
        '<div id="aics-results" role="region" aria-live="polite" aria-label="תוצאות חיפוש"></div>' +
      '</div>';

    document.body.appendChild(overlay);

    _input    = overlay.querySelector('#aics-input');
    _results  = overlay.querySelector('#aics-results');
    _clearBtn = overlay.querySelector('#aics-clear');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeDrawer();
    });
    overlay.querySelector('#aics-close').addEventListener('click', closeDrawer);

    _clearBtn.addEventListener('click', function () {
      _input.value = '';
      _clearBtn.style.display = 'none';
      _results.innerHTML = renderPlaceholder();
      _currentQuery = '';
      _input.focus();
    });

    _input.addEventListener('input', onInput);

    overlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); }
    });

    // Quantity delegation
    _results.addEventListener('click', function (e) {
      var btn = e.target.closest('.aics-qty-btn');
      if (!btn) return;
      var pid = btn.dataset.pid;
      var inp = _results.querySelector('.aics-qty[data-pid="' + pid + '"]');
      if (!inp) return;
      var v = parseInt(inp.value, 10) || 1;
      inp.value = btn.classList.contains('aics-minus') ? Math.max(1, v - 1) : v + 1;
    });

    _drawer = overlay;
    return overlay;
  }

  /* ------------------------------------------------------------------ */
  /* Open / Close                                                         */
  /* ------------------------------------------------------------------ */
  function openDrawer(initVal) {
    if (!_drawer) buildDrawer();
    _drawer.classList.add('aics-open');
    document.body.classList.add('aics-no-scroll');
    fetchCartNonce();

    if (initVal && initVal.length >= 2) {
      _input.value = initVal;
      _clearBtn.style.display = '';
      triggerSearch(initVal);
    } else {
      _input.value = '';
      _results.innerHTML = renderPlaceholder();
    }

    requestAnimationFrame(function () {
      _input.focus();
      _input.select();
    });
    trapFocus(_drawer);
  }

  function closeDrawer() {
    if (!_drawer) return;
    _drawer.classList.remove('aics-open');
    document.body.classList.remove('aics-no-scroll');
    if (_controller) { _controller.abort(); _controller = null; }
  }

  function trapFocus(el) {
    var sel = 'button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])';
    function handler(e) {
      if (e.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(el.querySelectorAll(sel))
        .filter(function (n) { return !n.disabled && n.offsetParent !== null; });
      if (!focusable.length) return;
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault(); first.focus();
      }
    }
    el.addEventListener('keydown', handler);
  }

  /* ------------------------------------------------------------------ */
  /* Input handler                                                        */
  /* ------------------------------------------------------------------ */
  function onInput() {
    var val = _input.value;
    _clearBtn.style.display = val ? '' : 'none';
    clearTimeout(_debounce);
    if (val.length < 2) {
      _results.innerHTML = renderPlaceholder();
      _currentQuery = '';
      return;
    }
    _debounce = setTimeout(function () { triggerSearch(val); }, 300);
  }

  function triggerSearch(q) {
    if (q === _currentQuery) return;
    _currentQuery = q;
    if (_controller) { _controller.abort(); }
    _controller = new AbortController();
    _results.innerHTML = renderLoading();
    fetch(Cfg.restUrl + '/search?query=' + encodeURIComponent(q), {
      signal: _controller.signal,
      headers: { 'X-WP-Nonce': Cfg.nonce },
      credentials: 'same-origin'
    })
    .then(function (r) {
      if (!r.ok) throw new Error('search_failed');
      return r.json();
    })
    .then(function (data) {
      _controller = null;
      renderResults(data);
    })
    .catch(function (err) {
      if (err.name === 'AbortError') return;
      _controller = null;
      _results.innerHTML = '<div class="aics-msg aics-err" role="alert">' + esc(L.typeToSearch) + '</div>';
    });
  }

  /* ------------------------------------------------------------------ */
  /* Rendering                                                            */
  /* ------------------------------------------------------------------ */
  function renderPlaceholder() {
    return '<div class="aics-msg aics-ph" dir="rtl">' +
      '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
      '<p>' + esc(L.typeToSearch) + '</p></div>';
  }

  function renderLoading() {
    return '<div class="aics-msg" role="status" aria-label="' + esc(L.searching) + '">' +
      '<span class="aics-spin" aria-hidden="true"></span>' +
      '<span>' + esc(L.searching) + '</span></div>';
  }

  function renderResults(data) {
    var products = data.products || [];
    if (!products.length) {
      _results.innerHTML = '<div class="aics-msg" role="status">' + esc(L.noResults) + '</div>';
      return;
    }

    var html = '<div class="aics-grid" dir="rtl" role="list">';
    for (var i = 0; i < products.length; i++) {
      html += buildCard(products[i]);
    }
    html += '</div>';
    _results.innerHTML = html;

    // Attach add-to-cart
    var btns = _results.querySelectorAll('.aics-atc');
    for (var j = 0; j < btns.length; j++) {
      (function (btn) {
        btn.addEventListener('click', function () { handleAtc(btn); });
      })(btns[j]);
    }
  }

  function buildCard(p) {
    var inStock = p.in_stock;
    var isVar   = p.type === 'variable';

    var imgHtml = p.image
      ? '<img src="' + esc(p.image) + '" alt="' + esc(p.name) + '" class="aics-img" loading="lazy" />'
      : '<div class="aics-img aics-no-img" aria-hidden="true"></div>';

    var priceHtml = buildPrice(p);

    var stockHtml = inStock
      ? '<span class="aics-stock aics-ok">' + esc(L.inStock) + '</span>'
      : '<span class="aics-stock aics-oos">' + esc(L.outOfStock) + '</span>';

    var varHtml = isVar && p.attributes && p.attributes.length ? buildVars(p) : '';

    var qtyHtml = inStock
      ? '<div class="aics-qty-wrap" aria-label="' + esc(L.quantity) + '">' +
          '<button type="button" class="aics-qty-btn aics-minus" data-pid="' + p.id + '" aria-label="פחות">−</button>' +
          '<input type="number" class="aics-qty" min="1" value="1" data-pid="' + p.id + '" aria-label="' + esc(L.quantity) + '" />' +
          '<button type="button" class="aics-qty-btn aics-plus" data-pid="' + p.id + '" aria-label="יותר">+</button>' +
        '</div>'
      : '';

    var atcDisabled = !inStock ? ' disabled aria-disabled="true"' : '';
    var atcLabel    = !inStock ? esc(L.outOfStock) : esc(L.addToCart);

    return '<article class="aics-card" role="listitem" data-pid="' + p.id + '" data-type="' + esc(p.type) + '">' +
      '<a href="' + esc(p.permalink) + '" class="aics-img-link" tabindex="-1" aria-hidden="true">' + imgHtml + '</a>' +
      '<div class="aics-info">' +
        '<a href="' + esc(p.permalink) + '" class="aics-name">' + esc(p.name) + '</a>' +
        '<div class="aics-meta">' + priceHtml + stockHtml + '</div>' +
        varHtml +
        qtyHtml +
        '<div class="aics-actions">' +
          '<button type="button" class="aics-atc" data-pid="' + p.id + '" data-type="' + esc(p.type) + '"' + atcDisabled + '>' + atcLabel + '</button>' +
          '<a href="' + esc(p.permalink) + '" class="aics-view" aria-label="' + esc(p.name) + ' - ' + esc(L.viewProduct) + '">' + esc(L.viewProduct) + '</a>' +
        '</div>' +
      '</div>' +
    '</article>';
  }

  function buildPrice(p) {
    if (p.is_weighed) {
      return '<span class="aics-price">' + (p.price_html || esc(Cfg.currency + p.price)) + '</span>' +
             '<span class="aics-wn">' + esc(L.weightedPriceNote) + '</span>';
    }
    if (p.price_html) {
      return '<span class="aics-price">' + p.price_html + '</span>';
    }
    if (p.is_on_sale && p.sale_price) {
      return '<del class="aics-reg">' + esc(Cfg.currency + p.regular_price) + '</del>' +
             '<span class="aics-sale">' + esc(Cfg.currency + p.sale_price) + '</span>';
    }
    return '<span class="aics-price">' + esc(Cfg.currency + (p.price || '')) + '</span>';
  }

  function buildVars(p) {
    var html = '<div class="aics-vars" data-pid="' + p.id + '">';
    for (var i = 0; i < p.attributes.length; i++) {
      var a = p.attributes[i];
      var opts = '<option value="">' + esc(L.selectOption) + '</option>';
      for (var j = 0; j < a.options.length; j++) {
        opts += '<option value="' + esc(a.options[j]) + '">' + esc(a.options[j]) + '</option>';
      }
      html += '<div class="aics-var-row">' +
        '<label class="aics-var-label" for="aics-v-' + p.id + '-' + i + '">' + esc(a.name) + '</label>' +
        '<select id="aics-v-' + p.id + '-' + i + '" class="aics-var-sel"' +
          ' data-pid="' + p.id + '" data-attr="' + esc(a.name) + '">' + opts + '</select>' +
      '</div>';
    }
    return html + '</div>';
  }

  /* ------------------------------------------------------------------ */
  /* Add to cart                                                          */
  /* ------------------------------------------------------------------ */
  function getQty(pid) {
    var inp = _results.querySelector('.aics-qty[data-pid="' + pid + '"]');
    return inp ? Math.max(1, parseInt(inp.value, 10) || 1) : 1;
  }

  function getVariation(pid) {
    var wrap = _results.querySelector('.aics-vars[data-pid="' + pid + '"]');
    if (!wrap) return [];
    var sels = wrap.querySelectorAll('.aics-var-sel');
    var v = [];
    for (var i = 0; i < sels.length; i++) {
      if (sels[i].value) {
        v.push({ attribute: sels[i].dataset.attr, value: sels[i].value });
      }
    }
    return v;
  }

  function handleAtc(btn) {
    var pid      = parseInt(btn.dataset.pid, 10);
    var ptype    = btn.dataset.type;
    var qty      = getQty(pid);
    var varData  = getVariation(pid);

    if (ptype === 'variable' && varData.length === 0) {
      var wrap = _results.querySelector('.aics-vars[data-pid="' + pid + '"]');
      if (wrap) {
        var firstEmpty = wrap.querySelector('.aics-var-sel');
        if (firstEmpty && !firstEmpty.value) {
          firstEmpty.classList.add('aics-req');
          firstEmpty.focus();
          setTimeout(function () { firstEmpty.classList.remove('aics-req'); }, 2000);
          return;
        }
      }
    }

    btn.disabled = true;
    btn.textContent = L.searching;

    var promise = _cartNonce ? Promise.resolve() : fetchCartNonce();

    promise.then(function () {
      var body = { id: pid, quantity: qty };
      if (varData.length) body.variation = varData;

      return fetch(Cfg.storeUrl + '/cart/add-item', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': Cfg.nonce,
          'Nonce': _cartNonce || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify(body)
      });
    })
    .then(function (r) {
      if (!r.ok) return r.json().then(function (e) { throw new Error(e.message || 'failed'); });
      return r.json();
    })
    .then(function (cart) {
      btn.textContent = L.addedToCart;
      btn.classList.add('aics-added');
      refreshCartCount(cart.items_count);
      setTimeout(function () {
        if (!btn.isConnected) return;
        btn.textContent = L.addToCart;
        btn.classList.remove('aics-added');
        btn.disabled = false;
      }, 2500);
    })
    .catch(function () {
      btn.textContent = L.errorAdding;
      btn.classList.add('aics-err-btn');
      setTimeout(function () {
        if (!btn.isConnected) return;
        btn.textContent = L.addToCart;
        btn.classList.remove('aics-err-btn');
        btn.disabled = false;
      }, 2500);
    });
  }

  function refreshCartCount(count) {
    document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));
    document.body.dispatchEvent(new CustomEvent('added_to_cart', { bubbles: true }));
    if (window.jQuery) {
      jQuery(document.body).trigger('wc_fragment_refresh');
      jQuery(document.body).trigger('added_to_cart');
    }
    if (typeof count !== 'undefined') {
      var els = document.querySelectorAll('.cart-contents-count,.cart-count,[data-cart-count],.widget_shopping_cart_item_count');
      for (var i = 0; i < els.length; i++) {
        els[i].textContent = count;
      }
    }
  }

  /* ------------------------------------------------------------------ */
  /* Intercept search fields                                              */
  /* ------------------------------------------------------------------ */
  var SELECTORS = [
    'input[type="search"]',
    'input.search-field',
    'input[name="s"]',
    '.wp-block-search__input',
    '.woocommerce-product-search input',
  ].join(',');

  function attach(field) {
    if (field.dataset.aicsHooked) return;
    field.dataset.aicsHooked = '1';

    function handler(e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      openDrawer(field.value);
      field.blur();
    }

    field.addEventListener('focus', handler);
    field.addEventListener('click', handler);
  }

  function attachAll() {
    var fields = document.querySelectorAll(SELECTORS);
    for (var i = 0; i < fields.length; i++) attach(fields[i]);
  }

  var _observer = new MutationObserver(function (muts) {
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var node = added[j];
        if (node.nodeType !== 1) continue;
        if (node.matches && node.matches(SELECTORS)) attach(node);
        if (node.querySelectorAll) {
          var inner = node.querySelectorAll(SELECTORS);
          for (var k = 0; k < inner.length; k++) attach(inner[k]);
        }
      }
    }
  });

  /* ------------------------------------------------------------------ */
  /* Mobile keyboard awareness                                            */
  /* ------------------------------------------------------------------ */
  function setupViewport() {
    if (!window.visualViewport) return;
    window.visualViewport.addEventListener('resize', function () {
      if (!_drawer || !_drawer.classList.contains('aics-open')) return;
      var panel = _drawer.querySelector('#aics-panel');
      if (panel) {
        panel.style.maxHeight = (window.visualViewport.height - 20) + 'px';
      }
    });
  }

  /* ------------------------------------------------------------------ */
  /* Init                                                                 */
  /* ------------------------------------------------------------------ */
  function init() {
    attachAll();
    _observer.observe(document.body, { childList: true, subtree: true });
    setupViewport();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
