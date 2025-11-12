<?php
// produtos.php — grid completo com filtros
$root = __DIR__;
@require_once $root . '/includes/config.php';
@require_once $root . '/includes/functions.php';
@require_once $root . '/includes/header.php';
$q = trim($_GET['q'] ?? '');
$loja = trim($_GET['loja'] ?? '');
$ord = $_GET['ord'] ?? 'recente';
?>
<main class="container">
<header class="page-header">
<h1>Produtos</h1>
<form class="filtros" method="get" action="/produtos.php">
<input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar produto">
<input type="text" name="loja" value="<?= htmlspecialchars($loja) ?>" placeholder="Loja (nome ou slug)">
<select name="ord">
<option value="recente" <?= $ord==='recente'?'selected':'' ?>>Recentes</option>
<option value="preco_asc" <?= $ord==='preco_asc'?'selected':'' ?>>Preço ↑</option>
<option value="preco_desc" <?= $ord==='preco_desc'?'selected':'' ?>>Preço ↓</option>
</select>
<button type="submit" class="btn">Filtrar</button>
</form>
</header>


<div id="grid-produtos" data-endpoint="/ajax/produto.php"></div>
</main>


<script>
(function(){
function load(){
var cont = document.getElementById('grid-produtos');
if(!cont) return;
var params = new URLSearchParams(window.location.search);
var url = cont.getAttribute('data-endpoint') + '?' + params.toString();
fetch(url, {credentials:'same-origin'}).then(r=>r.text()).then(html=> cont.innerHTML = html).catch(()=>{
cont.innerHTML = '<div class="alert alert-error">Erro ao carregar produtos.</div>';
});
}
if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', load); } else { load(); }
})();
</script>


<?php @require_once $root . '/includes/footer.php'; ?>