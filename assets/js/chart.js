// assets/js/chart.js
// Carrega widgets/estatísticas em HTML vindos de ajax/estatisticas.php e injeta no container.
// Uso: <div id="estatisticas" data-range="7d"></div>
(function () {
function qs(sel, el) { return (el || document).querySelector(sel); }
function qsa(sel, el) { return (el || document).querySelectorAll(sel); }


function loadStats(container) {
var range = container.getAttribute('data-range') || '7d';
var url = '/ajax/estatisticas.php?range=' + encodeURIComponent(range);
fetch(url, { credentials: 'same-origin' })
.then(function (r) { return r.text(); })
.then(function (html) {
container.innerHTML = html;
})
.catch(function (err) {
container.innerHTML = '<div class="alert alert-error">Erro ao carregar estatísticas.</div>';
if (window.console) console.error(err);
});
}


function init() {
qsa('#estatisticas').forEach(loadStats);
}


if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', init);
} else {
init();
}
})();