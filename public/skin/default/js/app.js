/* Singular static-site interactions only. All page copy and content live in HTML. */
const $ = (selector, root = document) => root.querySelector(selector);
const i18n = $('#template-i18n')?.dataset || {};
const t = key => i18n[key] || key;
const toast = (message) => {
  const node = $('.toast');
  if (!node) return;
  node.textContent = message;
  node.classList.add('is-visible');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => node.classList.remove('is-visible'), 3200);
};

document.querySelectorAll('[data-purchase]').forEach(button => button.addEventListener('click', () => {
  toast(`${button.dataset.purchase} ${t('purchaseNotice')}`);
}));

// Mobile navigation and the top-level category dropdowns.
const menuToggle = $('.menu-toggle'), nav = $('.primary-nav');
const aboutDropdowns = [...document.querySelectorAll('.nav-dropdown')];
function closeAboutDropdown(dropdown) {
  (dropdown ? [dropdown] : aboutDropdowns).forEach(item => {
    item.classList.remove('is-open');
    $('.nav-dropdown-toggle', item)?.setAttribute('aria-expanded', 'false');
  });
}
const canHoverAbout = matchMedia('(hover: hover) and (pointer: fine)').matches;
aboutDropdowns.forEach(dropdown => {
  const toggle = $('.nav-dropdown-toggle', dropdown);
  if (canHoverAbout) {
    dropdown.addEventListener('pointerenter', () => {
      dropdown.classList.add('is-open');
      toggle?.setAttribute('aria-expanded', 'true');
    });
    dropdown.addEventListener('pointerleave', () => {
      if (!dropdown.contains(document.activeElement)) closeAboutDropdown(dropdown);
    });
    dropdown.addEventListener('focusout', event => {
      if (!dropdown.contains(event.relatedTarget)) closeAboutDropdown(dropdown);
    });
  }
  toggle?.addEventListener('click', event => {
    if (canHoverAbout && event.detail > 0) return;
    const currentlyOpen = toggle.getAttribute('aria-expanded') === 'true';
    if (event.detail > 0 && !canHoverAbout && currentlyOpen) return;
    event.preventDefault();
    event.stopPropagation();
    const open = !currentlyOpen;
    if (open) closeAboutDropdown();
    toggle.setAttribute('aria-expanded', String(open));
    dropdown.classList.toggle('is-open', open);
  });
});
menuToggle?.addEventListener('click', () => {
  const open = menuToggle.getAttribute('aria-expanded') !== 'true';
  menuToggle.setAttribute('aria-expanded', String(open));
  nav?.classList.toggle('is-open', open);
  document.body.classList.toggle('menu-open', open);
});
nav?.addEventListener('click', event => {
  if (event.target.closest('a')) {
    closeAboutDropdown();
    menuToggle?.setAttribute('aria-expanded', 'false');
    nav.classList.remove('is-open');
    document.body.classList.remove('menu-open');
  }
});
document.addEventListener('click', event => {
  if (!aboutDropdowns.some(dropdown => dropdown.contains(event.target))) closeAboutDropdown();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    const openDropdown = aboutDropdowns.find(dropdown => dropdown.classList.contains('is-open'));
    if (openDropdown) {
      closeAboutDropdown();
      $('.nav-dropdown-toggle', openDropdown)?.focus();
    }
    if (nav?.classList.contains('is-open')) {
      menuToggle?.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
      document.body.classList.remove('menu-open');
      menuToggle?.focus();
    }
  }
});

// 页头下拉和会员表单直接调用系统已有的前台接口。
const headerPopovers = [...document.querySelectorAll('.header-popover')];
headerPopovers.forEach(opened => opened.addEventListener('toggle', () => {
  if (opened.open) headerPopovers.forEach(popover => {
    if (popover !== opened) popover.open = false;
  });
}));
document.addEventListener('click', event => {
  headerPopovers.forEach(popover => {
    if (popover.open && !popover.contains(event.target)) popover.open = false;
  });
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const opened = headerPopovers.filter(popover => popover.open);
  opened.forEach(popover => { popover.open = false; });
  opened[opened.length - 1]?.querySelector('summary')?.focus();
});

