<?php
if (!isset($pageTitle)) $pageTitle = 'Admin';
if (!isset($activePage)) $activePage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — Alke Admin</title>
  <link rel="stylesheet" href="/alke/admin/css/admin.css?v=4">
</head>
<body>
<div class="admin-wrap">

  <aside class="admin-sidebar">
    <a href="/alke/admin/index" class="sidebar-brand">
      Alke Clothes
      <small>Admin Panel</small>
    </a>

    <nav class="sidebar-nav">
      <a href="/alke/admin/index" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">📊</span>
        <span>Dashboard</span>
      </a>
      <a href="/alke/admin/products" class="<?= $activePage === 'products' ? 'active' : '' ?>">
        <span class="nav-icon">👕</span>
        <span>Products</span>
      </a>
      <a href="/alke/admin/orders" class="<?= $activePage === 'orders' ? 'active' : '' ?>">
        <span class="nav-icon">📦</span>
        <span>Orders</span>
      </a>
      <a href="/alke/admin/users" class="<?= $activePage === 'users' ? 'active' : '' ?>">
        <span class="nav-icon">👤</span>
        <span>Users</span>
      </a>
      <a href="/alke/admin/messages" class="<?= $activePage === 'messages' ? 'active' : '' ?>">
        <span class="nav-icon">✉️</span>
        <span>Messages</span>
      </a>

      <div class="sidebar-divider"></div>

      <div class="nav-logout">
        <a href="/alke/admin/logout">
          <span class="nav-icon">🚪</span>
          <span>Logout</span>
        </a>
      </div>
    </nav>
  </aside>

  <main class="admin-main">
    <div class="admin-topbar">
      <h1><?= htmlspecialchars($pageTitle) ?></h1>
      <div class="topbar-user">Logged in as <strong>admin</strong></div>
    </div>
    <div class="admin-content">
