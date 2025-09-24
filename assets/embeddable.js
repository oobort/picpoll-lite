(function(){
  function escapeHtml(s){
    s = String(s || '');
    return s.replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];
    });
  }
  function httpGet(url, cb){
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function(){
      if (xhr.readyState === 4){
        if (xhr.status >= 200 && xhr.status < 300){
          try { cb(null, JSON.parse(xhr.responseText)); }
          catch(e){ cb(e); }
        } else {
          if (xhr.status === 404 && window.FVG_SHORTCODE_CFG && window.FVG_SHORTCODE_CFG.ajax){
            return cb({ajaxFallback:true});
          }
          cb(new Error('HTTP ' + xhr.status));
        }
      }
    };
    xhr.send();
  }
  function httpPost(url, bodyObj, cb){
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type','application/json');
    xhr.onreadystatechange = function(){
      if (xhr.readyState === 4){
        if (xhr.status >= 200 && xhr.status < 300){
          try { cb(null, JSON.parse(xhr.responseText)); }
          catch(e){ cb(e); }
        } else {
          if (xhr.status === 404 && window.FVG_SHORTCODE_CFG && window.FVG_SHORTCODE_CFG.ajax){
            return cb({ajaxFallback:true});
          }
          cb(new Error('HTTP ' + xhr.status));
        }
      }
    };
    xhr.send(JSON.stringify(bodyObj || {}));
  }

  function init(){
    var nodes = document.querySelectorAll('.fvg-embed');
    if (!nodes.length) return;

    var OPT = {};
    try {
      var el = document.getElementById('vg-options-json');
      if (el && el.textContent) { OPT = JSON.parse(el.textContent); }
    } catch(e){ OPT = {}; }

    for (var i=0; i<nodes.length; i++){
      (function(host){
        var limit  = parseInt(host.getAttribute('data-limit') || '30', 10);
        var random = parseInt(host.getAttribute('data-random') || '1', 10) === 1;
        var brand  = host.getAttribute('data-brand-color') || '#FF7150';
        var cat    = host.getAttribute('data-category') || '';
        var showExcerpt = parseInt(host.getAttribute('data-show-excerpt') || '1', 10) === 1;
        var regionsJson = host.getAttribute('data-regions') || '{}';
        var regionMode = host.getAttribute('data-region-mode') || (OPT && OPT.region_mode) || 'none';
        var regionMap = {};
        try { regionMap = JSON.parse(regionsJson); } catch(e){ regionMap = {}; }
        var regionCodes = [];
        for (var k in regionMap) { if (Object.prototype.hasOwnProperty.call(regionMap, k)) regionCodes.push(k); }

        var apiRoot = (window.FVG_SHORTCODE_CFG && window.FVG_SHORTCODE_CFG.api) || '/wp-json/vote-game/v1/';
        var ajaxUrl = (window.FVG_SHORTCODE_CFG && window.FVG_SHORTCODE_CFG.ajax) || '';

        var wrap = document.createElement('div');
        wrap.className = 'fvg-wrap';
        var scope = host.getAttribute('data-scope') || '';
        if (scope) { wrap.className += ' ' + scope; }

        var card = document.createElement('div'); card.className = 'fvg-card';
        var stage = document.createElement('div'); stage.className = 'fvg-stage';
        card.appendChild(stage); wrap.appendChild(card);
        host.parentNode.insertBefore(wrap, host); host.parentNode.removeChild(host);

        var LABELS = (OPT && OPT.option_labels && OPT.option_labels.length) ? OPT.option_labels.slice(0) : ['Vote Option 1'];
        try { document.documentElement.style.setProperty('--fvg-accent', brand); } catch(e){}

        var IMAGES = []; var idx = 0;

        var selectedRegion = null;
        if (regionMode === 'prompt') {
          try {
            selectedRegion = localStorage.getItem('fvg_region') || null;
          } catch(e){ selectedRegion = null; }
        }
        function showRegionPromptIfNeeded(cb){
          if (regionMode !== 'prompt') return cb();
          if (selectedRegion && regionMap[selectedRegion]) return cb();
          // Build a simple chooser
          var overlay = document.createElement('div');
          overlay.style.position='fixed'; overlay.style.inset='0'; overlay.style.background='rgba(0,0,0,.5)'; overlay.style.display='flex'; overlay.style.alignItems='center'; overlay.style.justifyContent='center'; overlay.style.zIndex='999999';
          var panel = document.createElement('div');
          panel.style.background='#fff'; panel.style.padding='16px'; panel.style.borderRadius='12px'; panel.style.maxWidth='420px'; panel.style.width='90%';
          panel.innerHTML = '<h3 style="margin:0 0 10px;">Choose your region</h3><div id="fvg-chooser" style="display:grid;gap:8px;max-height:50vh;overflow:auto;"></div><div style="margin-top:12px;text-align:right;"><button id="fvg-choose-btn" class="button button-primary" disabled>Continue</button></div>';
          overlay.appendChild(panel); document.body.appendChild(overlay);
          var box = panel.querySelector('#fvg-chooser');
          var firstKey = null;
          for (var code in regionMap){ if (!Object.prototype.hasOwnProperty.call(regionMap, code)) continue; if (!firstKey) firstKey = code;
            var btn = document.createElement('button'); btn.type='button'; btn.textContent = regionMap[code] + ' (' + code + ')';
            btn.style.border='1px solid #ddd'; btn.style.padding='8px 10px'; btn.style.borderRadius='8px'; btn.style.cursor='pointer'; btn.setAttribute('data-code', code);
            btn.addEventListener('click', function(ev){ var code = ev.currentTarget.getAttribute('data-code'); selectedRegion = code; var choose = panel.querySelector('#fvg-choose-btn'); choose.disabled=false; });
            box.appendChild(btn);
          }
          var chooseBtn = panel.querySelector('#fvg-choose-btn');
          chooseBtn.addEventListener('click', function(){ try { localStorage.setItem('fvg_region', selectedRegion||''); }catch(e){} document.body.removeChild(overlay); cb(); });
        }


        function formatTotal(n){
          n = n || 0;
          if (n >= 10000) return String(Math.round(n/1000)) + 'k';
          try { return n.toLocaleString(); } catch(e){ return String(n); }
        }
        function updateProgress(){
          var txt = IMAGES.length ? '(' + Math.min(idx, IMAGES.length) + '/' + IMAGES.length + ')' : '';
          var el = wrap.querySelector('.fvg-progress');
          if (!el){ el = document.createElement('div'); el.className = 'fvg-progress'; wrap.appendChild(el); }
          el.textContent = txt;
        }
        function barsHtml(label, stats){
          var total = (stats && stats.total) || 0;
          var perc = (stats && stats.percent) || [];
          var counts = (stats && stats.counts) || [];
          var minSample = (OPT && OPT.min_sample) ? OPT.min_sample : 0;
          var out = '';
          out += '<div class="fvg-region-card">';
          out +=   '<div class="fvg-section-title">';
          out +=     '<span>How ' + escapeHtml(label) + ' Voted</span>';
          out +=     '<span class="fvg-section-sub">Total votes: ' + formatTotal(total) + '</span>';
          out +=   '</div>';
          if (total < minSample){
            out += '<div class="fvg-section-sub">Not enough votes yet.</div>';
          } else {
            out += '<div class="fvg-bars">';
            for (var i=0;i<LABELS.length;i++){
              var p = perc[i] || 0;
              var c = counts[i] || 0;
              out += '<div>';
              out +=   '<div class="fvg-bar-label"><span>' + escapeHtml(LABELS[i]) + '</span><span>' + p + '% (' + c + ')</span></div>';
              out +=   '<div class="fvg-bar"><span style="width:' + p + '%"></span></div>';
              out += '</div>';
            }
            out += '</div>';
          }
          out += '</div>';
          return out;
        }
        function renderImageCard(item){
          var titleSize = (OPT && OPT.title_size) ? OPT.title_size : 18;
          var html = '';
          html += '<div>';
          html +=   '<img class="fvg-img" src="' + item.url + '" alt="' + escapeHtml(item.title) + '">';
          html +=   '<div class="fvg-title" style="display:block;font-size:' + titleSize + 'px">' + escapeHtml(item.title) + '</div>';
          if (showExcerpt && item.excerpt){ html += '<div class="fvg-excerpt">' + escapeHtml(item.excerpt) + '</div>'; }
          html +=   '<div class="fvg-choices">';
          for (var i=0;i<LABELS.length;i++){
            html += '<button class="fvg-btn" data-choice="' + i + '">' + escapeHtml(LABELS[i]) + '</button>';
          }
          html +=   '</div>';
          html +=   '<div class="fvg-result"></div>';
          html += '</div>';
          stage.innerHTML = html;

          var btns = stage.querySelectorAll('.fvg-btn');
          for (var b=0; b<btns.length; b++){
            (function(btn){
              var choice = parseInt(btn.getAttribute('data-choice'), 10);
              btn.addEventListener('click', function(){
                var countryToSend = (regionMode==='prompt') ? (selectedRegion||'') : '';
                vote(item.id, choice, countryToSend, regionCodes, function(err, stats){
                  if (err){ alert('Could not save vote. Try again.'); return; }
                  renderResults(item, choice, stats);
                });
              });
            })(btns[b]);
          }
        }
        function renderResults(item, youChoice, payload){
          var titleSize = (OPT && OPT.title_size) ? OPT.title_size : 18;
          var html = '';
          html += '<div>';
          html +=   '<img class="fvg-img" src="' + item.url + '" alt="' + escapeHtml(item.title) + '">';
          html +=   '<div class="fvg-title" style="display:block;font-size:' + titleSize + 'px">' + escapeHtml(item.title) + '</div>';
          if (showExcerpt && item.excerpt){ html += '<div class="fvg-excerpt">' + escapeHtml(item.excerpt) + '</div>'; }
          html +=   '<div class="fvg-result">';
          html +=     '<div style="margin-bottom:8px; font-weight:600;">You voted: ' + escapeHtml(LABELS[youChoice]) + '</div>';
          if (!(OPT && OPT.hide_everyone)) {
            html += barsHtml('Everyone', (payload && payload.overall) || {percent:[], counts:[], total:0});
          }
          if (regionCodes && regionCodes.length){
            for (var r=0;r<regionCodes.length;r++){
              var code = regionCodes[r];
              var label = regionMap[code] || code;
              var rs = (payload && payload.regions && payload.regions[code]) ? payload.regions[code] : {percent:[], counts:[], total:0};
              html += barsHtml(label, rs);
            }
          }
          html +=     '<button class="fvg-next">Next (' + (idx + 1) + ' of ' + IMAGES.length + ')</button>';
          html +=   '</div>';
          html += '</div>';
          stage.innerHTML = html;
          var next = stage.querySelector('.fvg-next');
          if (next){
            next.addEventListener('click', function(){
              idx++;
              updateProgress();
              if (idx < IMAGES.length) { renderImageCard(IMAGES[idx]); try{ wrap.scrollIntoView({behavior:'smooth', block:'center'}); }catch(e){} }
              else { renderDone(); try{ wrap.scrollIntoView({behavior:'smooth', block:'center'}); }catch(e){} }
            });
          }
        }
        function renderDone(){
          stage.innerHTML = '<div style="text-align:center; padding:24px;"><h3>All done!</h3><p>You have reached the end of the items.</p></div>';
        }

        function getImages(cb){
          var url = apiRoot + 'images?limit=' + encodeURIComponent(limit) + '&random=' + (random ? '1' : '0') + '&category=' + encodeURIComponent(cat || '');
          httpGet(url, function(err, data){
            if (err && err.ajaxFallback && ajaxUrl){
              var ax = ajaxUrl + '?action=vg_images&limit=' + encodeURIComponent(limit) + '&random=' + (random ? '1' : '0') + '&category=' + encodeURIComponent(cat || '');
              httpGet(ax, cb);
              return;
            }
            cb(err, data);
          });
        }
        function vote(image_id, choice, countryCode, regionCodes, cb){
          var regions = (regionCodes || []).join(',');
          httpPost(apiRoot + 'vote', { image_id: image_id, choice: choice, country: (countryCode||''), regions: regions }, function(err, data){
            if (err && err.ajaxFallback && ajaxUrl){
              var xhr = new XMLHttpRequest();
              xhr.open('POST', ajaxUrl + '?action=vg_vote', true);
              xhr.setRequestHeader('Content-Type','application/json');
              xhr.onreadystatechange = function(){
                if (xhr.readyState === 4){
                  if (xhr.status >= 200 && xhr.status < 300){
                    try { cb(null, JSON.parse(xhr.responseText)); } catch(e){ cb(e); }
                  } else { cb(new Error('HTTP ' + xhr.status)); }
                }
              };
              xhr.send(JSON.stringify({ image_id: image_id, choice: choice, country: (countryCode||''), regions: regions }));
              return;
            }
            cb(err, data);
          });
        }

        stage.innerHTML = '<div style="padding:12px;color:#555;">Loading...</div>';
        showRegionPromptIfNeeded(function(){
        getImages(function(err, data){
          if (err){
            stage.innerHTML = '<div style="padding:16px;">Error loading items: ' + escapeHtml(String(err)) + '</div>';
            return;
          }
          IMAGES = data || [];
          updateProgress();
          if (IMAGES.length) renderImageCard(IMAGES[0]);
          else stage.innerHTML = '<div style="padding:16px;">No items found. Create Items with Featured Images under Vote Game &rarr; Items.</div>';
        });
        });
      })(nodes[i]);
    }
  }

  window.FVG_initEmbeds = function(){ try{ init(); }catch(e){ try{ console.error(e); }catch(_e){} } };
  if (document.readyState !== 'loading') { window.FVG_initEmbeds(); }
  else { document.addEventListener('DOMContentLoaded', function(){ window.FVG_initEmbeds(); }); }
})();