<?php
/**
 * config.php - Configuración del sistema AnonChat
 * 
 * Este archivo contiene todas las configuraciones ajustables del sistema.
 * Modifica estos valores según las necesidades de tu instalación.
 */

declare(strict_types=1);

return [
    // ============================================
    // Rate Limiting (Límite de intentos por IP)
    // ============================================
    'rate_limit' => [
        // Límite de intentos de login por IP
        'login_attempts' => 15,        // Número máximo de intentos
        'login_window' => 300,         // Ventana de tiempo en segundos (300 = 5 minutos)
    ],

    // ============================================
    // Bloqueo de Cuenta (Failed Login Attempts)
    // ============================================
    'account_lockout' => [
        // Intentos máximos antes de bloquear la cuenta
        'max_attempts' => 5,            // Número de intentos fallidos permitidos
        'lock_duration' => 10,         // Duración del bloqueo en minutos
    ],

    // ============================================
    // Seguridad Anti Brute-Force
    // ============================================
    'security' => [
        // Retraso en microsegundos después de un login fallido (200000 = 0.2 segundos)
        'failed_login_delay' => 200000,
    ],

    // ============================================
    // Sesión
    // ============================================
    'session' => [
        // Tiempo de vida de la sesión en segundos (1800 = 30 minutos)
        'lifetime' => 1800,
    ],
];