// Back-to-top control shared by every static page.
const backToTop = $('.back-to-top');
function syncBackToTop() {
  backToTop?.classList.toggle('is-visible', window.scrollY > 320);
}
window.addEventListener('scroll', syncBackToTop, { passive: true });
syncBackToTop();
backToTop?.addEventListener('click', () => {
  const behavior = matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
  window.scrollTo({ top: 0, behavior });
});

// Homepage carousel.
const slides = [...document.querySelectorAll('.hero-slide')];
const dots = [...document.querySelectorAll('[data-goto]')];
let currentSlide = 0, carouselTimer, carouselPaused = false;
function showSlide(index) {
  if (!slides.length) return;
  currentSlide = (index + slides.length) % slides.length;
  slides.forEach((slide, i) => {
    slide.classList.toggle('active', i === currentSlide);
    slide.setAttribute('aria-hidden', String(i !== currentSlide));
  });
  dots.forEach((dot, i) => {
    dot.classList.toggle('active', i === currentSlide);
  });
}
function carouselStart() {
  clearInterval(carouselTimer);
  if (slides.length && !carouselPaused && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    carouselTimer = setInterval(() => showSlide(currentSlide + 1), 6500);
  }
}
document.querySelectorAll('[data-hero]').forEach(button => button.addEventListener('click', () => {
  showSlide(currentSlide + (button.dataset.hero === 'next' ? 1 : -1));
  carouselStart();
}));
dots.forEach(button => button.addEventListener('click', () => {
  showSlide(Number(button.dataset.goto));
  carouselStart();
}));
const pauseButton = $('.hero-pause');
pauseButton?.addEventListener('click', () => {
  carouselPaused = !carouselPaused;
  pauseButton.textContent = carouselPaused ? '▶' : 'Ⅱ';
  pauseButton.setAttribute('aria-pressed', String(carouselPaused));
  carouselStart();
});
const hero = $('.hero');
hero?.addEventListener('mouseenter', () => clearInterval(carouselTimer));
hero?.addEventListener('mouseleave', carouselStart);
hero?.addEventListener('focusin', () => clearInterval(carouselTimer));
hero?.addEventListener('focusout', carouselStart);
carouselStart();

// Category filters operate on the already-rendered HTML items.
document.querySelectorAll('[data-filter-group]').forEach(group => group.addEventListener('click', event => {
  const button = event.target.closest('[data-filter]');
  if (!button) return;
  if (button.tagName === 'A') event.preventDefault();
  group.querySelectorAll('[data-filter]').forEach(option => {
    const selected = option === button;
    option.classList.toggle('active', selected);
  });
  group.dataset.activeFilter = button.dataset.filter;
  group.closest('main')?.dispatchEvent(new Event('listfilterchange'));
}));

document.querySelectorAll('[data-layout-toggle]').forEach(button => {
  const target = $(button.dataset.layoutTarget);
  if (!target) return;
  let horizontal = button.dataset.initialLayout === 'horizontal';
  const update = () => {
    target.classList.toggle('is-horizontal', horizontal);
    target.classList.toggle('is-vertical', !horizontal);
    button.setAttribute('aria-pressed', String(horizontal));
    const labelNode = $('[data-layout-label]', button);
    if (labelNode) labelNode.textContent = t('layoutToggle');
  };
  update();
  button.addEventListener('click', () => { horizontal = !horizontal; update(); });
});

