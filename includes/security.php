<?php
class Security {
    
    // Prevenir SQL Injection
    public static function sanitizeSQL($pdo, $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitizeSQL($pdo, $value);
            }
            return $data;
        }
        
        return $pdo->quote(trim($data));
    }
    
    // Prevenir XSS
    public static function sanitizeXSS($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitizeXSS($value);
            }
            return $data;
        }
        
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    // Validar email
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    // Validar URL
    public static function validateURL($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    // Gerar CSRF Token
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    // Validar CSRF Token
    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    // Rate Limiting
    public static function checkRateLimit($action, $limit = 10, $timeframe = 60) {
        $key = "rate_limit_{$action}_" . $_SERVER['REMOTE_ADDR'];
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'time' => time()
            ];
            return true;
        }
        
        $data = $_SESSION[$key];
        
        if (time() - $data['time'] > $timeframe) {
            $_SESSION[$key] = [
                'count' => 1,
                'time' => time()
            ];
            return true;
        }
        
        if ($data['count'] >= $limit) {
            return false;
        }
        
        $_SESSION[$key]['count']++;
        return true;
    }
    
    // Log de segurança
    public static function logSecurityEvent($event, $details = []) {
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'event' => $event,
            'details' => $details
        ];
        
        file_put_contents(
            '../logs/security.log', 
            json_encode($log) . PHP_EOL, 
            FILE_APPEND | LOCK_EX
        );
    }
    
    // Headers de segurança
    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net; img-src \'self\' data: https:;');
    }
}

// Configuração do .htaccess para segurança
/*
# .htaccess
RewriteEngine On

# Prevenir acesso a arquivos sensíveis
<Files ~ "\.(env|log|sql)$">
    Deny from all
</Files>

<Files "config.php">
    Deny from all
</Files>

# Prevenir hotlinking
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?seudominio\.com [NC]
RewriteRule \.(jpg|jpeg|png|gif)$ - [F,NC]

# Forçar HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Prevenir ataques comuns
RewriteCond %{QUERY_STRING} (<|%3C).*script.*(>|%3E) [NC,OR]
RewriteCond %{QUERY_STRING} base64_encode.*\(.*\) [OR]
RewriteCond %{QUERY_STRING} GLOBALS(=|\[|\%[0-9A-Z]{0,2}) [OR]
RewriteCond %{QUERY_STRING} _REQUEST(=|\[|\%[0-9A-Z]{0,2})
RewriteRule ^(.*)$ index.php [F,L]
*/

// Configuração atualizada do config.php com segurança
/*
// Adicionar no config.php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Inicializar segurança
Security::setSecurityHeaders();

// Validar todas as entradas
$_GET = Security::sanitizeXSS($_GET);
$_POST = Security::sanitizeXSS($_POST);
$_REQUEST = Security::sanitizeXSS($_REQUEST);
*/
?>