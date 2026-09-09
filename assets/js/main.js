(function(){
  'use strict';

  function showToast(msg, ms){
    ms = ms||2500;
    var t=document.getElementById('toast');
    if(!t){t=document.createElement('div');t.id='toast';document.body.appendChild(t);}
    t.textContent=msg; t.classList.add('show');
    clearTimeout(t._t); t._t=setTimeout(function(){t.classList.remove('show');},ms);
  }
  window.showToast = showToast;

  // ── Continue Browsing (localStorage only — no accounts, no server state) ──
  var CB_KEY = 'rm_recent_episodes';
  var CB_MAX = 12;
  function recordVisit(epNumber){
    try {
      var raw = localStorage.getItem(CB_KEY);
      var list = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(list)) list = [];
      list = list.filter(function(n){ return n !== epNumber; });
      list.unshift(epNumber);
      list = list.slice(0, CB_MAX);
      localStorage.setItem(CB_KEY, JSON.stringify(list));
    } catch (e) { /* private browsing / storage disabled — silently skip */ }
  }
  function recentEpisodes(excludeEp){
    try {
      var raw = localStorage.getItem(CB_KEY);
      var list = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(list)) return [];
      return list.filter(function(n){ return n !== excludeEp; });
    } catch (e) { return []; }
  }
  window.rmRecordVisit = recordVisit;
  window.rmRecentEpisodes = recentEpisodes;

  // Renders the "Continue Browsing" rail into #<containerId> if the
  // visitor has any recently-viewed episodes recorded in this browser.
  // No account, no server-side tracking — a JSON lookup of already-public
  // episode fields is the only network request involved.
  function initContinueBrowsing(containerId, basePath){
    var host = document.getElementById(containerId);
    if (!host) return;
    var recent = recentEpisodes(null);
    if (!recent.length) { host.hidden = true; return; }
    fetch((basePath || '') + '/episodes_lookup.php?eps=' + recent.slice(0, 8).join(','))
      .then(function(r){ return r.ok ? r.json() : []; })
      .then(function(items){
        if (!Array.isArray(items) || !items.length) { host.hidden = true; return; }
        var rail = host.querySelector('.rail');
        if (!rail) return;
        rail.innerHTML = items.map(function(ep){
          var n = String(ep.episode_number).padStart(3, '0');
          var thumb = ep.thumbnail
            ? '<img src="' + ep.thumbnail + '" alt="Episode #' + n + '" loading="lazy">'
            : '<div class="thumb-ph">R</div>';
          return '<div class="card ep-card" data-href="' + ep.url + '" tabindex="0">'
            + '<div class="ep-thumb">' + thumb + '<span class="ep-num-badge">EP' + n + '</span></div>'
            + '<div class="ep-body"><span class="ep-num">Episode #' + n + '</span>'
            + '<div class="ep-title"></div><div class="ep-date">' + (ep.air_date || '') + '</div></div></div>';
        }).join('');
        // Titles are set via textContent, not string-concatenated HTML,
        // so nothing in a title can inject markup even though it already
        // passed through this same DB on the way in.
        rail.querySelectorAll('.ep-title').forEach(function(el, i){ el.textContent = items[i].title; });
        rail.querySelectorAll('.ep-card[data-href]').forEach(function(c){
          c.addEventListener('click', function(){ window.location.href = c.dataset.href; });
          c.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.location.href = c.dataset.href; } });
        });
        host.hidden = false;
      })
      .catch(function(){ host.hidden = true; });
  }
  window.rmInitContinueBrowsing = initContinueBrowsing;

  document.addEventListener('DOMContentLoaded', function(){

    // Mobile navigation drawer
    (function(){
      var toggle = document.getElementById('navToggle');
      var drawer = document.getElementById('mobileNav');
      var backdrop = document.getElementById('mobileNavBackdrop');
      if (!toggle || !drawer || !backdrop) return;
      function open(){
        drawer.hidden = false; backdrop.hidden = false;
        requestAnimationFrame(function(){ drawer.classList.add('open'); backdrop.classList.add('open'); });
        toggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('nav-open');
      }
      function close(){
        drawer.classList.remove('open'); backdrop.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('nav-open');
        setTimeout(function(){ drawer.hidden = true; backdrop.hidden = true; }, 250);
      }
      toggle.addEventListener('click', function(){
        toggle.getAttribute('aria-expanded') === 'true' ? close() : open();
      });
      backdrop.addEventListener('click', close);
      drawer.querySelectorAll('a').forEach(function(a){ a.addEventListener('click', close); });
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
    })();

    // Episode card click
    document.querySelectorAll('.ep-card[data-href]').forEach(function(c){
      c.style.cursor='pointer';
      c.addEventListener('click', function(){ window.location.href=c.dataset.href; });
      c.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); window.location.href=c.dataset.href; } });
    });

    // Image error fallback
    document.querySelectorAll('.ep-thumb img').forEach(function(img){
      img.addEventListener('error', function(){
        var w=img.closest('.ep-thumb,.ep-detail-thumb');
        if(w){ img.style.display='none'; var ph=document.createElement('div'); ph.className='thumb-ph'; ph.textContent='R'; w.appendChild(ph); }
      });
    });

    // Lazy load
    if('IntersectionObserver' in window){
      var io=new IntersectionObserver(function(entries){
        entries.forEach(function(e){ if(e.isIntersecting){ var i=e.target; i.src=i.dataset.src; i.removeAttribute('data-src'); io.unobserve(i); } });
      },{rootMargin:'150px'});
      document.querySelectorAll('img[data-src]').forEach(function(i){ io.observe(i); });
    }

    // Auto-submit filter selects
    document.querySelectorAll('.filter-bar select').forEach(function(s){
      s.addEventListener('change', function(){ var f=s.closest('form'); if(f) f.submit(); });
    });

    // Smooth scroll
    document.querySelectorAll('a[href^="#"]').forEach(function(a){
      a.addEventListener('click', function(e){
        var t=document.querySelector(a.getAttribute('href'));
        if(t){ e.preventDefault(); t.scrollIntoView({behavior:'smooth',block:'start'}); }
      });
    });

  });
})();
