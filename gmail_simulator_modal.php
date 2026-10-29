<?php
/**
 * Embedded System Gmail Webmail Inbox Simulator Widget & Modal
 * Provides an authentic Google Workspace / Gmail simulator directly inside FoodDash pages.
 */
$current_sim_email = '';
if (isset($_SESSION['user']['email'])) {
    $current_sim_email = $_SESSION['user']['email'];
} elseif (isset($_SESSION['email'])) {
    $current_sim_email = $_SESSION['email'];
} elseif (isset($_SESSION['username']) && strpos($_SESSION['username'], '@') !== false) {
    $current_sim_email = $_SESSION['username'];
} else {
    $current_sim_email = 'customer@gmail.com';
}
?>
<!-- FLOATING GMAIL INBOX BUTTON -->
<div id="fdGmailFloatingBtn" class="fd-gmail-floating-btn" onclick="openGmailModal()" title="Open Simulated Gmail Webmail Inbox">
  <div class="fd-gmail-btn-icon">
    <!-- Gmail Red Envelope SVG -->
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
      <path d="M20 4H4C2.9 4 2.01 4.9 2.01 6L2 18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4Z" fill="#EA4335" opacity="0.15"/>
      <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM20 8L12 13L4 8V6L12 11L20 6V8Z" fill="#EA4335"/>
    </svg>
    <span class="fd-gmail-badge" id="fdGmailUnreadBadge">0</span>
  </div>
  <span class="fd-gmail-btn-label">Gmail Inbox</span>
</div>

