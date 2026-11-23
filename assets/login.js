/* Sign-in slideshow: pictures and captions from Admin → Sign-in pictures (embedded by login.php). Reads the page only. */
(function () {
  var MS = 6000, stage = document.getElementById('lgStage'), cap = document.getElementById('lgCaption');
  if (!stage) return;
  var slides = []; try { slides = JSON.parse(document.getElementById('lgSlides').textContent || '[]') || []; } catch (e) {}
  if (!slides.length) { stage.classList.add('empty'); cap.hidden = true; return; }
  var tag = document.getElementById('lgTag'), title = document.getElementById('lgTitle'), text = document.getElementById('lgText'), dotsEl = document.getElementById('lgDots');
  var els = [], dots = [], idx = 0, timer = null, still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  slides.forEach(function (s, i) {
    var d = document.createElement('div'); d.className = 'lg-slide';
    d.style.backgroundImage = 'url("' + String(s.src).replace(/["\\\n()]/g, '') + '")';
    stage.appendChild(d); els.push(d);
    if (slides.length > 1) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'lg-dot'; b.setAttribute('aria-label', 'Picture ' + (i + 1));
      b.addEventListener('click', function () { go(i); }); dotsEl.appendChild(b); dots.push(b);
    }
  });
  if (!slides.some(function (s) { return s.tag || s.title || s.text; }) && slides.length < 2) cap.hidden = true;
  function go(i) {
    clearTimeout(timer); idx = (i + els.length) % els.length;
    els.forEach(function (e, n) { e.classList.toggle('on', n === idx); });
    dots.forEach(function (d, n) { d.setAttribute('aria-current', n === idx ? 'true' : 'false'); });
    var s = slides[idx]; tag.textContent = s.tag || ''; title.textContent = s.title || ''; text.textContent = s.text || '';
    if (els.length > 1 && !still) timer = setTimeout(function () { go(idx + 1); }, MS);
  }
  document.addEventListener('visibilitychange', function () { if (document.hidden) clearTimeout(timer); else go(idx); });
  go(0);
})();
