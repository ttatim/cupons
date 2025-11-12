<?php
class ApiAfiliados {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // API do Mercado Livre
    public function mercadolivre($method, $params = []) {
        $api_key = 'SUA_API_KEY';
        $base_url = 'https://api.mercadolibre.com';
        
        switch ($method) {
            case 'get_product':
                $url = $base_url . "/items/{$params['product_id']}";
                break;
            case 'search_products':
                $url = $base_url . "/sites/MLB/search?q=" . urlencode($params['query']);
                break;
            case 'get_commission':
                $url = $base_url . "/users/{$params['user_id']}/commission_rates";
                break;
            default:
                return null;
        }
        
        $response = $this->makeRequest($url, [
            'Authorization: Bearer ' . $api_key
        ]);
        
        return json_decode($response, true);
    }
    
    // API da Shopee
    public function shopee($method, $params = []) {
        $partner_id = 'SEU_PARTNER_ID';
        $partner_key = 'SUA_PARTNER_KEY';
        $base_url = 'https://partner.shopeemobile.com/api/v2';
        
        $timestamp = time();
        $sign = hash_hmac('sha256', $partner_id . $method . $timestamp, $partner_key);
        
        $url = $base_url . '/' . $method;
        $post_data = array_merge($params, [
            'partner_id' => $partner_id,
            'timestamp' => $timestamp,
            'sign' => $sign
        ]);
        
        $response = $this->makeRequest($url, [], $post_data);
        return json_decode($response, true);
    }
    
    // API do AliExpress
    public function aliexpress($method, $params = []) {
        $app_key = 'SUA_APP_KEY';
        $app_secret = 'SEU_APP_SECRET';
        $base_url = 'https://api.aliexpress.com';
        
        // Implementar lógica específica do AliExpress
        return $this->makeGenericAffiliateRequest($base_url, $method, $params);
    }
    
    // API genérica para outras plataformas
    private function makeGenericAffiliateRequest($base_url, $method, $params) {
        $url = $base_url . '/' . $method;
        return $this->makeRequest($url, [], $params);
    }
    
    // Função base para requests HTTP
    private function makeRequest($url, $headers = [], $post_data = null) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        if ($post_data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            error_log("API Error: HTTP $http_code - $url");
            return null;
        }
        
        return $response;
    }
    
    // Sincronizar comissões
    public function sincronizarComissoes() {
        $comissoes = [];
        
        // Buscar transações recentes de todas as APIs
        try {
            // Mercado Livre
            $ml_comissoes = $this->mercadolivre('get_commission', [
                'user_id' => 'SEU_USER_ID'
            ]);
            if ($ml_comissoes) {
                $comissoes['mercadolivre'] = $this->processarComissoesML($ml_comissoes);
            }
        } catch (Exception $e) {
            error_log("Erro ML: " . $e->getMessage());
        }
        
        // Shopee
        try {
            $shopee_comissoes = $this->shopee('payment/get_balance', []);
            if ($shopee_comissoes) {
                $comissoes['shopee'] = $this->processarComissoesShopee($shopee_comissoes);
            }
        } catch (Exception $e) {
            error_log("Erro Shopee: " . $e->getMessage());
        }
        
        // Salvar no banco de dados
        $this->salvarComissoes($comissoes);
        
        return $comissoes;
    }
    
    private function processarComissoesML($data) {
        // Processar dados específicos do Mercado Livre
        return [
            'total' => $data['total_commission'] ?? 0,
            'pendente' => $data['pending_commission'] ?? 0,
            'disponivel' => $data['available_commission'] ?? 0
        ];
    }
    
    private function processarComissoesShopee($data) {
        // Processar dados específicos da Shopee
        return [
            'total' => $data['balance'] ?? 0,
            'pendente' => $data['pending_balance'] ?? 0,
            'disponivel' => $data['available_balance'] ?? 0
        ];
    }
    
    private function salvarComissoes($comissoes) {
        foreach ($comissoes as $plataforma => $dados) {
            $stmt = $this->pdo->prepare("
                INSERT INTO comissoes (plataforma, total, pendente, disponivel, data_sincronizacao) 
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                total = VALUES(total), 
                pendente = VALUES(pendente), 
                disponivel = VALUES(disponivel),
                data_sincronizacao = NOW()
            ");
            
            $stmt->execute([
                $plataforma,
                $dados['total'],
                $dados['pendente'],
                $dados['disponivel']
            ]);
        }
    }
}

// Tabela para comissões
/*
CREATE TABLE comissoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plataforma VARCHAR(50) NOT NULL,
    total DECIMAL(10,2) DEFAULT 0,
    pendente DECIMAL(10,2) DEFAULT 0,
    disponivel DECIMAL(10,2) DEFAULT 0,
    data_sincronizacao DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (plataforma)
);
*/
?>