<!-- GMAIL SIMULATOR MODAL BACKDROP -->
<div id="fdGmailModal" class="fd-gmail-modal-backdrop" onclick="handleGmailBackdropClick(event)">
  <div class="fd-gmail-modal-window" role="dialog" aria-modal="true" aria-labelledby="fdGmailModalTitle">
    
    <!-- MODAL TOP BAR (GMAIL HEADER) -->
    <div class="fd-gm-header">
      <div class="fd-gm-brand-group">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" style="flex-shrink:0;">
          <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM20 8L12 13L4 8V6L12 11L20 6V8Z" fill="#EA4335"/>
        </svg>
        <div class="fd-gm-title-area">
          <span class="fd-gm-title-main" id="fdGmailModalTitle">Gmail - Inbox</span>
          <span class="fd-gm-subtitle" id="fdGmHeaderSubtitle">(<?php echo htmlspecialchars($current_sim_email); ?>)</span>
        </div>
      </div>

      <!-- SEARCH BAR -->
      <div class="fd-gm-search-container">
        <i class="fa-solid fa-magnifying-glass fd-gm-search-icon"></i>
        <input type="text" id="fdGmSearchInput" class="fd-gm-search-input" placeholder="Search in mail..." onkeyup="filterGmailModalEmails()">
      </div>

      <!-- TOP ACTIONS -->
      <div class="fd-gm-actions-group">
        <select id="fdGmRecipientSelect" class="fd-gm-email-picker" onchange="changeModalRecipientFilter()" title="Filter by recipient">
          <option value="all">All Inboxes</option>
          <option value="customer@gmail.com" <?php echo $current_sim_email === 'customer@gmail.com' ? 'selected' : ''; ?>>customer@gmail.com</option>
          <option value="admin@gmail.com" <?php echo $current_sim_email === 'admin@gmail.com' ? 'selected' : ''; ?>>admin@gmail.com</option>
          <option value="manager@gmail.com" <?php echo $current_sim_email === 'manager@gmail.com' ? 'selected' : ''; ?>>manager@gmail.com</option>
          <option value="rider@gmail.com" <?php echo $current_sim_email === 'rider@gmail.com' ? 'selected' : ''; ?>>rider@gmail.com</option>
          <option value="noahjames@gmail.com" <?php echo $current_sim_email === 'noahjames@gmail.com' ? 'selected' : ''; ?>>noahjames@gmail.com</option>
          <?php if (!empty($current_sim_email) && !in_array($current_sim_email, ['customer@gmail.com', 'admin@gmail.com', 'manager@gmail.com', 'rider@gmail.com', 'noahjames@gmail.com', 'all'])): ?>
            <option value="<?php echo htmlspecialchars($current_sim_email); ?>" selected><?php echo htmlspecialchars($current_sim_email); ?></option>
          <?php endif; ?>
        </select>

        <a href="view_mail.php" target="_blank" class="fd-gm-icon-btn" title="Open full Gmail simulator tab">
          <i class="fa-solid fa-arrow-up-right-from-square"></i>
        </a>
        <button type="button" class="fd-gm-icon-btn" onclick="refreshGmailModalEmails(true)" title="Refresh mail">
          <i class="fa-solid fa-rotate-right" id="fdGmRefreshIcon"></i>
        </button>
        <button type="button" class="fd-gm-icon-btn fd-gm-close-btn" onclick="closeGmailModal()" title="Close simulator">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </div>

    <!-- MODAL BODY -->
    <div class="fd-gm-body-layout">
      
      <!-- MINI SIDEBAR -->
      <div class="fd-gm-mini-sidebar">
        <button type="button" class="fd-gm-compose-pill" onclick="sendQuickResetFromModal()">
          <span style="font-size:18px; line-height:1; color:#1a73e8;">+</span>
          <span>Test Reset</span>
        </button>

        <div class="fd-gm-tab active" onclick="showGmailModalList()">
          <i class="fa-solid fa-inbox" style="color:#0b57d0;"></i>
          <span>Inbox</span>
          <span class="fd-gm-tab-count" id="fdGmTabBadge">0</span>
        </div>
        <div class="fd-gm-tab" onclick="alert('Simulated: Starred emails')">
          <i class="fa-regular fa-star"></i>
          <span>Starred</span>
        </div>
        <div class="fd-gm-tab" onclick="alert('Simulated: Sent emails')">
          <i class="fa-regular fa-paper-plane"></i>
          <span>Sent</span>
        </div>
        <div class="fd-gm-tab" onclick="alert('Simulated: Draft emails')">
          <i class="fa-regular fa-file"></i>
          <span>Drafts</span>
        </div>

        <div class="fd-gm-security-tag">
          <i class="fa-solid fa-shield-halved"></i>
          <span>Google Workspace Verified</span>
        </div>
      </div>

      <!-- MAIN CONTENT AREA -->
      <div class="fd-gm-main-view">
        
        <!-- SUB TOOLBAR -->
        <div class="fd-gm-sub-toolbar">
          <div style="display:flex; align-items:center; gap:8px;">
            <button type="button" id="fdGmDetailBackBtn" class="fd-gm-sub-btn" style="display:none;" onclick="showGmailModalList()">
              <i class="fa-solid fa-arrow-left"></i> Back to Inbox
            </button>
            <span id="fdGmListStatus" style="font-size: 11px; color: #5f6368;">Syncing with FoodDash mail spool...</span>
          </div>
          <button type="button" class="fd-gm-sub-btn" onclick="markAllGmailModalRead()" title="Mark all as read">
            <i class="fa-regular fa-envelope-open"></i> Mark all read
          </button>
        </div>

        <!-- LIST PANE -->
        <div id="fdGmListContainer" class="fd-gm-list-pane">
          <!-- Populated by JS -->
        </div>

        <!-- DETAIL PANE -->
        <div id="fdGmDetailContainer" class="fd-gm-detail-pane" style="display:none;">
          <div class="fd-gm-detail-title-row">
            <h3 id="fdGmDetailSubject" class="fd-gm-detail-subject">Subject</h3>
            <span class="fd-gm-inbox-tag">Inbox</span>
          </div>

          <div class="fd-gm-sender-card">
            <div class="fd-gm-sender-avatar">FD</div>
            <div style="flex:1;">
              <div style="font-size:13px; font-weight:700; color:#202124;">
                Google Workspace / FoodDash Security
                <span style="font-weight:400; color:#5f6368; font-size:11px;">&lt;security@fooddash.internal&gt;</span>
              </div>
              <div id="fdGmDetailRecipient" style="font-size:11px; color:#5f6368; margin-top:2px;">to recipient</div>
            </div>
            <div id="fdGmDetailTime" style="font-size:11px; color:#5f6368;">Time</div>
          </div>

          <div id="fdGmDetailBody" class="fd-gm-email-body-content">
            <!-- Full email HTML -->
          </div>
        </div>

      </div>

    </div>

  </div>
