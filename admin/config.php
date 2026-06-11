<?php
// -------------------------------------------------------
// Admin credentials — CHANGE THESE before going live!
//
// Preferred: set ADMIN_USER and ADMIN_PASS_HASH environment
// variables. Generate a hash with:
//   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
// -------------------------------------------------------
define('ADMIN_USER', getenv('ADMIN_USER') ?: 'admin');

// Default password is still "admin123" (hashed) — change it!
define(
    'ADMIN_PASS_HASH',
    getenv('ADMIN_PASS_HASH')
        ?: '$2y$10$DUGWyb/vFvlekWZ80VxLyeZBejsaHO7stWXUqegnzpPy.hhArqFnq' // admin123
);
