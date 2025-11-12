// assets/js/upload.js
// Suporte a pré-visualização de imagem para formulários de upload via <input type="file">.
// Uso: <input type="file" class="js-upload" data-preview="#preview-img">
(function () {
function qs(sel, el) { return (el || document).querySelector(sel); }
function qsa(sel, el) { return (el || document).querySelectorAll(sel); }


function handleChange(ev) {
var input = ev.target;
var previewSel = input.getAttribute('data-preview');
var preview = previewSel ? qs(previewSel) : null;
var file = input.files && input.files[0];
if (!file || !preview) return;


var reader = new FileReader();
reader.onload = function (e) {
if (preview.tagName === 'IMG') {
preview.src = e.target.result;
} else {
preview.style.backgroundImage = 'url(' + e.target.result + ')';
}
};
reader.readAsDataURL(file);
}


function init() {
qsa('input.js-upload[type="file"]').forEach(function (el) {
el.addEventListener('change', handleChange);
});
}


if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', init);
} else {
init();
}
})();