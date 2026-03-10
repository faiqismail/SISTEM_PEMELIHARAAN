<?php

// ============================================
// FILE: inc/auth.php
// ============================================

/**
 * Universal Authentication & Authorization
 * Digunakan untuk semua role
 *
 * Cara pakai:
 * include "../inc/auth.php";
 * checkAuth('admin');               // Cek apakah user adalah admin
 * checkAuth(['admin', 'pengawas']); // Cek apakah user adalah admin ATAU pengawas
 */

// ============================================
// VALIDASI TOKEN KE DATABASE
// ============================================

if (!function_exists('validateDbSession')) {
    function validateDbSession($token) {
        global $connection;

        if (empty($token)) return null;

        $sql = "SELECT us.id_session, us.id_user, us.role, us.expires_at,
                       u.username, u.status
                FROM user_sessions us
                JOIN users u ON us.id_user = u.id_user
                WHERE us.session_token = ?
                  AND us.expires_at > NOW()
                  AND u.status = 'Aktif'
                LIMIT 1";

        $stmt = $connection->prepare($sql);
        if (!$stmt) return null;

        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows === 1 ? $result->fetch_assoc() : null;
    }
}

// ============================================
// MAIN AUTH FUNCTION
// ============================================

if (!function_exists('checkAuth')) {
    function checkAuth($allowed_roles) {
        if (!is_array($allowed_roles)) {
            $allowed_roles = [$allowed_roles];
        }

        // Multi-tab: tab_id dari URL (get_armada_tab_id dari config)
        $tab_id = function_exists('get_armada_tab_id') ? get_armada_tab_id() : null;

        $session_found = false;
        $current_role  = null;

        foreach ($allowed_roles as $role) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $sessionName = $tab_id
                ? 'ARMADA_' . strtoupper($role) . '_' . $tab_id
                : 'ARMADA_' . strtoupper($role);

            session_name($sessionName);
            session_start();

            if (!empty($_SESSION['id_login']) && $_SESSION['role'] === $role) {
                $token        = $_SESSION['db_token'] ?? null;
                $session_data = validateDbSession($token);

                if ($session_data) {
                    $session_found = true;
                    $current_role  = $role;
                    break;
                } else {
                    $_SESSION = [];
                    session_destroy();
                }
            }
        }

        if (!$session_found) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('initSession')) {
                initSession(null); // ARMADA_LOGIN_{tab_id}
            } else {
                session_name('ARMADA_LOGIN');
                session_start();
            }
            if (empty($_SESSION['id_login'] ?? null)) {
                header('Location: ' . (function_exists('url_with_tab') ? url_with_tab('../index.php?expired=1') : '../index.php?expired=1'));
                exit;
            }
            header('Location: ' . (function_exists('url_with_tab') ? url_with_tab('../index.php?unauthorized=1') : '../index.php?unauthorized=1'));
            exit;
        }

        return $current_role;
    }
}
// ============================================
// HELPER: Ambil data user yang sedang login
// ============================================

if (!function_exists('getLoginUser')) {
    function getLoginUser() {
        return [
            'id_user'  => $_SESSION['id_user']  ?? null,
            'id_login' => $_SESSION['id_login'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role'     => $_SESSION['role']     ?? null,
        ];
    }
}

// ============================================
// HELPER: Logout - hapus session PHP + token DB
// ============================================

if (!function_exists('logoutUser')) {
    function logoutUser() {
        global $connection;

        // Hapus token dari DB jika ada
        if (!empty($_SESSION['db_token'])) {
            $token = $_SESSION['db_token'];
            $sql   = "DELETE FROM user_sessions WHERE session_token = ?";
            $stmt  = $connection->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $token);
                $stmt->execute();
            }
        }

        // Hancurkan session PHP
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}