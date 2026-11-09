/* =====================================================================
   GASATTEND - shared front-end behaviour (no dependencies)
   1 Sidebar   2 Dropdowns   3 Toasts   4 Confirm modal   5 Auto-submit
   6 Form validation   7 Auth page   8 Attendance helpers   9 Photo preview
   ===================================================================== */
(function () {
  'use strict';
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* 1. SIDEBAR ------------------------------------------------------- */
  var menuBtn = $('#menu-toggle'), scrim = $('#scrim');
  if (menuBtn) {
    menuBtn.addEventListener('click', function () {
      if (window.matchMedia('(max-width: 900px)').matches) document.body.classList.toggle('sidebar-open');
      else document.body.classList.toggle('sidebar-collapsed');
    });
  }
  if (scrim) scrim.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
  $$('.nav-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.parentElement;
      var open = group.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* 2. DROPDOWNS (bell, user menu, table row actions) ---------------- */
  function closeDropdowns(except) {
    $$('.dropdown.open').forEach(function (d) { if (d !== except) d.classList.remove('open'); });
  }
  function placeMenu(btn, menu) {
    // Row menus live inside a horizontally scrolling table, so they are positioned relative to the viewport.
    if (!btn.closest('.table-wrap')) return;
    var r = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.left = 'auto';
    menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
    var h = menu.offsetHeight;
    var top = r.bottom + 6;
    if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
    menu.style.top = top + 'px';
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-dropdown]');
    if (btn) {
      var dd = btn.closest('.dropdown');
      var willOpen = !dd.classList.contains('open');
      closeDropdowns(dd);
      dd.classList.toggle('open', willOpen);
      if (willOpen) placeMenu(btn, $('.dropdown-menu', dd));
      return;
    }
    if (!e.target.closest('.dropdown-menu')) closeDropdowns();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeDropdowns(); closeModal(); document.body.classList.remove('sidebar-open'); }
  });
  window.addEventListener('scroll', function () { closeDropdowns(); }, true);
  window.addEventListener('resize', function () { closeDropdowns(); });

  /* 3. TOASTS -------------------------------------------------------- */
  function dismissToast(t) {
    t.classList.add('leaving');
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
  }
  $$('.toast').forEach(function (t) {
    var close = $('button', t);
    if (close) close.addEventListener('click', function () { dismissToast(t); });
    setTimeout(function () { dismissToast(t); }, t.classList.contains('error') ? 9000 : 5500);
  });

  /* 4. CONFIRM MODAL ------------------------------------------------- */
  var modal = $('#confirm-modal'), pendingForm = null;
  function closeModal() { if (modal) modal.classList.remove('open'); pendingForm = null; }
  if (modal) {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.matches || !form.matches('form[data-confirm]')) return;
      e.preventDefault();
      pendingForm = form;
      $('#confirm-title').textContent = form.dataset.confirmTitle || 'Are you sure?';
      $('#confirm-text').textContent = form.dataset.confirm;
      var ok = $('[data-modal-ok]', modal);
      ok.textContent = form.dataset.confirmBtn || 'Confirm';
      ok.className = 'btn ' + (form.dataset.confirmStyle === 'primary' ? 'btn-primary' : 'btn-danger');
      closeDropdowns();
      modal.classList.add('open');
      $('[data-modal-cancel]', modal).focus();
    });
    $('[data-modal-cancel]', modal).addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    $('[data-modal-ok]', modal).addEventListener('click', function () {
      var f = pendingForm;
      closeModal();
      if (f) f.submit();           // submit() skips the submit event, so there is no loop
    });
  }

  /* 5. AUTO-SUBMIT (date pickers, filters) --------------------------- */
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) {
      if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit();
    }
  });

  /* 6. FORM VALIDATION ----------------------------------------------- */
  function fieldOf(input) { return input.closest('[data-field]'); }
  function showError(input, msg) {
    var f = fieldOf(input);
    if (!f) return;
    f.classList.add('has-error');
    var out = $('.field-error', f);
    if (out) out.textContent = msg;
  }
  function clearError(input) {
    var f = fieldOf(input);
    if (!f) return;
    f.classList.remove('has-error');
    var out = $('.field-error', f);
    if (out) out.textContent = '';
  }
  function messageFor(input) {
    var v = input.validity;
    if (v.valueMissing) return input.dataset.msgRequired || 'This field is required.';
    if (v.typeMismatch) return input.type === 'email' ? 'Enter a valid email address.' : 'Enter a valid value.';
    if (v.tooShort) return 'Use at least ' + input.minLength + ' characters.';
    if (v.patternMismatch) return input.dataset.msgPattern || 'Check the format of this value.';
    if (v.rangeOverflow || v.rangeUnderflow) return 'That value is out of range.';
    return input.validationMessage || 'Please check this value.';
  }
  function applyCustomRules(form) {
    $$('input, select', form).forEach(function (i) { i.setCustomValidity(''); });
    $$('input[autocomplete="new-password"]', form).forEach(function (i) {
      if (i.name === 'confirm') {
        var base = $('input[name="password"], input[name="new"]', form);
        if (base && i.value !== base.value) i.setCustomValidity('Passwords do not match.');
      } else if (i.value && !(/[A-Za-z]/.test(i.value) && /\d/.test(i.value))) {
        i.setCustomValidity('Include at least one letter and one number.');
      }
    });
    var tin = $('[data-time-in]', form), tout = $('[data-time-out]', form);
    if (tin && tout && !tin.disabled && !tout.disabled && tin.value && tout.value && tout.value < tin.value) {
      tout.setCustomValidity('Time out is earlier than time in.');
    }
    var from = $('input[name="from"]', form), to = $('input[name="to"]', form);
    if (from && to && from.value && to.value && to.value < from.value) to.setCustomValidity('“To” date must be on or after “From”.');
  }
  function checkInput(form, input) {
    applyCustomRules(form);
    if (input.willValidate && !input.checkValidity()) { showError(input, messageFor(input)); return false; }
    clearError(input);
    return true;
  }
  $$('form[data-validate]').forEach(function (form) {
    form.setAttribute('novalidate', '');
    form.addEventListener('submit', function (e) {
      applyCustomRules(form);
      var first = null;
      $$('input, select, textarea', form).forEach(function (i) {
        if (!i.willValidate) return;
        if (!i.checkValidity()) { showError(i, messageFor(i)); if (!first) first = i; } else clearError(i);
      });
      if (first) { e.preventDefault(); first.focus(); return; }
      var btn = $('button[type="submit"]', form);
      if (btn && !e.defaultPrevented) setTimeout(function () { btn.disabled = true; }, 0);   // block double-clicks
    });
    form.addEventListener('blur', function (e) {
      if (e.target.matches && e.target.matches('input, select, textarea') && e.target.value !== '') checkInput(form, e.target);
    }, true);
    form.addEventListener('input', function (e) {
      var f = fieldOf(e.target);
      if (f && f.classList.contains('has-error')) checkInput(form, e.target);
    });
  });
  window.addEventListener('pageshow', function () {            // back button: re-enable disabled submit buttons
    $$('form[data-validate] button[type="submit"]').forEach(function (b) { b.disabled = false; });
  });

  /* 7. AUTH PAGE ----------------------------------------------------- */
  $$('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = $('input', btn.closest('.input-wrap'));
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.classList.toggle('shown', show);
    });
  });
  var tabs = $$('[data-tab]');
  if (tabs.length) {
    var panels = $$('[data-panel]');
    var sub = $('#auth-sub'), sw = $('#auth-switch');
    var text = {
      login: { sub: 'Sign in to continue to your workspace', sw: 'Don’t have an account? <button type="button" data-tab-link="register">Create Account</button>', sso: 'Sign in with ' },
      register: { sub: 'Create your account to get started', sw: 'Already have an account? <button type="button" data-tab-link="login">Sign In</button>', sso: 'Sign up with ' }
    };
    var show = function (name) {
      tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
      panels.forEach(function (p) { p.classList.toggle('active', p.dataset.panel === name); });
      sub.textContent = text[name].sub;
      sw.innerHTML = text[name].sw;
      var providers = ['Google', 'Microsoft'];
      $$('[data-sso-label]').forEach(function (el, i) { el.textContent = text[name].sso + providers[i]; });
      document.title = (name === 'login' ? 'Sign in' : 'Create account') + ' | GASATTEND';
      var first = $('[data-panel="' + name + '"] input:not([type="hidden"])');
      if (first && document.activeElement === document.body) { /* keep focus natural on first load */ }
    };
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });
    document.addEventListener('click', function (e) {
      var l = e.target.closest('[data-tab-link]');
      if (l) show(l.dataset.tabLink);
    });
    var current = $('[data-tab].active');
    show(current ? current.dataset.tab : 'login');
  }
  var strengthBox = $('#strength');
  if (strengthBox) {
    var pw = $('#register-password');
    pw.addEventListener('input', function () {
      var v = pw.value, score = 0;
      if (v.length >= 10) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v) || v.length >= 14) score++;
      strengthBox.classList.toggle('show', v.length > 0);
      var bar = $('i', strengthBox);
      bar.style.width = (score * 25) + '%';
      bar.style.background = ['#dc2626', '#dc2626', '#e8890c', '#0f9f6e', '#0f9f6e'][score];
    });
  }

  /* 8. ATTENDANCE HELPERS -------------------------------------------- */
  var attForm = $('[data-attendance-form]');
  if (attForm) {
    var status = $('[data-status-select]', attForm), tIn = $('[data-time-in]', attForm), tOut = $('[data-time-out]', attForm), hint = $('[data-time-hint]', attForm);
    var workStart = attForm.dataset.workStart || '09:00';
    var syncTimes = function () {
      var needs = status.value === 'Present' || status.value === 'Late';
      tIn.disabled = tOut.disabled = !needs;
      tIn.required = needs;
      tIn.dataset.msgRequired = 'Time in is required for ' + status.value + '.';
      if (!needs) { clearError(tIn); clearError(tOut); }
      hint.textContent = needs ? '' : 'Time in/out do not apply to “' + status.value + '”.';
    };
    status.addEventListener('change', syncTimes);
    tIn.addEventListener('change', function () {
      if (!tIn.value) return;
      if (status.value === 'Present' && tIn.value > workStart) {
        status.value = 'Late';
        hint.textContent = 'Time in is after ' + workStart + ' — status changed to Late. You can change it back.';
      } else if (status.value === 'Late' && tIn.value <= workStart) {
        status.value = 'Present';
        hint.textContent = 'Time in is on or before ' + workStart + ' — status changed to Present.';
      }
    });
    syncTimes();
  }
  var bulk = $('[data-bulk-form]');
  if (bulk) {
    var defIn = $('[data-bulk-in]', bulk), defOut = $('[data-bulk-out]', bulk);
    var needsTime = function (v) { return v === 'Present' || v === 'Late'; };
    var syncRow = function (row, fill) {
      var s = $('[data-row-status]', row), i = $('[data-row-in]', row), o = $('[data-row-out]', row);
      var needs = needsTime(s.value);
      i.disabled = o.disabled = !needs;
      if (needs && fill) { if (!i.value) i.value = defIn.value; if (!o.value && s.value === 'Present') o.value = defOut.value; }
    };
    $$('[data-row]', bulk).forEach(function (row) {
      syncRow(row, false);
      $('[data-row-status]', row).addEventListener('change', function () { syncRow(row, true); });
    });
    $$('[data-bulk-fill]', bulk).forEach(function (b) {
      b.addEventListener('click', function () {
        $$('[data-row]', bulk).forEach(function (row) {
          var s = $('[data-row-status]', row);
          if (s.value === '') { s.value = b.dataset.bulkFill; syncRow(row, true); }
        });
      });
    });
    var clear = $('[data-bulk-clear]', bulk);
    if (clear) clear.addEventListener('click', function () {
      $$('[data-row]', bulk).forEach(function (row) {
        if (row.dataset.saved === '1') return;
        $('[data-row-status]', row).value = '';
        $('[data-row-in]', row).value = ''; $('[data-row-out]', row).value = '';
        syncRow(row, false);
      });
    });
  }

  /* 9. PHOTO PREVIEW ------------------------------------------------- */
  var photo = $('[data-photo-input]');
  if (photo) {
    photo.addEventListener('change', function () {
      var file = photo.files && photo.files[0];
      var holder = $('#photo-preview .avatar');
      var field = photo.closest('[data-field]');
      clearError(photo);
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { photo.value = ''; showError(photo, 'Photo must be a JPG, PNG or WebP image.'); return; }
      if (file.size > 2 * 1024 * 1024) { photo.value = ''; showError(photo, 'Photo must be 2 MB or smaller.'); return; }
      if (holder) {
        holder.innerHTML = '';
        var img = document.createElement('img');
        img.alt = '';
        img.src = URL.createObjectURL(file);
        holder.appendChild(img);
      }
    });
  }
})();
