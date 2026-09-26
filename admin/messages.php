<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/../config/db.php';

$pageTitle  = 'Messages';
$activePage = 'messages';

$message     = '';
$messageType = '';

/* Make sure the table exists (in case the DB predates the contact form) */
$conn->query("
    CREATE TABLE IF NOT EXISTS contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        subject VARCHAR(200) DEFAULT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

/* Delete a message */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $mid = (int)($_POST['id'] ?? 0);
    if ($mid > 0) {
        $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
        $stmt->bind_param('i', $mid);
        $ok = $stmt->execute();
        $stmt->close();
        $message     = $ok ? "Message #$mid deleted." : 'Could not delete message.';
        $messageType = $ok ? 'success' : 'danger';
    }
}

$messages = $conn->query("
    SELECT id, name, email, subject, message, created_at
    FROM contact_messages
    ORDER BY created_at DESC, id DESC
");
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="admin-section">
  <div class="admin-section-header">
    <h2>Contact Messages (<?= $messages ? $messages->num_rows : 0 ?>)</h2>
    <span style="font-size:0.8rem; color:#888;">Messages submitted through the site's Contact page</span>
  </div>

  <?php if ($messages && $messages->num_rows > 0): ?>
    <div style="display:flex; flex-direction:column; gap:14px;">
      <?php while ($m = $messages->fetch_assoc()): ?>
        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; background:#fff;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
            <div>
              <div style="font-weight:700; color:#222;">
                <?= htmlspecialchars($m['name']) ?>
                <a href="mailto:<?= htmlspecialchars($m['email']) ?>" style="font-weight:500; color:#2563eb; font-size:0.85rem; margin-left:6px;">
                  &lt;<?= htmlspecialchars($m['email']) ?>&gt;
                </a>
              </div>
              <?php if (!empty($m['subject'])): ?>
                <div style="font-size:0.9rem; color:#555; margin-top:2px;">
                  <strong>Subject:</strong> <?= htmlspecialchars($m['subject']) ?>
                </div>
              <?php endif; ?>
            </div>
            <div style="text-align:right; white-space:nowrap;">
              <div style="font-size:0.8rem; color:#888;"><?= date('M j, Y · g:i A', strtotime($m['created_at'])) ?></div>
              <div style="margin-top:8px; display:flex; gap:8px; justify-content:flex-end;">
                <a href="mailto:<?= htmlspecialchars($m['email']) ?>?subject=Re:%20<?= rawurlencode($m['subject'] ?? 'Your message to Alke') ?>"
                   class="btn btn-sm btn-outline">Reply</a>
                <form method="POST" onsubmit="return confirm('Delete this message?');" style="margin:0;">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:#dc3545; color:#fff;">Delete</button>
                </form>
              </div>
            </div>
          </div>
          <div style="margin-top:12px; padding-top:12px; border-top:1px solid #f0f0f0; color:#333; font-size:0.92rem; line-height:1.6; white-space:pre-wrap;"><?= htmlspecialchars($m['message']) ?></div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php else: ?>
    <p class="empty-state">No messages yet.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
