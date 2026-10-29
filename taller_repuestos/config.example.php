<?php
// Copie este archivo como config.php y ajuste su instancia de SQL Server.
return [
    'server' => 'localhost\\SQLEXPRESS',
    'database' => 'TallerRepuestosDB',
    // true: autenticación de Windows (cuenta que ejecuta PHP).
    'integrated_auth' => true,
    'username' => '',
    'password' => '',
    // Solo para desarrollo local con certificado autofirmado.
    'trust_server_certificate' => true,
];
