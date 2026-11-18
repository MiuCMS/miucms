const memberI18n = document.querySelector('#template-i18n')?.dataset || {};
const memberText = key => memberI18n[key] || key;

// 浏览器可能忽略 autocomplete="off"；用户操作输入框后再解除只读，避免页面加载时预填。
document.querySelectorAll('input[data-no-autofill]').forEach(input => {
  input.readOnly = true;
  const unlock = () => { input.readOnly = false; };
  input.addEventListener('pointerdown', unlock, { once: true });
  input.addEventListener('focus', unlock, { once: true });
});

async function initLocationCascade() {
  const wrapper = document.querySelector('[data-location-cascade]');
  if (!wrapper) return;

  const countrySelect = wrapper.querySelector('[data-location-country]');
  const provinceSelect = wrapper.querySelector('[data-location-province]');
  const citySelect = wrapper.querySelector('[data-location-city]');
  const status = wrapper.querySelector('[data-location-status]');
  if (!countrySelect || !provinceSelect || !citySelect) return;

  const current = {
    country: countrySelect.value,
    province: provinceSelect.value,
    city: citySelect.value
  };
  const textLength = value => Array.from(String(value ?? '').trim()).length;
  const validName = value => typeof value === 'string' && value.trim() !== '' && textLength(value) <= 20;
  const normalizeName = value => String(value ?? '').trim().toLocaleLowerCase()
    .replace(/\s+/g, '')
    .replace(/(?:特别行政区|维吾尔自治区|壮族自治区|回族自治区|自治区|自治州|省|市|地区|盟)$/u, '');
  const matchesName = (candidate, value) => normalizeName(candidate) !== '' && normalizeName(candidate) === normalizeName(value);

  let regionNames;
  try {
    regionNames = typeof Intl.DisplayNames === 'function'
      ? new Intl.DisplayNames([document.documentElement.lang || 'zh-CN'], { type: 'region' })
      : null;
  } catch {}

  let data;
  try {
    const response = await fetch(wrapper.dataset.locationUrl, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('Location data request failed');
    data = await response.json();
  } catch {
    if (status) {
      status.textContent = memberText('userLocationFailed');
      status.hidden = false;
    }
    return;
  }

  const countries = (Array.isArray(data.countries) ? data.countries : []).map(country => {
    let localizedName = '';
    try {
      localizedName = regionNames?.of(country.code) || '';
    } catch {}
    const label = [localizedName, country.native, country.name, country.code].find(validName);
    const states = (Array.isArray(country.states) ? country.states : [])
      .filter(state => validName(state.name))
      .map(state => {
        const cities = (Array.isArray(state.cities) ? state.cities : []).filter(validName);
        if (cities.length === 0) cities.push(state.name);
        return {
          label: state.name,
          value: state.name,
          aliases: [state.name],
          cities: cities.map(name => ({ label: name, value: name, aliases: [name] }))
        };
      });
    return {
      label,
      value: label,
      code: country.code,
      aliases: [label, country.name, country.native, country.code, country.code === 'CN' ? '中华人民共和国' : ''].filter(Boolean),
      states
    };
  }).filter(country => country.label).sort((a, b) => a.label.localeCompare(b.label));

  function fillSelect(select, items, placeholder, previousValue) {
    select.replaceChildren(new Option(placeholder, ''));
    let selected = null;
    items.forEach((item, index) => {
      const option = new Option(item.label, item.value);
      option.dataset.locationIndex = String(index);
      select.add(option);
      if (!selected && previousValue && [item.value, item.label, ...(item.aliases || [])].some(name => matchesName(name, previousValue))) {
        selected = item;
        option.selected = true;
      }
    });
    if (!selected && previousValue) {
      select.add(new Option(previousValue, previousValue, true, true));
    }
    select.disabled = items.length === 0 && !previousValue;
    return selected;
  }

  function selectedItem(select, items) {
    const index = select.selectedOptions[0]?.dataset.locationIndex;
    return index === undefined || index === '' ? null : items[Number(index)] || null;
  }

  function syncLabel(select) {
    select.closest('.account-float-field')?.classList.toggle(
      'is-floating',
      document.activeElement === select || select.value !== ''
    );
  }

  let activeCountry = fillSelect(countrySelect, countries, memberText('userCountryPlaceholder'), current.country);
  let activeState = fillSelect(provinceSelect, activeCountry?.states || [], memberText('userProvincePlaceholder'), current.province);
  fillSelect(citySelect, activeState?.cities || [], memberText('userCityPlaceholder'), current.city);

  countrySelect.addEventListener('change', () => {
    activeCountry = selectedItem(countrySelect, countries);
    activeState = fillSelect(provinceSelect, activeCountry?.states || [], memberText('userProvincePlaceholder'), '');
    fillSelect(citySelect, activeState?.cities || [], memberText('userCityPlaceholder'), '');
    syncLabel(provinceSelect);
    syncLabel(citySelect);
  });

  provinceSelect.addEventListener('change', () => {
    activeState = selectedItem(provinceSelect, activeCountry?.states || []);
    fillSelect(citySelect, activeState?.cities || [], memberText('userCityPlaceholder'), '');
    syncLabel(citySelect);
  });

  [countrySelect, provinceSelect, citySelect].forEach(syncLabel);
}

initLocationCascade();
document.querySelectorAll('[data-avatar-upload]').forEach(wrapper => {
  const fileInput = wrapper.querySelector('[data-avatar-file]');
  const pickButton = wrapper.querySelector('[data-avatar-pick]');
  const previewWrap = wrapper.querySelector('[data-avatar-preview-wrap]');
  const status = wrapper.querySelector('[data-avatar-status]');
  const form = wrapper.closest('form');
  if (!fileInput || !pickButton || !previewWrap) return;
  const pickButtonText = pickButton.textContent.trim();
  let cooldownUntil = 0;
  let cooldownTimer = 0;

  const setStatus = (message, success = false) => {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-success', success);
  };
  const startCooldown = seconds => {
    cooldownUntil = Date.now() + Math.max(1, Number(seconds) || 60) * 1000;
    window.clearTimeout(cooldownTimer);
    pickButton.disabled = true;
    pickButton.textContent = pickButtonText;
    cooldownTimer = window.setTimeout(() => {
      if (cooldownUntil <= Date.now()) pickButton.disabled = false;
    }, Math.max(1, cooldownUntil - Date.now()));
  };
  const showUploadError = message => {
    setStatus(message);
    if (typeof toast === 'function') toast(message);
  };

  pickButton.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    if (file.size > 300 * 1024) {
      showUploadError(memberText('userAvatarTooLarge'));
      fileInput.value = '';
      return;
    }

    const objectUrl = URL.createObjectURL(file);
    try {
      const image = new Image();
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = reject;
        image.src = objectUrl;
      });
      if (image.naturalWidth !== image.naturalHeight) {
        showUploadError(memberText('userAvatarNotSquare'));
        fileInput.value = '';
        return;
      }
    } catch {
      showUploadError(memberText('userAvatarInvalid'));
      fileInput.value = '';
      return;
    } finally {
      URL.revokeObjectURL(objectUrl);
    }

    pickButton.disabled = true;
    form?.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
    wrapper.setAttribute('aria-busy', 'true');
    setStatus(memberText('userAvatarUploading'));
    try {
      const body = new FormData();
      body.append('file', file);
      const response = await fetch(wrapper.dataset.uploadUrl, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
      });
      const result = await response.json();
      if (!response.ok || Number(result.code) !== 0 || !result.data?.filepath) {
        if (Number(result.data?.retry_after) > 0) startCooldown(result.data.retry_after);
        throw new Error(result.msg || memberText('userAvatarUploadFailed'));
      }

      let preview = previewWrap.querySelector('[data-avatar-preview]');
      if (!preview) {
        preview = document.createElement('img');
        preview.dataset.avatarPreview = '';
        preview.alt = memberText('userAvatarAlt');
        previewWrap.replaceChildren(preview);
      }
      preview.src = result.data.filepath;
      setStatus(memberText('userAvatarUploaded'), true);
      startCooldown(60);
    } catch (error) {
      showUploadError(error.message || memberText('userAvatarUploadFailed'));
    } finally {
      wrapper.setAttribute('aria-busy', 'false');
      if (cooldownUntil <= Date.now()) pickButton.disabled = false;
      form?.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = false; });
      fileInput.value = '';
    }
  });
});
document.querySelectorAll('.account-float-field').forEach(field => {
  const control = field.querySelector('input, textarea, select');
  if (!control) return;
  const syncLabel = () => field.classList.toggle('is-floating', document.activeElement === control || control.value !== '');
  ['input', 'change', 'focus', 'blur'].forEach(event => control.addEventListener(event, syncLabel));
  syncLabel();
});

