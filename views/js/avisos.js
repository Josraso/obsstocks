/**
 * obsstocks - pestaña «Avisos»: buscador instantáneo y edición a mano del stock mínimo
 * y de la cantidad a pedir. Los datos vienen en la página (window.obsAvisos); cada cambio
 * se guarda al momento por AJAX.
 * Fila: [referencia, nombre, stock, mínimo|null, a pedir|null, activo(1/0), sinProducto(1/0)]
 */
(function () {
  'use strict';

  var cfg = window.obsAvisos;
  var root = document.getElementById('obs_avisos');
  if (!cfg || !root) {
    return;
  }
  var t = cfg.t;
  var rows = cfg.rows;
  var STEP = 100;
  var state = { q: '', filter: 'alert', limit: STEP };

  function norm(s) {
    s = String(s == null ? '' : s).toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var index = rows.map(function (r) { return norm(r[0] + ' ' + r[1]); });

  function hasAlert(r) { return r[3] !== null; }
  function isBelow(r) { return r[3] !== null && r[3] > 0 && r[5] === 1 && r[2] !== null && r[2] < r[3]; }

  var search = root.querySelector('.obs-av-search');
  var tbody = root.querySelector('tbody');
  var info = root.querySelector('.obs-av-info');
  var more = root.querySelector('.obs-av-more');
  var filters = root.querySelectorAll('[data-obs-filter]');

  function matches(i) {
    var r = rows[i];
    if (state.filter === 'alert' && !(hasAlert(r) && r[3] > 0)) { return false; }
    if (state.filter === 'below' && !isBelow(r)) { return false; }
    if (state.filter === 'orphan' && r[6] !== 1) { return false; }
    if (state.q === '') { return true; }
    var words = state.q.split(/\s+/);
    for (var w = 0; w < words.length; w += 1) {
      if (words[w] !== '' && index[i].indexOf(words[w]) === -1) { return false; }
    }
    return true;
  }

  function counts() {
    var c = { all: rows.length, alert: 0, below: 0, orphan: 0 };
    var seen = {};
    for (var i = 0; i < rows.length; i += 1) {
      var r = rows[i];
      if (hasAlert(r) && r[3] > 0) { c.alert += 1; }
      if (isBelow(r)) { c.below += 1; }
      if (r[6] === 1) { c.orphan += 1; }
      seen[r[0]] = 1;
    }
    for (var f = 0; f < filters.length; f += 1) {
      var k = filters[f].getAttribute('data-obs-filter');
      var badge = filters[f].querySelector('.badge');
      if (badge) { badge.textContent = c[k]; }
      filters[f].className = 'btn ' + (k === state.filter ? 'btn-primary' : 'btn-default');
      if (k === 'orphan') { filters[f].style.display = c.orphan ? '' : 'none'; }
    }
  }

  function rowHtml(i) {
    var r = rows[i];
    var noRef = r[0] === '';
    var dis = noRef ? ' disabled' : '';
    var name = esc(r[1]);
    if (r[6] === 1) {
      name = '<em class="text-muted">' + esc(t.orphan) + '</em>';
    } else if (r[5] !== 1) {
      name += ' <span class="label label-default">' + esc(t.inactive) + '</span>';
    }
    return '<tr data-i="' + i + '"' + (isBelow(r) ? ' class="obs-below"' : '') + '>'
      + '<td style="white-space:nowrap">' + (noRef ? '<em class="text-muted">' + esc(t.noref) + '</em>' : '<code>' + esc(r[0]) + '</code>') + '</td>'
      + '<td>' + name + '</td>'
      + '<td class="text-center">' + (r[2] === null ? '—' : '<span class="badge' + (isBelow(r) ? ' badge-danger' : '') + '">' + r[2] + '</span>') + '</td>'
      + '<td class="text-center"><input type="number" min="0" step="1" class="form-control obs-av-num" data-f="min" value="' + (r[3] === null ? '' : r[3]) + '" placeholder="0"' + dis + '></td>'
      + '<td class="text-center"><input type="number" min="0" step="1" class="form-control obs-av-num" data-f="qty" value="' + (r[4] === null ? '' : r[4]) + '" placeholder="0"' + dis + '></td>'
      + '<td style="white-space:nowrap"><button type="button" class="btn btn-default btn-sm obs-av-del"' + (hasAlert(r) ? '' : ' style="visibility:hidden"') + ' title="' + esc(t.removeTitle) + '"><i class="icon-trash"></i> ' + esc(t.remove) + '</button>'
      + ' <span class="obs-av-state text-muted"></span></td>'
      + '</tr>';
  }

  function render() {
    var out = [];
    var total = 0;
    for (var i = 0; i < rows.length; i += 1) {
      if (matches(i)) {
        total += 1;
        if (out.length < state.limit) { out.push(rowHtml(i)); }
      }
    }
    tbody.innerHTML = out.length ? out.join('') : '<tr><td colspan="6" class="text-center text-muted" style="padding:25px">' + esc(t.none) + '</td></tr>';
    info.textContent = t.showing.replace('%1', out.length).replace('%2', total);
    more.style.display = total > out.length ? '' : 'none';
    counts();
  }

  function setState(tr, text, cls) {
    var el = tr.querySelector('.obs-av-state');
    el.className = 'obs-av-state ' + (cls || 'text-muted');
    el.textContent = text;
  }

  function post(data, done) {
    var body = [];
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k)) {
        body.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
      }
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', cfg.url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) { return; }
      var res = null;
      try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
      done(res && typeof res === 'object' ? res : { ok: false, error: t.failed });
    };
    xhr.send(body.join('&'));
  }

  /* Los avisos van por referencia: el cambio se refleja en todas las filas con la misma */
  function apply(ref, min, qty) {
    for (var i = 0; i < rows.length; i += 1) {
      if (rows[i][0] === ref) {
        rows[i][3] = min;
        rows[i][4] = qty;
      }
    }
  }

  function refreshRow(tr, text, cls) {
    var i = parseInt(tr.getAttribute('data-i'), 10);
    var r = rows[i];
    var same = tbody.querySelectorAll('tr[data-i]');
    for (var k = 0; k < same.length; k += 1) {
      var j = parseInt(same[k].getAttribute('data-i'), 10);
      if (rows[j][0] !== r[0]) { continue; }
      var x = rows[j];
      same[k].className = isBelow(x) ? 'obs-below' : '';
      var ins = same[k].querySelectorAll('input.obs-av-num');
      if (document.activeElement !== ins[0]) { ins[0].value = x[3] === null ? '' : x[3]; }
      if (document.activeElement !== ins[1]) { ins[1].value = x[4] === null ? '' : x[4]; }
      ins[0].classList.remove('obs-av-error');
      ins[1].classList.remove('obs-av-error');
      same[k].querySelector('.obs-av-del').style.visibility = hasAlert(x) ? '' : 'hidden';
      var badge = same[k].querySelector('td:nth-child(3) .badge');
      if (badge) { badge.className = 'badge' + (isBelow(x) ? ' badge-danger' : ''); }
    }
    setState(tr, text, cls);
    counts();
  }

  function save(tr) {
    var i = parseInt(tr.getAttribute('data-i'), 10);
    var r = rows[i];
    var ins = tr.querySelectorAll('input.obs-av-num');
    var vals = [];
    for (var k = 0; k < 2; k += 1) {
      var raw = ins[k].value.trim();
      var n = raw === '' ? 0 : Number(raw);
      if (!isFinite(n) || n < 0 || Math.floor(n) !== n) {
        ins[k].classList.add('obs-av-error');
        setState(tr, t.invalid, 'text-danger');
        return;
      }
      ins[k].classList.remove('obs-av-error');
      vals.push(n);
    }
    var curMin = r[3] === null ? 0 : r[3];
    var curQty = r[4] === null ? 0 : r[4];
    if (vals[0] === curMin && vals[1] === curQty) {
      return;
    }
    setState(tr, t.saving);
    post({ obs_ajax: 'save', reference: r[0], min_stock: vals[0], qty: vals[1] }, function (res) {
      if (!res.ok) {
        setState(tr, res.error || t.failed, 'text-danger');
        return;
      }
      apply(r[0], res.exists ? res.min_stock : null, res.exists ? res.qty : null);
      refreshRow(tr, t.saved, 'text-success');
    });
  }

  function remove(tr) {
    var i = parseInt(tr.getAttribute('data-i'), 10);
    var r = rows[i];
    if (!window.confirm(t.confirmRemove.replace('%1', r[0]))) { return; }
    setState(tr, t.saving);
    post({ obs_ajax: 'delete', reference: r[0] }, function (res) {
      if (!res.ok) {
        setState(tr, res.error || t.failed, 'text-danger');
        return;
      }
      apply(r[0], null, null);
      if (r[6] === 1) {
        // Aviso sin producto: al quitarlo ya no hay nada que mostrar
        rows.splice(i, 1);
        index.splice(i, 1);
        render();
        return;
      }
      refreshRow(tr, t.removed, 'text-success');
    });
  }

  var timer = null;
  search.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      state.q = norm(search.value).trim();
      state.limit = STEP;
      render();
    }, 80);
  });
  search.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); }
  });
  for (var f = 0; f < filters.length; f += 1) {
    filters[f].addEventListener('click', function () {
      state.filter = this.getAttribute('data-obs-filter');
      state.limit = STEP;
      render();
    });
  }
  more.querySelector('button').addEventListener('click', function () {
    state.limit += STEP * 2;
    render();
  });
  tbody.addEventListener('change', function (e) {
    if (e.target.classList.contains('obs-av-num')) {
      save(e.target.closest('tr'));
    }
  });
  tbody.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.classList.contains('obs-av-num')) {
      e.preventDefault();
      e.target.blur();
    }
  });
  tbody.addEventListener('click', function (e) {
    var btn = e.target.closest('.obs-av-del');
    if (btn) { remove(btn.closest('tr')); }
  });

  render();
  search.focus();
})();
