<?php
// Header do site público
?>
<!-- Header -->
<header class="header">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <!-- Logo -->
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-ticket-perforated me-2"></i>
                <strong><?= SITE_NAME ?></strong>
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar Content -->
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">
                            <i class="bi bi-house me-1"></i>Início
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cupons.php">
                            <i class="bi bi-ticket-perforated me-1"></i>Cupons
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="produtos.php">
                            <i class="bi bi-box me-1"></i>Produtos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="lojas.php">
                            <i class="bi bi-shop me-1"></i>Lojas
                        </a>
                    </li>
                </ul>

                <!-- Search Form -->
                <form class="d-flex me-3" method="GET" action="busca.php">
                    <div class="input-group">
                        <input type="search" class="form-control" placeholder="Buscar cupons, produtos..." 
                               name="q" aria-label="Search">
                        <button class="btn btn-light" type="submit">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>

                <!-- Admin Link -->
                <div class="d-flex">
                    <a href="admin/login.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-person me-1"></i>Área Admin
                    </a>
                </div>
            </div>
        </div>
    </nav>
</header>