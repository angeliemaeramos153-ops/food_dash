<?php
session_start();
require_once 'db_connect.php';

$filter_email = trim($_GET['email'] ?? '');
if (empty($filter_email) && isset($_SESSION['user']['email'])) {
    $filter_email = $_SESSION['user']['email'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gmail - FoodDash Webmail Simulator</title>
  <link rel="icon" type="image/png" href="https://ssl.gstatic.com/ui/v1/icons/mail/rfr/gmail.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Google+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --gm-red: #ea4335;
      --gm-blue: #1a73e8;
      --gm-green: #34a853;
      --gm-yellow: #fbbc04;
      --gm-bg: #f6f8fc;
      --gm-sidebar-active: #d3e3fd;
      --gm-text-main: #202124;
      --gm-text-sub: #5f6368;
      --gm-border: #e0e3e7;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Roboto', 'Google Sans', Arial, sans-serif; }
    body {
      background: var(--gm-bg);
      color: var(--gm-text-main);
      height: 100vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    /* TOP HEADER */
    .gm-header {
      height: 64px;
      background: #ffffff;
      border-bottom: 1px solid var(--gm-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      gap: 16px;
      z-index: 10;
    }
    .gm-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 220px;
    }
    .gm-logo-img {
      width: 32px;
      height: 32px;
      object-fit: contain;
    }
    .gm-brand-title {
      font-family: 'Google Sans', sans-serif;
      font-size: 20px;
      font-weight: 500;
      color: var(--gm-text-sub);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .gm-brand-badge {
      font-size: 10px;
      font-weight: 700;
      background: #e8f0fe;
      color: var(--gm-blue);
      padding: 2px 6px;
      border-radius: 4px;
      border: 1px solid #d2e3fc;
      letter-spacing: 0.5px;
    }

    /* SEARCH BAR */
    .gm-search-wrapper {
      flex: 1;
      max-width: 720px;
      position: relative;
    }
    .gm-search-box {
      width: 100%;
      height: 44px;
      background: #eaf1fb;
      border-radius: 24px;
      display: flex;
      align-items: center;
      padding: 0 16px;
      border: 1px solid transparent;
      transition: background 0.2s, box-shadow 0.2s;
    }
    .gm-search-box:focus-within {
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
      border-color: transparent;
    }
    .gm-search-icon {
      color: var(--gm-text-sub);
      font-size: 16px;
      margin-right: 12px;
    }
    .gm-search-input {
      border: none;
      background: transparent;
      outline: none;
      font-size: 15px;
      width: 100%;
      color: var(--gm-text-main);
    }

    /* USER AVATAR & RECIPIENT SELECT */
    .gm-user-area {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .gm-account-chip {
      background: #f1f3f4;
      padding: 6px 12px;
      border-radius: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: var(--gm-text-main);
      border: 1px solid #dadce0;
    }
    .gm-email-select {
      border: none;
      background: transparent;
      font-size: 13px;
      font-weight: 500;
      color: var(--gm-text-main);
      outline: none;
      cursor: pointer;
    }
    .gm-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #8f1d2f;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }

    /* MAIN CONTAINER */
    .gm-main-layout {
      flex: 1;
      display: flex;
      overflow: hidden;
    }

    /* SIDEBAR */
    .gm-sidebar {
      width: 250px;
      background: var(--gm-bg);
      padding: 16px 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      flex-shrink: 0;
      border-right: 1px solid var(--gm-border);
    }
    .gm-compose-btn {
      height: 52px;
      padding: 0 24px;
      background: #c2e7ff;
      color: #001d35;
      border: none;
      border-radius: 16px;
      display: inline-flex;
      align-items: center;
      gap: 12px;
      font-family: 'Google Sans', sans-serif;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      box-shadow: 0 1px 2px rgba(0,0,0,0.1);
      transition: all 0.2s;
      width: fit-content;
      margin-bottom: 12px;
    }
    .gm-compose-btn:hover {
      box-shadow: 0 4px 8px 3px rgba(0,0,0,0.15);
      background: #b3defa;
    }
    .gm-nav-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 36px;
      padding: 0 16px;
      border-radius: 18px;
      color: var(--gm-text-main);
      font-size: 14px;
      cursor: pointer;
      user-select: none;
      transition: background 0.15s;
      text-decoration: none;
    }
    .gm-nav-item:hover { background: #eaedf1; }
    .gm-nav-item.active {
      background: var(--gm-sidebar-active);
      font-weight: 700;
      color: #001d35;
    }
    .gm-nav-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .gm-nav-left i { font-size: 15px; width: 18px; text-align: center; }
    .gm-nav-badge {
      font-size: 12px;
      font-weight: 700;
      color: #001d35;
    }

    /* CONTENT BODY */
    .gm-content {
      flex: 1;
      background: #ffffff;
      border-radius: 16px 16px 0 0;
      margin: 8px 12px 0 0;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border: 1px solid var(--gm-border);
      border-bottom: none;
    }

    /* TOOLBAR */
    .gm-toolbar {
      height: 48px;
      border-bottom: 1px solid var(--gm-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      font-size: 14px;
      color: var(--gm-text-sub);
    }
    .gm-toolbar-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .gm-btn-icon {
      background: none;
      border: none;
      color: var(--gm-text-sub);
      font-size: 15px;
      cursor: pointer;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s;
    }
    .gm-btn-icon:hover { background: #f1f3f4; color: var(--gm-text-main); }
    .gm-btn-icon.spinning i {
      animation: gmSpin 0.8s linear infinite;
    }
    @keyframes gmSpin {
      100% { transform: rotate(360deg); }
    }

    /* EMAIL LIST VIEW */
    .gm-mail-list-container {
      flex: 1;
      overflow-y: auto;
    }
    .gm-mail-row {
      display: flex;
      align-items: center;
      height: 48px;
      padding: 0 16px;
      border-bottom: 1px solid #f1f3f4;
      cursor: pointer;
      font-size: 14px;
      transition: background 0.15s, box-shadow 0.15s;
      position: relative;
    }
    .gm-mail-row:hover {
      background: #f8fafd;
      box-shadow: inset 1px 0 0 #dadce0, inset -1px 0 0 #dadce0, 0 1px 2px 0 rgba(60,64,67,0.3);
      z-index: 2;
    }
    .gm-mail-row.unread {
      background: #ffffff;
      font-weight: 700;
      color: #1f1f1f;
    }
    .gm-mail-row.read {
      background: #f2f6fc;
      font-weight: 400;
      color: #444746;
    }
    .gm-row-icons {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-right: 16px;
      color: #c4c7c5;
    }
    .gm-row-icons i:hover { color: #fbbc04; }
    .gm-row-sender {
      width: 190px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      flex-shrink: 0;
    }
    .gm-row-subject {
      flex: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      padding-right: 16px;
    }
    .gm-row-snippet {
      color: var(--gm-text-sub);
      font-weight: 400;
    }
    .gm-row-time {
      font-size: 12px;
      color: var(--gm-text-sub);
      white-space: nowrap;
      margin-left: auto;
    }

    /* EMPTY STATE */
    .gm-empty-state {
      padding: 60px 20px;
      text-align: center;
      color: var(--gm-text-sub);
    }
    .gm-empty-icon {
      font-size: 54px;
      color: #dadce0;
      margin-bottom: 16px;
    }

    /* EMAIL DETAIL VIEW */
    .gm-mail-detail-container {
      flex: 1;
      overflow-y: auto;
      display: none;
      flex-direction: column;
      padding: 24px 32px;
      background: #ffffff;
    }
    .gm-detail-header {
      margin-bottom: 20px;
    }
    .gm-detail-subject {
      font-family: 'Google Sans', sans-serif;
      font-size: 22px;
      font-weight: 500;
      color: var(--gm-text-main);
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 16px;
    }
    .gm-chip-inbox {
      font-size: 11px;
      font-weight: 700;
      background: #e8eaed;
      color: #3c4043;
      padding: 2px 8px;
      border-radius: 4px;
    }
    .gm-detail-sender-row {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 20px;
    }
    .gm-sender-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #1a73e8;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 16px;
    }
    .gm-sender-meta {
      flex: 1;
    }
    .gm-sender-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--gm-text-main);
    }
    .gm-sender-email {
      font-size: 12px;
      color: var(--gm-text-sub);
      margin-left: 6px;
      font-weight: 400;
    }
    .gm-detail-to {
      font-size: 12px;
      color: var(--gm-text-sub);
      margin-top: 2px;
    }
    .gm-detail-date {
      font-size: 12px;
      color: var(--gm-text-sub);
      white-space: nowrap;
    }
    .gm-detail-body {
      padding: 20px 0;
      border-top: 1px solid var(--gm-border);
    }

    /* RESPONSIVE */
    @media (max-width: 768px) {
      .gm-sidebar { display: none; }
      .gm-content { margin: 0; border-radius: 0; border: none; }
      .gm-row-sender { width: 120px; }
    }
  </style>
</head>
<body>

  <!-- TOP HEADER -->
  <header class="gm-header">
    <div class="gm-brand">
      <img src="https://ssl.gstatic.com/ui/v1/icons/mail/rfr/gmail.ico" alt="Gmail" class="gm-logo-img">
      <span class="gm-brand-title">
        Gmail
        <span class="gm-brand-badge">FoodDash Simulator</span>
      </span>
    </div>

    <div class="gm-search-wrapper">
      <div class="gm-search-box">
        <i class="fa-solid fa-magnifying-glass gm-search-icon"></i>
        <input type="text" id="gmSearchInput" class="gm-search-input" placeholder="Search in mail..." onkeyup="filterEmails()">
      </div>
    </div>

    <div class="gm-user-area">
      <div class="gm-account-chip">
        <i class="fa-solid fa-envelope" style="color: var(--gm-red);"></i>
        <select id="gmRecipientFilter" class="gm-email-select" onchange="changeRecipientFilter()">
          <option value="all" <?php echo empty($filter_email) ? 'selected' : ''; ?>>All Inboxes</option>
          <option value="customer@gmail.com" <?php echo $filter_email === 'customer@gmail.com' ? 'selected' : ''; ?>>customer@gmail.com</option>
          <option value="admin@gmail.com" <?php echo $filter_email === 'admin@gmail.com' ? 'selected' : ''; ?>>admin@gmail.com</option>
          <option value="manager@gmail.com" <?php echo $filter_email === 'manager@gmail.com' ? 'selected' : ''; ?>>manager@gmail.com</option>
          <option value="rider@gmail.com" <?php echo $filter_email === 'rider@gmail.com' ? 'selected' : ''; ?>>rider@gmail.com</option>
          <option value="noahjames@gmail.com" <?php echo $filter_email === 'noahjames@gmail.com' ? 'selected' : ''; ?>>noahjames@gmail.com</option>
          <?php if (!empty($filter_email) && !in_array($filter_email, ['customer@gmail.com', 'admin@gmail.com', 'manager@gmail.com', 'rider@gmail.com', 'noahjames@gmail.com', 'all'])): ?>
            <option value="<?php echo htmlspecialchars($filter_email); ?>" selected><?php echo htmlspecialchars($filter_email); ?></option>
          <?php endif; ?>
        </select>
      </div>

      <a href="index.php" style="text-decoration:none;" title="Return to FoodDash">
        <div class="gm-avatar" id="gmAvatar">FD</div>
      </a>
    </div>
  </header>

  <!-- MAIN LAYOUT -->
  <div class="gm-main-layout">
    
    <!-- LEFT SIDEBAR -->
    <aside class="gm-sidebar">
      <button class="gm-compose-btn" onclick="sendQuickResetDemo()">
        <svg width="24" height="24" viewBox="0 0 24 24"><path fill="#4285F4" d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"></path></svg>
        <span>Test Reset</span>
      </button>

      <a href="#" class="gm-nav-item active" onclick="showMailList(); return false;">
        <div class="gm-nav-left">
          <i class="fa-solid fa-inbox" style="color: #001d35;"></i>
          <span>Inbox</span>
        </div>
        <span class="gm-nav-badge" id="gmSidebarBadge">0</span>
      </a>

      <div class="gm-nav-item" onclick="alert('Simulated folder: Starred')">
        <div class="gm-nav-left">
          <i class="fa-regular fa-star"></i>
          <span>Starred</span>
        </div>
      </div>

      <div class="gm-nav-item" onclick="alert('Simulated folder: Sent')">
        <div class="gm-nav-left">
          <i class="fa-regular fa-paper-plane"></i>
          <span>Sent</span>
        </div>
      </div>

      <div class="gm-nav-item" onclick="alert('Simulated folder: Drafts')">
        <div class="gm-nav-left">
          <i class="fa-regular fa-file"></i>
          <span>Drafts</span>
        </div>
      </div>

      <div style="margin-top: auto; padding: 12px 6px; font-size: 11px; color: var(--gm-text-sub); border-top: 1px solid var(--gm-border);">
        <p><i class="fa-solid fa-cloud"></i> Google Workspace Internal Node</p>
        <p style="margin-top: 4px;">Connected to FoodDash DB</p>
      </div>
    </aside>

    <!-- CONTENT BODY -->
    <main class="gm-content">
      
      <!-- TOOLBAR -->
      <div class="gm-toolbar">
        <div class="gm-toolbar-left">
          <button class="gm-btn-icon" id="gmBackBtn" style="display:none;" onclick="showMailList()" title="Back to Inbox">
            <i class="fa-solid fa-arrow-left"></i>
          </button>
          <button class="gm-btn-icon" id="gmRefreshBtn" onclick="refreshEmails(true)" title="Refresh">
            <i class="fa-solid fa-rotate-right"></i>
          </button>
          <button class="gm-btn-icon" onclick="markAllRead()" title="Mark all as read">
            <i class="fa-regular fa-envelope-open"></i>
          </button>
        </div>
        <div id="gmStatusIndicator" style="font-size: 12px; color: var(--gm-text-sub);">
          Syncing...
        </div>
      </div>

      <!-- MAIL LIST PANE -->
      <div id="gmMailList" class="gm-mail-list-container">
        <!-- Dynamically rendered email rows -->
      </div>

      <!-- MAIL DETAIL VIEW -->
      <div id="gmMailDetail" class="gm-mail-detail-container">
        <div class="gm-detail-header">
          <div class="gm-detail-subject">
            <span id="gmDetailSubject">Subject</span>
            <span class="gm-chip-inbox">Inbox</span>
          </div>

          <div class="gm-detail-sender-row">
            <div class="gm-sender-avatar" id="gmDetailAvatar">FD</div>
            <div class="gm-sender-meta">
              <div>
                <span class="gm-sender-name">Google Workspace / FoodDash Security</span>
                <span class="gm-sender-email">&lt;security@fooddash.internal&gt;</span>
              </div>
              <div class="gm-detail-to" id="gmDetailRecipient">to me</div>
            </div>
            <div class="gm-detail-date" id="gmDetailDate">Oct 16, 2026</div>
          </div>
        </div>

        <div class="gm-detail-body" id="gmDetailBody">
          <!-- Full HTML Email injected here -->
        </div>
      </div>

    </main>

  </div>

  <script>
    let allEmails = [];
    let activeEmailId = null;

    function getSelectedRecipient() {
      const sel = document.getElementById('gmRecipientFilter');
      return sel ? sel.value : 'all';
    }

    function changeRecipientFilter() {
      const email = getSelectedRecipient();
      const url = new URL(window.location);
      if (email && email !== 'all') {
        url.searchParams.set('email', email);
      } else {
        url.searchParams.delete('email');
      }
      window.history.replaceState({}, '', url);
      refreshEmails(false);
    }

    async function refreshEmails(spin = false) {
      const refBtn = document.getElementById('gmRefreshBtn');
      const statusEl = document.getElementById('gmStatusIndicator');
      if (spin && refBtn) refBtn.classList.add('spinning');
      if (statusEl) statusEl.textContent = 'Updating...';

      try {
        const filter = getSelectedRecipient();
        const res = await fetch(`mail_api.php?action=list&email=${encodeURIComponent(filter)}&t=${Date.now()}`);
        const data = await res.json();

        if (data.success) {
          allEmails = data.emails || [];
          document.getElementById('gmSidebarBadge').textContent = data.unread_count || 0;
          if (statusEl) {
            statusEl.textContent = `${allEmails.length} messages (${data.unread_count} unread)`;
          }
          renderEmailList(allEmails);
        }
      } catch (e) {
        if (statusEl) statusEl.textContent = 'Connection error';
      } finally {
        if (refBtn) refBtn.classList.remove('spinning');
      }
    }

    function renderEmailList(emails) {
      const container = document.getElementById('gmMailList');
      if (!emails || emails.length === 0) {
        container.innerHTML = `
          <div class="gm-empty-state">
            <i class="fa-regular fa-folder-open gm-empty-icon"></i>
            <h3>Your inbox is empty</h3>
            <p style="margin-top: 6px; font-size: 13px;">No password reset emails found for this address. Trigger "Send Reset" from the login page or click "Test Reset" above.</p>
          </div>
        `;
        return;
      }

      let html = '';
      emails.forEach(mail => {
        const readClass = mail.is_read ? 'read' : 'unread';
        const starClass = mail.is_read ? 'fa-regular' : 'fa-solid';
        const starColor = mail.is_read ? '#c4c7c5' : '#fbbc04';

        html += `
          <div class="gm-mail-row ${readClass}" onclick="openEmailDetail(${mail.id})">
            <div class="gm-row-icons">
              <i class="fa-regular fa-square"></i>
              <i class="${starClass} fa-star" style="color:${starColor};"></i>
            </div>
            <div class="gm-row-sender" title="Google Workspace / FoodDash Security">
              Google Security
            </div>
            <div class="gm-row-subject">
              <span>${escapeHtml(mail.subject)}</span>
              <span class="gm-row-snippet"> - ${escapeHtml(mail.snippet)}</span>
            </div>
            <div class="gm-row-time">
              ${mail.time_ago}
            </div>
          </div>
        `;
      });

      container.innerHTML = html;
    }

    async function openEmailDetail(id) {
      try {
        const res = await fetch(`mail_api.php?action=get&id=${id}&t=${Date.now()}`);
        const data = await res.json();
        if (data.success && data.email) {
          const mail = data.email;
          activeEmailId = mail.id;

          document.getElementById('gmDetailSubject').textContent = mail.subject;
          document.getElementById('gmDetailRecipient').textContent = `to ${mail.recipient}`;
          document.getElementById('gmDetailDate').textContent = mail.formatted_date || mail.sent_at;
          document.getElementById('gmDetailBody').innerHTML = mail.body_html;

          // Toggle panes
          document.getElementById('gmMailList').style.display = 'none';
          document.getElementById('gmMailDetail').style.display = 'flex';
          document.getElementById('gmBackBtn').style.display = 'inline-flex';

          // Update local unread counter
          refreshEmails(false);
        }
      } catch (e) {
        alert('Failed to load email message.');
      }
    }

    function showMailList() {
      document.getElementById('gmMailList').style.display = 'block';
      document.getElementById('gmMailDetail').style.display = 'none';
      document.getElementById('gmBackBtn').style.display = 'none';
      activeEmailId = null;
    }

    function filterEmails() {
      const q = document.getElementById('gmSearchInput').value.toLowerCase().trim();
      if (!q) {
        renderEmailList(allEmails);
        return;
      }
      const filtered = allEmails.filter(m => 
        m.subject.toLowerCase().includes(q) ||
        m.recipient.toLowerCase().includes(q) ||
        m.snippet.toLowerCase().includes(q)
      );
      renderEmailList(filtered);
    }

    async function markAllRead() {
      for (const m of allEmails) {
        if (!m.is_read) {
          await fetch(`mail_api.php?action=mark_read&id=${m.id}`);
        }
      }
      refreshEmails(true);
    }

    async function sendQuickResetDemo() {
      const email = getSelectedRecipient() === 'all' ? 'customer@gmail.com' : getSelectedRecipient();
      const res = await fetch('mail_api.php?action=send_reset', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `email=${encodeURIComponent(email)}`
      });
      const data = await res.json();
      if (data.success) {
        refreshEmails(true);
        if (data.email_id) {
          setTimeout(() => openEmailDetail(data.email_id), 300);
        }
      }
    }

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Auto-poll every 6 seconds for new simulated emails
    setInterval(() => {
      if (document.getElementById('gmMailList').style.display !== 'none') {
        refreshEmails(false);
      }
    }, 6000);

    // Initial Load
    refreshEmails(true);
  </script>
</body>
</html>