</div>

<!-- GMAIL SIMULATOR STYLES -->
<style>
  /* FLOATING TRIGGER BUTTON */
  .fd-gmail-floating-btn, .gmail-widget, #gmailInboxWidget, #fdGmailFloatingBtn {
    position: fixed !important;
    bottom: calc(75px + env(safe-area-inset-bottom, 10px)) !important;
    right: 16px !important;
    z-index: 10000 !important;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #ffffff;
    color: #202124;
    padding: 10px 16px;
    border-radius: 28px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.18), 0 1px 3px rgba(0,0,0,0.08);
    cursor: pointer;
    border: 1px solid #dadce0;
    font-family: 'Google Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    user-select: none;
  }
  .fd-gmail-floating-btn:hover {
    box-shadow: 0 8px 24px rgba(234,67,53,0.25), 0 2px 6px rgba(0,0,0,0.12);
    transform: translateY(-2px);
    border-color: #ea4335;
  }
  .fd-gmail-btn-icon {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .fd-gmail-badge {
    position: absolute;
    top: -6px;
    right: -8px;
    background: #ea4335;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    min-width: 17px;
    height: 17px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    box-shadow: 0 2px 4px rgba(234,67,53,0.4);
    border: 2px solid #ffffff;
    transition: transform 0.2s;
  }
  .fd-gmail-badge.bump {
    transform: scale(1.3);
  }
  .fd-gmail-btn-label {
    letter-spacing: -0.2px;
  }

  /* MODAL BACKDROP */
  .fd-gmail-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(32, 33, 36, 0.6);
    backdrop-filter: blur(3px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none !important;
    transition: opacity 0.2s ease-in-out;
  }
  .fd-gmail-modal-backdrop.active {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
  }
  .fd-gmail-modal-backdrop:not(.active) {
    display: none !important;
    pointer-events: none !important;
  }

  /* MODAL WINDOW */
  .fd-gmail-modal-window {
    width: 100%;
    max-width: 1000px;
    height: 85vh;
    max-height: 720px;
    background: #f6f8fc;
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.3);
    border: 1px solid #dadce0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: scale(0.97);
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .fd-gmail-modal-backdrop.active .fd-gmail-modal-window {
    transform: scale(1);
  }

  /* MODAL HEADER */
  .fd-gm-header {
    height: 60px;
    background: #ffffff;
    border-bottom: 1px solid #e0e3e7;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 18px;
    gap: 14px;
  }
  .fd-gm-brand-group {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 200px;
  }
  .fd-gm-title-area {
    display: flex;
    flex-direction: column;
  }
  .fd-gm-title-main {
    font-family: 'Google Sans', sans-serif;
    font-size: 16px;
    font-weight: 700;
    color: #202124;
    letter-spacing: -0.3px;
  }
  .fd-gm-subtitle {
    font-size: 11px;
    color: #5f6368;
    max-width: 180px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* SEARCH CONTAINER */
  .fd-gm-search-container {
    flex: 1;
    max-width: 440px;
    height: 38px;
    background: #eaf1fb;
    border-radius: 20px;
    display: flex;
    align-items: center;
    padding: 0 14px;
    border: 1px solid transparent;
    transition: all 0.2s;
  }
  .fd-gm-search-container:focus-within {
    background: #ffffff;
    border-color: #dadce0;
    box-shadow: 0 1px 3px rgba(60,64,67,0.25);
  }
  .fd-gm-search-icon {
    font-size: 13px;
    color: #5f6368;
    margin-right: 10px;
  }
  .fd-gm-search-input {
    border: none;
    background: transparent;
    outline: none;
    font-size: 13px;
    width: 100%;
    color: #202124;
  }

  /* ACTIONS GROUP */
  .fd-gm-actions-group {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .fd-gm-email-picker {
    border: 1px solid #dadce0;
    background: #f1f3f4;
    padding: 6px 10px;
    border-radius: 14px;
    font-size: 12px;
    font-weight: 600;
    color: #3c4043;
    outline: none;
    cursor: pointer;
  }
  .fd-gm-icon-btn {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: #5f6368;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    text-decoration: none;
  }
  .fd-gm-icon-btn:hover {
    background: #f1f3f4;
    color: #202124;
  }
  .fd-gm-close-btn:hover {
    background: #fce8e6;
    color: #ea4335;
  }
  .fd-gm-icon-btn.spinning i {
    animation: gmModalSpin 0.7s linear infinite;
  }
  @keyframes gmModalSpin {
    100% { transform: rotate(360deg); }
  }

  /* BODY LAYOUT */
  .fd-gm-body-layout {
    flex: 1;
    display: flex;
    overflow: hidden;
  }
  .fd-gm-mini-sidebar {
    width: 180px;
    background: #f6f8fc;
    border-right: 1px solid #e0e3e7;
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex-shrink: 0;
  }
  .fd-gm-compose-pill {
    background: #c2e7ff;
    color: #001d35;
    border: none;
    border-radius: 14px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    margin-bottom: 10px;
    width: fit-content;
    transition: background 0.15s;
  }
  .fd-gm-compose-pill:hover {
    background: #b3defa;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
  }
  .fd-gm-tab {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 7px 12px;
    border-radius: 16px;
    font-size: 13px;
    color: #444746;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;
  }
  .fd-gm-tab:hover { background: #eaedf1; }
  .fd-gm-tab.active {
    background: #d3e3fd;
    font-weight: 700;
    color: #001d35;
  }
  .fd-gm-tab-count {
    margin-left: auto;
    font-size: 11px;
    font-weight: 700;
    color: #001d35;
  }
  .fd-gm-security-tag {
    margin-top: auto;
    font-size: 10px;
    color: #1a73e8;
    background: #e8f0fe;
    padding: 6px 8px;
    border-radius: 6px;
    border: 1px solid #d2e3fc;
    display: flex;
    align-items: center;
    gap: 5px;
    line-height: 1.3;
  }

  /* MAIN CONTENT VIEW */
  .fd-gm-main-view {
    flex: 1;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .fd-gm-sub-toolbar {
    height: 42px;
    border-bottom: 1px solid #f1f3f4;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    background: #fafafa;
  }
  .fd-gm-sub-btn {
    border: none;
    background: transparent;
    font-size: 11px;
    font-weight: 600;
    color: #5f6368;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .fd-gm-sub-btn:hover {
    background: #f1f3f4;
    color: #202124;
  }

  /* LIST PANE */
  .fd-gm-list-pane {
    flex: 1;
    overflow-y: auto;
  }
  .fd-gm-item-row {
    display: flex;
    align-items: center;
    height: 46px;
    padding: 0 16px;
    border-bottom: 1px solid #f1f3f4;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.15s;
    user-select: none;
  }
  .fd-gm-item-row:hover {
    background: #f8fafd;
    box-shadow: inset 1px 0 0 #dadce0, inset -1px 0 0 #dadce0;
  }
  .fd-gm-item-row.unread {
    background: #ffffff;
    font-weight: 700;
    color: #1f1f1f;
  }
  .fd-gm-item-row.read {
    background: #f7f9fc;
    font-weight: 400;
    color: #444746;
  }
  .fd-gm-col-icons {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 14px;
    color: #c4c7c5;
  }
  .fd-gm-col-sender {
    width: 170px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex-shrink: 0;
  }
  .fd-gm-col-subject {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-right: 12px;
  }
  .fd-gm-col-snippet {
    color: #5f6368;
    font-weight: 400;
  }
  .fd-gm-col-time {
    font-size: 11px;
    color: #5f6368;
    white-space: nowrap;
    margin-left: auto;
  }
  .fd-gm-empty-state {
    padding: 50px 20px;
    text-align: center;
    color: #5f6368;
  }

  /* DETAIL PANE */
  .fd-gm-detail-pane {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    background: #ffffff;
  }
  .fd-gm-detail-title-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
  }
  .fd-gm-detail-subject {
    font-family: 'Google Sans', sans-serif;
    font-size: 19px;
    font-weight: 600;
    color: #202124;
    margin: 0;
  }
  .fd-gm-inbox-tag {
    font-size: 10px;
    font-weight: 700;
    background: #e8eaed;
    color: #3c4043;
    padding: 2px 6px;
    border-radius: 4px;
  }
  .fd-gm-sender-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f1f3f4;
    margin-bottom: 16px;
  }
  .fd-gm-sender-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #1a73e8;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
  }
  .fd-gm-email-body-content {
    line-height: 1.5;
  }
  @media (max-width: 768px) {
    .fd-gmail-floating-btn, .gmail-widget, #gmailInboxWidget, #fdGmailFloatingBtn {
      bottom: calc(75px + env(safe-area-inset-bottom, 10px)) !important;
      right: 16px !important;
      z-index: 10000 !important;
      padding: 8px 12px;
      font-size: 12px;
    }
    .fd-gm-mini-sidebar { display: none; }
    .fd-gm-col-sender { width: 110px; }
    .fd-gmail-modal-window { height: 95vh; max-height: none; border-radius: 12px; }
  }
</style>

<!-- GMAIL SIMULATOR JAVASCRIPT LOGIC -->
<script>
  let fdGmEmails = [];
  let fdGmActiveId = null;

  function openGmailModal(preselectEmail = null) {
    const modal = document.getElementById('fdGmailModal');
    if (modal) {
      modal.classList.add('active');
      if (preselectEmail) {
        const picker = document.getElementById('fdGmRecipientSelect');
        if (picker) {
          picker.value = preselectEmail;
          changeModalRecipientFilter();
        }
      }
      refreshGmailModalEmails(true);
    }
  }

  function closeGmailModal() {
    const modal = document.getElementById('fdGmailModal');
    if (modal) modal.classList.remove('active');
  }

  function handleGmailBackdropClick(e) {
    if (e.target.id === 'fdGmailModal') {
      closeGmailModal();
    }
  }

  function getModalSelectedEmail() {
    const sel = document.getElementById('fdGmRecipientSelect');
    return sel ? sel.value : 'all';
  }

  function changeModalRecipientFilter() {
    const email = getModalSelectedEmail();
    const subTitle = document.getElementById('fdGmHeaderSubtitle');
    if (subTitle) {
      subTitle.textContent = email === 'all' ? '(All Inboxes)' : `(${email})`;
    }
    showGmailModalList();
    refreshGmailModalEmails(false);
  }

  async function refreshGmailModalEmails(showSpinner = false) {
    const icon = document.getElementById('fdGmRefreshIcon');
    const statusEl = document.getElementById('fdGmListStatus');
    if (showSpinner && icon) icon.parentElement.classList.add('spinning');
    if (statusEl) statusEl.textContent = 'Checking inbox...';

    try {
      const email = getModalSelectedEmail();
      const res = await fetch(`mail_api.php?action=list&email=${encodeURIComponent(email)}&t=${Date.now()}`);
      const data = await res.json();

      if (data.success) {
        fdGmEmails = data.emails || [];
        const unread = data.unread_count || 0;

        // Update badge counters
        const floatBadge = document.getElementById('fdGmailUnreadBadge');
        const tabBadge = document.getElementById('fdGmTabBadge');
        if (floatBadge) {
          floatBadge.textContent = unread;
          if (unread > 0) {
            floatBadge.classList.add('bump');
            setTimeout(() => floatBadge.classList.remove('bump'), 300);
          }
        }
        if (tabBadge) tabBadge.textContent = unread;

        if (statusEl) {
          statusEl.textContent = `${fdGmEmails.length} messages (${unread} unread)`;
        }

        renderGmailModalList(fdGmEmails);
      }
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Mail network error';
    } finally {
      if (icon) icon.parentElement.classList.remove('spinning');
    }
  }

  function renderGmailModalList(emails) {
    const container = document.getElementById('fdGmListContainer');
    if (!container) return;

    if (!emails || emails.length === 0) {
      container.innerHTML = `
        <div class="fd-gm-empty-state">
          <i class="fa-regular fa-envelope-open" style="font-size:42px; color:#dadce0; margin-bottom:12px; display:block;"></i>
          <strong style="display:block; font-size:14px; color:#202124;">No messages here</strong>
          <p style="font-size:12px; color:#5f6368; margin-top:4px;">Trigger "Send Reset" in the password recovery modal or click "+ Test Reset" to simulate incoming mail.</p>
        </div>
      `;
      return;
    }

    let html = '';
    emails.forEach(mail => {
      const isUnread = !mail.is_read;
      const readClass = isUnread ? 'unread' : 'read';
      const starClass = isUnread ? 'fa-solid' : 'fa-regular';
      const starColor = isUnread ? '#fbbc04' : '#c4c7c5';

      html += `
        <div class="fd-gm-item-row ${readClass}" onclick="openGmailModalDetail(${mail.id})">
          <div class="fd-gm-col-icons">
            <i class="fa-regular fa-square"></i>
            <i class="${starClass} fa-star" style="color:${starColor};"></i>
          </div>
          <div class="fd-gm-col-sender" title="${escapeGmHtml(mail.recipient)}">
            Google Security
          </div>
          <div class="fd-gm-col-subject">
            <span>${escapeGmHtml(mail.subject)}</span>
            <span class="fd-gm-col-snippet"> - ${escapeGmHtml(mail.snippet)}</span>
          </div>
          <div class="fd-gm-col-time">
            ${mail.time_ago}
          </div>
        </div>
      `;
    });

    container.innerHTML = html;
  }

  async function openGmailModalDetail(id) {
    try {
      const res = await fetch(`mail_api.php?action=get&id=${id}&t=${Date.now()}`);
      const data = await res.json();
      if (data.success && data.email) {
        const mail = data.email;
        fdGmActiveId = mail.id;

        document.getElementById('fdGmDetailSubject').textContent = mail.subject;
        document.getElementById('fdGmDetailRecipient').textContent = `to ${mail.recipient}`;
        document.getElementById('fdGmDetailTime').textContent = mail.formatted_date || mail.sent_at;
        document.getElementById('fdGmDetailBody').innerHTML = mail.body_html;

        document.getElementById('fdGmListContainer').style.display = 'none';
        document.getElementById('fdGmDetailContainer').style.display = 'block';
        document.getElementById('fdGmDetailBackBtn').style.display = 'inline-flex';

        // Refresh count
        refreshGmailModalEmails(false);
      }
    } catch (e) {
      alert('Could not open email.');
    }
  }

  function showGmailModalList() {
    const listPane = document.getElementById('fdGmListContainer');
    const detailPane = document.getElementById('fdGmDetailContainer');
    const backBtn = document.getElementById('fdGmDetailBackBtn');
    if (listPane) listPane.style.display = 'block';
    if (detailPane) detailPane.style.display = 'none';
    if (backBtn) backBtn.style.display = 'none';
    fdGmActiveId = null;
  }

  function filterGmailModalEmails() {
    const q = document.getElementById('fdGmSearchInput').value.toLowerCase().trim();
    if (!q) {
      renderGmailModalList(fdGmEmails);
      return;
    }
    const filtered = fdGmEmails.filter(m =>
      m.subject.toLowerCase().includes(q) ||
      m.recipient.toLowerCase().includes(q) ||
      m.snippet.toLowerCase().includes(q)
    );
    renderGmailModalList(filtered);
  }

  async function markAllGmailModalRead() {
    for (const m of fdGmEmails) {
      if (!m.is_read) {
        await fetch(`mail_api.php?action=mark_read&id=${m.id}`);
      }
    }
    refreshGmailModalEmails(true);
  }

  async function sendQuickResetFromModal() {
    const email = getModalSelectedEmail() === 'all' ? 'customer@gmail.com' : getModalSelectedEmail();
    const res = await fetch('mail_api.php?action=send_reset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `email=${encodeURIComponent(email)}`
    });
    const data = await res.json();
    if (data.success) {
      refreshGmailModalEmails(true);
      if (data.email_id) {
        setTimeout(() => openGmailModalDetail(data.email_id), 250);
      }
    }
  }

  function escapeGmHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Periodic inbox badge poll every 8 seconds
  setInterval(() => {
    const email = getModalSelectedEmail();
    fetch(`mail_api.php?action=unread_count&email=${encodeURIComponent(email)}&t=${Date.now()}`)
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          const badge = document.getElementById('fdGmailUnreadBadge');
          if (badge) badge.textContent = d.unread_count;
        }
      })
      .catch(() => {});
  }, 8000);

  // Initial fetch for floating counter badge on page load
  document.addEventListener('DOMContentLoaded', () => {
    refreshGmailModalEmails(false);
  });
</script>