function refreshCaptcha(form) {
  form.querySelectorAll('[data-captcha-refresh] img').forEach(image => {
    const source = image.dataset.source || image.getAttribute('src') || '';
    image.dataset.source = source;
    image.src = `${source}${source.includes('?') ? '&' : '?'}_=${Date.now()}`;
  });
}
document.querySelectorAll('[data-captcha-refresh]').forEach(image => {
  image.addEventListener('click', () => {
    const form = image.closest('form');
    if (form) refreshCaptcha(form);
  });
});

document.querySelectorAll('[data-register-email-code]').forEach(button => button.addEventListener('click', async () => {
  const form = button.closest('form');
  const email = form?.querySelector('[name="email"]');
  const captcha = form?.querySelector('[name="captcha"]');
  const feedback = form?.querySelector('[data-form-feedback]');
  if (!form || !email?.value.trim() || !captcha?.value.trim()) {
    if (feedback) feedback.textContent = memberText('userEmailCaptchaRequired');
    return;
  }
  button.disabled = true;
  if (feedback) {
    feedback.textContent = memberText('userSendingCode');
    feedback.classList.remove('success');
  }
  try {
    const response = await fetch(button.dataset.url, {
      method: 'POST',
      body: new URLSearchParams({ email: email.value.trim(), captcha: captcha.value.trim() }),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    });
    const result = await response.json();
    if (feedback) {
      feedback.textContent = result.msg || (Number(result.code) === 0 ? memberText('userCodeSent') : memberText('userCodeFailed'));
      feedback.classList.toggle('success', response.ok && Number(result.code) === 0);
    }
    refreshCaptcha(form);
    captcha.value = '';
  } catch {
    if (feedback) feedback.textContent = memberText('userNetworkFailed');
    refreshCaptcha(form);
    captcha.value = '';
  } finally {
    button.disabled = false;
  }
}));

