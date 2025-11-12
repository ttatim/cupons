<!-- Footer -->
<footer class="footer bg-dark text-light py-5">
    <div class="container">
        <div class="row">
            <!-- Logo e Descrição -->
            <div class="col-lg-4 mb-4">
                <h5 class="d-flex align-items-center mb-3">
                    <i class="bi bi-ticket-perforated me-2"></i>
                    <strong><?= SITE_NAME ?></strong>
                </h5>
                <p class="text-muted">
                    Encontre os melhores cupons de desconto e ofertas exclusivas. 
                    Economize em todas as suas compras online!
                </p>
                <div class="social-links">
                    <a href="#" class="text-muted me-3"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="text-muted me-3"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="text-muted me-3"><i class="bi bi-twitter"></i></a>
                    <a href="#" class="text-muted"><i class="bi bi-youtube"></i></a>
                </div>
            </div>

            <!-- Links Rápidos -->
            <div class="col-lg-2 col-md-4 mb-4">
                <h6 class="mb-3">Cupons</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="cupons.php" class="text-muted text-decoration-none">Todos os Cupons</a></li>
                    <li class="mb-2"><a href="cupons.php?categoria=eletronicos" class="text-muted text-decoration-none">Eletrônicos</a></li>
                    <li class="mb-2"><a href="cupons.php?categoria=moda" class="text-muted text-decoration-none">Moda</a></li>
                    <li class="mb-2"><a href="cupons.php?categoria=casa" class="text-muted text-decoration-none">Casa & Decoração</a></li>
                </ul>
            </div>

            <!-- Lojas -->
            <div class="col-lg-2 col-md-4 mb-4">
                <h6 class="mb-3">Lojas</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="lojas.php" class="text-muted text-decoration-none">Todas as Lojas</a></li>
                    <li class="mb-2"><a href="loja.php?slug=magalu" class="text-muted text-decoration-none">Magazine Luiza</a></li>
                    <li class="mb-2"><a href="loja.php?slug=americanas" class="text-muted text-decoration-none">Americanas</a></li>
                    <li class="mb-2"><a href="loja.php?slug=mercado-livre" class="text-muted text-decoration-none">Mercado Livre</a></li>
                </ul>
            </div>

            <!-- Suporte -->
            <div class="col-lg-2 col-md-4 mb-4">
                <h6 class="mb-3">Suporte</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="sobre.php" class="text-muted text-decoration-none">Sobre Nós</a></li>
                    <li class="mb-2"><a href="contato.php" class="text-muted text-decoration-none">Contato</a></li>
                    <li class="mb-2"><a href="privacidade.php" class="text-muted text-decoration-none">Privacidade</a></li>
                    <li class="mb-2"><a href="termos.php" class="text-muted text-decoration-none">Termos de Uso</a></li>
                </ul>
            </div>

            <!-- Newsletter -->
            <div class="col-lg-2 mb-4">
                <h6 class="mb-3">Newsletter</h6>
                <form>
                    <div class="mb-3">
                        <input type="email" class="form-control form-control-sm" placeholder="Seu email" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-send me-1"></i>Assinar
                    </button>
                </form>
            </div>
        </div>

        <hr class="my-4">

        <div class="row align-items-center">
            <div class="col-md-6">
                <p class="mb-0 text-muted">
                    &copy; <?= date('Y') ?> <?= SITE_NAME ?>. Todos os direitos reservados.
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="mb-0 text-muted">
                    Desenvolvido com <i class="bi bi-heart-fill text-danger"></i> para você economizar
                </p>
            </div>
        </div>
    </div>
</footer>