// 详情页收藏按钮：登录状态由接口校验，页面可用于动态和静态详情模板。
const collectStateRequests = new WeakMap();
function updateCollectButton(button, collected, count) {
  button.dataset.collected = collected ? '1' : '0';
  button.setAttribute('aria-pressed', String(collected));
  const label = $('[data-collect-label]', button);
  const counter = $('[data-collect-count]', button);
  if (label) label.textContent = collected ? t('favorited') : t('favorite');
  if (counter && Number.isFinite(Number(count))) counter.textContent = String(Math.max(0, Number(count)));
}
document.querySelectorAll('[data-collect-button][data-collect-hydrate]').forEach(button => {
  const request = fetch(`${button.dataset.stateUrl}?doc_id=${encodeURIComponent(button.dataset.docId)}`, {
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
  }).then(async response => {
    if (!response.ok) return;
    const result = await response.json();
    if (Number(result.code) === 0) updateCollectButton(button, Boolean(result.data?.collected), result.data?.count);
  }).catch(() => {});
  collectStateRequests.set(button, request);
});
document.addEventListener('click', async event => {
  const button = event.target.closest('[data-collect-button]');
  if (!button || button.disabled) return;
  event.preventDefault();
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  try {
    await collectStateRequests.get(button);
    const response = await fetch(button.dataset.toggleUrl, {
      method: 'POST',
      body: new URLSearchParams({ doc_id: button.dataset.docId }),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    });
    const result = await response.json();
    if (response.status === 404 || Number(result.code) === 404) {
      toast(t('loginFirst'));
      return;
    }
    if (!response.ok || Number(result.code) !== 0) {
      toast(result.msg || t('favoriteFailed'));
      return;
    }
    updateCollectButton(button, Boolean(result.data?.collected), result.data?.count);
    toast(result.msg || t('operationSuccess'));
  } catch {
    toast(t('networkFailed'));
  } finally {
    button.disabled = false;
    button.removeAttribute('aria-busy');
  }
});

document.querySelectorAll('[data-list-pagination]').forEach(pager => {
  const main = pager.closest('main');
  const items = [...(main?.querySelectorAll(pager.dataset.pageItems) || [])];
  const pageSize = Math.max(1, Number(pager.dataset.pageSize) || 6);
  const pageTarget = items[0]?.closest('[id]')?.id || 'main';
  const numbers = $('[data-page-numbers]', pager);
  const previous = $('[data-page-action="prev"]', pager);
  const next = $('[data-page-action="next"]', pager);
  const summary = $('[data-page-summary]', pager);
  let currentPage = 1;
  const setActionDisabled = (link, disabled) => {
    if (!link) return;
    link.classList.toggle('disabled', disabled);
    link.tabIndex = disabled ? -1 : 0;
  };
  const categoryOf = item => item.dataset.category || item.querySelector('.card-meta > span')?.textContent?.trim() || item.querySelector('.product-index')?.textContent?.trim() || '';
  const render = reset => {
    if (reset) currentPage = 1;
    const activeFilter = main?.querySelector('[data-filter-group]')?.dataset.activeFilter || 'all';
    const filtered = items.filter(item => activeFilter === 'all' || categoryOf(item) === activeFilter);
    const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
    currentPage = Math.min(currentPage, pageCount);
    const start = (currentPage - 1) * pageSize;
    items.forEach(item => {
      const index = filtered.indexOf(item);
      item.hidden = index < 0 || index < start || index >= start + pageSize;
    });
    setActionDisabled(previous, currentPage === 1);
    setActionDisabled(next, currentPage === pageCount);
    if (summary) summary.textContent = t('pageSummary').replace('{page}', currentPage).replace('{pages}', pageCount).replace('{items}', filtered.length);
    if (!numbers) return;
    numbers.replaceChildren();
    for (let pageIndex = 1; pageIndex <= pageCount; pageIndex += 1) {
      const link = document.createElement('a');
      link.href = `#${pageTarget}`;
      link.className = 'page-number';
      link.textContent = String(pageIndex);
      if (pageIndex === currentPage) link.classList.add('current');
      link.addEventListener('click', event => {
        event.preventDefault();
        currentPage = pageIndex;
        render(false);
        numbers.children[pageIndex - 1]?.focus();
      });
      numbers.append(link);
    }
  };
  previous?.addEventListener('click', event => {
    event.preventDefault();
    if (currentPage > 1) { currentPage -= 1; render(false); }
  });
  next?.addEventListener('click', event => {
    event.preventDefault();
    const activeFilter = main?.querySelector('[data-filter-group]')?.dataset.activeFilter || 'all';
    const count = items.filter(item => activeFilter === 'all' || categoryOf(item) === activeFilter).length;
    if (currentPage < Math.ceil(count / pageSize)) { currentPage += 1; render(false); }
  });
  main?.addEventListener('listfilterchange', () => render(true));
  render(false);
});