document.querySelectorAll('[data-account-form]').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[type="submit"]');
  const feedback = form.querySelector('[data-form-feedback]');
  if (submit) submit.disabled = true;
  if (feedback) {
    feedback.textContent = memberText('userSubmitting');
    feedback.classList.remove('success');
  }
  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    });
    const result = await response.json();
    const success = response.ok && Number(result.code) === 0;
    if (feedback) {
      feedback.textContent = result.msg || (success ? memberText('userOperationSuccess') : memberText('userSubmitFailed'));
      feedback.classList.toggle('success', success);
    }
    if (success && form.dataset.successUrl) {
      window.setTimeout(() => window.location.assign(form.dataset.successUrl), form.action.includes('reg_submit') ? 900 : 500);
    } else if (!success) {
      refreshCaptcha(form);
    }
  } catch {
    if (feedback) feedback.textContent = memberText('userNetworkFailed');
    refreshCaptcha(form);
  } finally {
    if (submit) submit.disabled = false;
  }
}));

document.querySelectorAll('[data-member-logout]').forEach(button => button.addEventListener('click', async () => {
  if (!window.confirm(memberText('userLogoutConfirm'))) return;
  button.disabled = true;
  try {
    const response = await fetch(button.dataset.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    });
    const result = await response.json();
    if (response.ok && Number(result.code) === 0) {
      window.location.assign(button.dataset.loginUrl);
      return;
    }
    toast(result.msg || memberText('userLogoutFailed'));
  } catch {
    toast(memberText('userNetworkFailed'));
  } finally {
    button.disabled = false;
  }
}));

document.querySelectorAll('[data-invite-url]').forEach(input => {
  const button = input.closest('.member-invite-link')?.querySelector('[data-copy-invite]');
  const feedback = input.closest('.member-invite-link')?.querySelector('[data-invite-feedback]');
  if (!button) return;
  input.value = new URL(input.value, window.location.href).href;
  button.addEventListener('click', async () => {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(input.value);
      } else {
        input.focus();
        input.select();
        if (!document.execCommand('copy')) throw new Error('copy failed');
      }
      if (feedback) feedback.textContent = memberText('userInviteCopied');
    } catch {
      input.focus();
      input.select();
      if (feedback) feedback.textContent = memberText('userCopyFailed');
    }
  });
});
