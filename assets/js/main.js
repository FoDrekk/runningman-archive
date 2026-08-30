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

  document.addEventListener('DOMContentLoaded', function(){

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