// Case image gallery.
document.querySelectorAll('[data-case-gallery]').forEach(gallery => {
  const mainImage = $('[data-case-main]', gallery);
  const thumbs = [...gallery.querySelectorAll('[data-case-thumb]')];
  const count = $('[data-case-count]', gallery);
  let active = 0;
  const show = index => {
    if (!thumbs.length || !mainImage) return;
    active = (index + thumbs.length) % thumbs.length;
    const thumb = thumbs[active];
    mainImage.src = thumb.dataset.src;
    mainImage.alt = thumb.dataset.alt;
    if (count) count.textContent = String(active + 1);
    thumbs.forEach((button, i) => {
      const selected = i === active;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-pressed', String(selected));
    });
  };
  gallery.querySelectorAll('[data-case-arrow]').forEach(button => button.addEventListener('click', () => show(active + (button.dataset.caseArrow === 'next' ? 1 : -1))));
  thumbs.forEach((button, index) => button.addEventListener('click', () => show(index)));
  gallery.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      show(active + (event.key === 'ArrowRight' ? 1 : -1));
    }
  });
});

// 线路切换显示对应播放组，并沿用当前集数切换播放源。
const markCurrentEpisode = button => {
  document.querySelectorAll('.episode-select').forEach(option => {
    const active = option === button;
    option.closest('li')?.classList.toggle('current', active);
    $('.episode-current', option).hidden = !active;
    $('.episode-open', option).hidden = active;
  });
};
let dplayerInstance = null;
let hlsInstance = null;
let videoPlaybackId = 0;
const videoStage = $('#video-player');
const episodePanel = $('.episode-section');
if (videoStage && episodePanel) {
  const syncEpisodePanelHeight = () => {
    episodePanel.style.height = window.matchMedia('(min-width: 901px)').matches
      ? `${videoStage.getBoundingClientRect().height}px`
      : '';
  };
  if (window.ResizeObserver) new ResizeObserver(syncEpisodePanelHeight).observe(videoStage);
  else window.addEventListener('resize', syncEpisodePanelHeight, { passive: true });
  syncEpisodePanelHeight();
}
const playEpisode = async (button, autoplay = true, muted = false) => {
  const playbackId = ++videoPlaybackId;
  const player = $('[data-real-video]');
  if (!player) return;
  const source = new URL(button.dataset.videoSrc, document.baseURI).href;
  const poster = button.dataset.videoPoster;
  const dplayerMount = $('[data-dplayer-mount]');
  let useDPlayer = button.dataset.videoPlayer === 'dplayer';
  if (useDPlayer && (!dplayerMount || typeof window.DPlayer !== 'function')) {
    toast(t('dplayerFailed'));
    useDPlayer = false;
  }
  if (hlsInstance) {
    hlsInstance.destroy();
    hlsInstance = null;
  }
  let useHlsJs = false;

  if (useDPlayer) {
    player.pause();
    player.hidden = true;
    dplayerMount.hidden = false;
    const video = { url: source, pic: poster, type: 'auto' };
    if (dplayerInstance) dplayerInstance.switchVideo(video);
    else dplayerInstance = new window.DPlayer({
      ...(window.MIU_DPLAYER_OPTIONS || {}),
      container: dplayerMount,
      autoplay: false,
      video
    });
  } else {
    if (dplayerInstance) {
      dplayerInstance.destroy();
      dplayerInstance = null;
    }
    if (dplayerMount) dplayerMount.hidden = true;
    player.hidden = false;
    player.pause();
    const isHlsSource = /\.m3u8(?:$|[?#])/i.test(source);
    useHlsJs = isHlsSource && window.Hls && window.Hls.isSupported();
    if (useHlsJs) {
      player.removeAttribute('src');
      player.load();
      hlsInstance = new window.Hls();
      hlsInstance.on(window.Hls.Events.MANIFEST_PARSED, () => {
        if (autoplay && playbackId === videoPlaybackId) {
          player.muted = muted;
          player.play().catch(() => toast(t('autoplayFailed')));
        }
      });
      hlsInstance.on(window.Hls.Events.ERROR, (_, data) => {
        if (data.fatal) toast(t('hlsFailed'));
      });
      hlsInstance.loadSource(source);
      hlsInstance.attachMedia(player);
    } else {
      if (player.src !== source) { player.src = source; player.load(); }
      else if (player.readyState > 0) player.currentTime = 0;
    }
    player.poster = poster;
  }
  const pageTitle = $('[data-video-page-title]');
  if (pageTitle) pageTitle.textContent = button.dataset.videoTitle;
  const title = $('[data-video-title]');
  if (title) title.textContent = button.dataset.videoTitle;
  markCurrentEpisode(button);
  if (autoplay) {
    try {
      const media = useDPlayer ? dplayerInstance.video : player;
      if (media) media.muted = muted;
      if (useDPlayer) await dplayerInstance.play();
      else if (!useHlsJs) await player.play();
    } catch { toast(t('autoplayFailed')); }
  }
};
document.querySelectorAll('.episode-select').forEach(button => button.addEventListener('click', async event => {
  event.preventDefault();
  await playEpisode(button);
}));

const lineSwitcher = $('[data-video-line-switcher]');
if (lineSwitcher) {
  const toggle = $('[data-video-line-toggle]', lineSwitcher);
  const menu = $('[data-video-line-menu]', lineSwitcher);
  const currentLabel = $('[data-video-line-current]', lineSwitcher);
  const options = [...lineSwitcher.querySelectorAll('[data-video-line-option]')];
  const groups = [...document.querySelectorAll('[data-video-line-group]')];
  const setOpen = open => {
    menu.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    lineSwitcher.classList.toggle('is-open', open);
  };
  const setLine = async (lineIndex, play = true, muted = false) => {
    const oldGroup = groups.find(group => !group.hidden);
    const episodeIndex = oldGroup?.querySelector('li.current .episode-select')?.dataset.episodeIndex || '1';
    const nextGroup = groups.find(group => group.dataset.lineIndex === lineIndex);
    const nextOption = options.find(option => option.dataset.lineIndex === lineIndex);
    if (!nextGroup || !nextOption) return;
    groups.forEach(group => { group.hidden = group !== nextGroup; });
    options.forEach(option => {
      const active = option === nextOption;
      option.setAttribute('aria-pressed', String(active));
      option.classList.toggle('is-current', active);
    });
    currentLabel.textContent = nextOption.textContent.trim();
    setOpen(false);
    const episodes = [...nextGroup.querySelectorAll('.episode-select')];
    const episode = episodes.find(item => item.dataset.episodeIndex === episodeIndex) || episodes[0];
    if (!episode) return;
    if (play) await playEpisode(episode, true, muted);
    else await playEpisode(episode, false);
  };
  const initialGroup = groups.find(group => !group.hidden) || groups[0];
  if (initialGroup) setLine(initialGroup.dataset.lineIndex, true, true);
  toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
  options.forEach(option => option.addEventListener('click', () => setLine(option.dataset.lineIndex)));
  document.addEventListener('click', event => {
    if (!lineSwitcher.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
      setOpen(false);
      toggle.focus();
    }
  });
}



// Contact form validates locally and clearly states that it does not submit to a server.
$('#contact-form')?.addEventListener('submit', event => {
  event.preventDefault();
  const form = event.currentTarget;
  const fields = [...form.querySelectorAll('input, textarea')];
  let valid = true;
  fields.forEach(field => {
    const error = field.parentElement.querySelector('.field-error');
    error.textContent = '';
    field.removeAttribute('aria-invalid');
    if (!field.checkValidity()) {
      valid = false;
      field.setAttribute('aria-invalid', 'true');
      error.textContent = field.validity.valueMissing ? t('required') : field.validity.typeMismatch ? t('invalidEmail') : t('minLength').replace('{count}', field.minLength);
    }
  });
  const feedback = $('.form-feedback', form);
  if (!valid) {
    feedback.textContent = t('checkForm');
    form.querySelector('[aria-invalid="true"]')?.focus();
    return;
  }
  feedback.textContent = t('demoFeedback');
  feedback.classList.add('success');
  form.reset();
});
