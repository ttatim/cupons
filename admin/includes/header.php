<?php
// Adicionar CSRF Token em todos os formulários
$csrf_token = Security::generateCSRFToken();
?>
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">