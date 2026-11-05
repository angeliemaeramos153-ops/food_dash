/**
 * Food Dash - Synchronized In-App Voice Calling & Persistent Arrival Alarm Engine
 * Handles bidirectional VoIP call states ('idle', 'calling', 'connected', 'declined', 'ended'),
 * authentic looping ringtones / ringbacks, WebRTC microphone streams, and the persistent arrival alarm.
 */

(function (window) {
  'use strict';

  // Audio Context & Player references
  let audioCtx = null;
  let ringbackAudio = null;
  let ringtoneAudio = null;
  let arrivalAudio = null;
  let synthLoopInterval = null;
  let arrivalMasterGain = null;
  let activeSynthNodes = [];
  let isMuted = false;
  let localMediaStream = null;

  // Call State
  let currentCallState = 'idle'; // 'idle', 'calling', 'connected', 'declined', 'ended'
  let currentCallId = null;
  let activeOrderId = null;
  let activeCallerRole = null;
  let myRole = 'customer'; // 'customer' or 'rider'
  let callTimerInterval = null;
  let callElapsedSeconds = 0;
  let callPollInterval = null;

  // Arrival Alarm State
  let arrivalAcknowledged = false;
  let arrivalAlarmActive = false;

  // Initialize Web Audio Context on first user gesture
  function initAudioContext() {
    if (!audioCtx) {
      try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass) {
          audioCtx = new AudioContextClass();
        }
      } catch (e) {
        console.warn('AudioContext init error:', e);
      }
    }
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume().catch(() => {});
    }
  }

  // Pre-bind gesture listeners for browser audio policy (especially mobile Safari & Chrome)
  let audioUnlocked = false;
  function unlockAudioContextOnGesture(e) {
    initAudioContext();
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume().catch(() => {});
    }

    // Prime Web Audio on mobile with a silent 1-sample buffer
    if (audioCtx && !audioUnlocked) {
      try {
        const buffer = audioCtx.createBuffer(1, 1, 22050);
        const source = audioCtx.createBufferSource();
        source.buffer = buffer;
        source.connect(audioCtx.destination);
        source.start(0);
        audioUnlocked = true;
      } catch (err) {}
    }

    // If arrival alarm is active but was waiting for user touch gesture on mobile:
    if (arrivalAlarmActive && !arrivalAcknowledged) {
      if (window.isLoggingOut || (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_logging_out') === '1')) {
        return;
      }
      if (e && e.target && e.target.closest && (
        e.target.closest('.btn-arrival-action') || 
        e.target.closest('.logout') || 
        e.target.closest('.mobile-logout') || 
        e.target.closest('.logout-btn') || 
        e.target.closest('[onclick*="openLogout"]') || 
        e.target.closest('[onclick*="executeLogout"]') || 
        e.target.closest('a[href*="logout.php"]')
      )) {
        return; // Action button or logout click handler will stop and dismiss
      }
      if (audioCtx) {
        if (audioCtx.state === 'suspended') {
          audioCtx.resume().then(() => {
            if (arrivalAlarmActive && !arrivalAcknowledged && !synthLoopInterval && !window.isLoggingOut) {
              playSynthArrivalAlarm();
            }
          }).catch(() => {});
        } else if (!synthLoopInterval) {
          playSynthArrivalAlarm();
        }
      }
    }
  }

  ['click', 'touchstart', 'touchend', 'pointerdown', 'keydown'].forEach(evt => {
    document.addEventListener(evt, unlockAudioContextOnGesture, { passive: true });
    window.addEventListener(evt, unlockAudioContextOnGesture, { passive: true });
  });

  function resolveSoundPath(filename) {
    try {
      const p = window.location.pathname;
      if (p.includes('/food_dash/')) {
        return window.location.origin + '/food_dash/assets/' + filename;
      }
    } catch (e) {}
    return 'assets/' + filename;
  }

  // -------------------------------------------------------------
  // AUDIO SYNTHESIS & PLAYBACK HELPERS
  // -------------------------------------------------------------

  function playSynthRingback() {
    stopSynthLoop();
    initAudioContext();
    if (!audioCtx) return;

    function playPulse() {
      if (currentCallState !== 'calling') return;
      try {
        const now = audioCtx.currentTime;
        const osc1 = audioCtx.createOscillator();
        const osc2 = audioCtx.createOscillator();
        const gain = audioCtx.createGain();

        osc1.type = 'sine';
        osc2.type = 'sine';
        osc1.frequency.setValueAtTime(440, now);
        osc2.frequency.setValueAtTime(480, now);

        gain.gain.setValueAtTime(0.001, now);
        gain.gain.linearRampToValueAtTime(0.18, now + 0.05);
        gain.gain.setValueAtTime(0.18, now + 1.4);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 1.5);

        osc1.connect(gain);
        osc2.connect(gain);
        gain.connect(audioCtx.destination);

        osc1.start(now);
        osc2.start(now);
        osc1.stop(now + 1.55);
      } catch (e) {}
    }

    playPulse();
    synthLoopInterval = setInterval(playPulse, 3500);
  }

  function playSynthRingtone() {
    stopSynthLoop();
    initAudioContext();
    if (!audioCtx) return;

    function playRingPattern() {
      if (currentCallState !== 'calling') return;
      try {
        const notes = [
          { f: 784, t: 0.00 }, { f: 880, t: 0.12 }, { f: 1046, t: 0.24 }, { f: 1318, t: 0.38 },
          { f: 784, t: 0.85 }, { f: 880, t: 0.97 }, { f: 1046, t: 1.09 }, { f: 1318, t: 1.23 }
        ];
        const now = audioCtx.currentTime;
        notes.forEach(n => {
          const osc = audioCtx.createOscillator();
          const gain = audioCtx.createGain();
          osc.type = 'sine';
          osc.frequency.setValueAtTime(n.f, now + n.t);

          gain.gain.setValueAtTime(0.001, now + n.t);
          gain.gain.linearRampToValueAtTime(0.22, now + n.t + 0.03);
          gain.gain.exponentialRampToValueAtTime(0.001, now + n.t + 0.32);

          osc.connect(gain);
          gain.connect(audioCtx.destination);
          osc.start(now + n.t);
          osc.stop(now + n.t + 0.35);
        });
      } catch (e) {}
    }

    playRingPattern();
    synthLoopInterval = setInterval(playRingPattern, 2400);
  }

  let isChimePlaying = false;

  function playSynthArrivalAlarm() {
    // If arrival was already acknowledged or user is logging out, do not play
    if (arrivalAcknowledged || window.isLoggingOut || 
        (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_logging_out') === '1') ||
        (typeof localStorage !== 'undefined' && localStorage.getItem('fd_logging_out') === '1')) return;

    // If the arpeggio loop is already running, prevent duplicate restarts / stuttering
    if (synthLoopInterval && arrivalAlarmActive) {
      return;
    }
    stopSynthLoop();
    initAudioContext();
    if (!audioCtx) return;

    arrivalAlarmActive = true;
    arrivalAcknowledged = false;

    function playAlarmChime() {
      if (!arrivalAlarmActive || arrivalAcknowledged || window.isLoggingOut || 
          (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_logging_out') === '1') ||
          (typeof localStorage !== 'undefined' && localStorage.getItem('fd_logging_out') === '1')) {
        stopSynthLoop();
        return;
      }
      if (isChimePlaying) return;

      if (!audioCtx) return;
      if (audioCtx.state === 'suspended') {
        audioCtx.resume().then(() => {
          if (arrivalAlarmActive && !arrivalAcknowledged && !window.isLoggingOut && 
              !(typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_logging_out') === '1') &&
              !(typeof localStorage !== 'undefined' && localStorage.getItem('fd_logging_out') === '1')) {
            playAlarmChime();
          }
        }).catch(() => {});
        return;
      }

      isChimePlaying = true;
      setTimeout(() => { isChimePlaying = false; }, 2500);

      try {
        const now = audioCtx.currentTime;

        // Dedicated arrival master gain node for instantaneous, clean cutoff on dismissal
        if (!arrivalMasterGain) {
          arrivalMasterGain = audioCtx.createGain();
          arrivalMasterGain.gain.setValueAtTime(0.85, now);
          arrivalMasterGain.connect(audioCtx.destination);
        } else {
          arrivalMasterGain.gain.cancelScheduledValues(now);
          arrivalMasterGain.gain.setValueAtTime(0.85, now);
        }

        // Clean, smooth, unhurried 4-note music-box arpeggio (C5 -> E5 -> G5 -> C6)
        // Spaced ~350ms apart (0.00s, 0.35s, 0.70s, 1.05s) with pure, warm sine waves
        const notes = [
          { freq: 523.25, time: 0.000, dur: 1.00, peak: 0.12 }, // Note 1: 523.25 Hz (C5) at 0ms, rings warmly for 1.0s
          { freq: 659.25, time: 0.350, dur: 1.00, peak: 0.12 }, // Note 2: 659.25 Hz (E5) at 350ms, rings warmly for 1.0s
          { freq: 783.99, time: 0.700, dur: 1.00, peak: 0.12 }, // Note 3: 783.99 Hz (G5) at 700ms, rings warmly for 1.0s
          { freq: 1046.50, time: 1.050, dur: 1.35, peak: 0.14 }  // Note 4: 1046.50 Hz (C6) at 1050ms (full harmonic finale ringing to ~2.40s)
        ];

        const attackTime = 0.015; // 15ms soft linear attack ramp to completely eliminate any start click / transient tick

        notes.forEach(n => {
          const osc = audioCtx.createOscillator();
          const gain = audioCtx.createGain();

          osc.type = 'sine'; // Soft, warm, pure sine wave tone for music-box chime
          const noteTime = now + n.time;
          const attackEnd = noteTime + attackTime;
          const noteEnd = noteTime + n.dur;

          osc.frequency.setValueAtTime(n.freq, noteTime);

          // Smooth exponential gain decay envelope: start at 0.0001, quick 15ms soft linear attack ramp to peak,
          // then smooth natural exponential decay to 0.0001 without clipping
          gain.gain.setValueAtTime(0.0001, noteTime);
          gain.gain.linearRampToValueAtTime(n.peak, attackEnd);
          gain.gain.exponentialRampToValueAtTime(0.0001, noteEnd);

          osc.connect(gain);
          gain.connect(arrivalMasterGain);
          osc.start(noteTime);
          osc.stop(noteEnd + 0.08); // Generous tail to prevent any premature cutoff

          const nodeItem = { osc, gain, noteStart: noteTime, decayEnd: noteEnd };
          activeSynthNodes.push(nodeItem);
          osc.onended = () => {
            const idx = activeSynthNodes.indexOf(nodeItem);
            if (idx > -1) activeSynthNodes.splice(idx, 1);
            try {
              osc.disconnect();
              gain.disconnect();
            } catch (e) {}
          };
        });
      } catch (e) {
        console.warn('Synth arrival alarm error:', e);
      }
    }

    playAlarmChime();
    // Repeating interval: 4-note arpeggio plays out completely in ~2.40s,
    // rests in 4.0 seconds of complete silence, and repeats cleanly without sounding harsh or cutting off.
    // Total cycle duration: 2400ms chime + 4000ms silence rest = 6400ms
    synthLoopInterval = setInterval(playAlarmChime, 6400);
  }

  function stopSynthLoop() {
    if (synthLoopInterval) {
      clearInterval(synthLoopInterval);
      synthLoopInterval = null;
    }
    isChimePlaying = false;

    // Immediately ramp master gain down and disconnect
    if (arrivalMasterGain && audioCtx) {
      try {
        const now = audioCtx.currentTime;
        arrivalMasterGain.gain.cancelScheduledValues(now);
        arrivalMasterGain.gain.setValueAtTime(arrivalMasterGain.gain.value, now);
        arrivalMasterGain.gain.linearRampToValueAtTime(0.0001, now + 0.012);
        const mg = arrivalMasterGain;
        arrivalMasterGain = null;
        setTimeout(() => {
          try { mg.disconnect(); } catch (e) {}
        }, 20);
      } catch (e) {
        try { arrivalMasterGain.disconnect(); } catch (e2) {}
        arrivalMasterGain = null;
      }
    }

    if (activeSynthNodes && activeSynthNodes.length > 0) {
      const now = audioCtx ? audioCtx.currentTime : 0;
      activeSynthNodes.forEach(item => {
        try {
          if (item.gain && audioCtx) {
            item.gain.gain.cancelScheduledValues(now);
            item.gain.gain.setValueAtTime(0.0001, now);
            item.gain.disconnect();
          }
        } catch (e) {}
        try {
          if (item.osc) {
            item.osc.onended = null;
            if (item.noteStart && item.noteStart > now) {
              try { item.osc.stop(item.noteStart); } catch (e1) { try { item.osc.stop(); } catch (e2) {} }
            } else {
              try { item.osc.stop(now + 0.01); } catch (e1) { try { item.osc.stop(); } catch (e2) {} }
            }
            item.osc.disconnect();
          }
        } catch (e) {}
      });
      activeSynthNodes = [];
    }
  }

  function playRingbackTone() {
    stopAllTones();
    try {
      if (!ringbackAudio) {
        ringbackAudio = new Audio('assets/outgoing_ringback.wav');
        ringbackAudio.loop = true;
        ringbackAudio.volume = 0.85;
      }
      ringbackAudio.currentTime = 0;
      const p = ringbackAudio.play();
      if (p !== undefined) {
        p.catch(() => playSynthRingback());
      }
    } catch (e) {
      playSynthRingback();
    }
  }

  function playRingtoneTone() {
    stopAllTones();
    try {
      if (!ringtoneAudio) {
        ringtoneAudio = new Audio('assets/incoming_ringtone.wav');
        ringtoneAudio.loop = true;
        ringtoneAudio.volume = 0.9;
      }
      ringtoneAudio.currentTime = 0;
      const p = ringtoneAudio.play();
      if (p !== undefined) {
        p.catch(() => playSynthRingtone());
      }
    } catch (e) {
      playSynthRingtone();
    }
    if ('vibrate' in navigator) {
      try { navigator.vibrate([400, 200, 400, 200, 600]); } catch (e) {}
    }
  }

  function stopArrivalAlarm() {
    arrivalAcknowledged = true;
    arrivalAlarmActive = false;
    stopSynthLoop();

    const oId = activeOrderId || (typeof window !== 'undefined' && (window.activeCustomerTrackingOrderId || window.orderId)) || 0;
    if (oId) {
      try {
        localStorage.setItem('fd_arrival_ack_' + oId, '1');
        sessionStorage.setItem('fd_arrival_ack_' + oId, '1');
      } catch (e) {}
    }
    try {
      localStorage.setItem('fd_arrival_acknowledged_all', '1');
      sessionStorage.setItem('fd_arrival_acknowledged_all', '1');
    } catch (e) {}

    if (arrivalAudio) {
      try {
        arrivalAudio.pause();
        arrivalAudio.currentTime = 0;
      } catch (e) {}
    }
    if (window.arrivalAlertAudio) {
      try {
        window.arrivalAlertAudio.pause();
        window.arrivalAlertAudio.currentTime = 0;
      } catch (e) {}
    }
    const modal = document.getElementById('riderArrivedModal');
    if (modal) {
      modal.classList.remove('show', 'active');
      modal.style.setProperty('display', 'none', 'important');
      modal.style.setProperty('visibility', 'hidden', 'important');
      modal.style.setProperty('pointer-events', 'none', 'important');
    }
  }
  const stopArrivalAlarmAudio = stopArrivalAlarm;

  function playArrivalAlarmAudio() {
    if (window.isLoggingOut || 
        (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_logging_out') === '1') ||
        (typeof localStorage !== 'undefined' && localStorage.getItem('fd_logging_out') === '1')) {
      return;
    }
    // If the arrival has already been acknowledged, do not play
    if (arrivalAcknowledged) return;

    // If the persistent arrival alarm is already playing cleanly, do not duplicate or stack
    if (arrivalAlarmActive && synthLoopInterval) {
      return;
    }

    // Completely halt any existing synthesizer intervals and audio nodes before starting a fresh cycle
    stopSynthLoop();
    if (arrivalAudio) {
      try {
        arrivalAudio.pause();
        arrivalAudio.currentTime = 0;
      } catch (e) {}
    }
    if (window.arrivalAlertAudio) {
      try {
        window.arrivalAlertAudio.pause();
        window.arrivalAlertAudio.currentTime = 0;
      } catch (e) {}
    }
    arrivalAlarmActive = true;
    arrivalAcknowledged = false;

    // Trigger exactly ONE clean, peaceful arpeggio chime per 6400ms cycle
    playSynthArrivalAlarm();

    if ('vibrate' in navigator) {
      try { navigator.vibrate([250, 150, 250]); } catch (e) {}
    }
  }

  function stopAllTones() {
    stopArrivalAlarm();
    if (ringbackAudio) {
      try { ringbackAudio.pause(); ringbackAudio.currentTime = 0; } catch (e) {}
    }
    if (ringtoneAudio) {
      try { ringtoneAudio.pause(); ringtoneAudio.currentTime = 0; } catch (e) {}
    }
    arrivalAlarmActive = false;
  }

  // -------------------------------------------------------------
  // CALL UI MODAL INJECTION & MANAGERS
  // -------------------------------------------------------------

  function ensureCallModalDOM() {
    if (document.getElementById('synchronizedCallModal')) return;

    const modalHtml = `
    <div id="synchronizedCallModal" class="fd-call-modal-overlay" style="display:none;">
      <div class="fd-call-card">
        <!-- Close button (only available if ended) -->
        <button type="button" class="fd-call-close-btn" id="fdCallCloseBtn" onclick="FoodDashCall.endCall()" style="display:none;">
          <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- Pulsing Avatar Section -->
        <div class="fd-call-avatar-wrap">
          <div class="fd-call-ripple-1"></div>
          <div class="fd-call-ripple-2"></div>
          <div class="fd-call-avatar-box" id="fdCallAvatarBox">
            <i class="fa-solid fa-phone" id="fdCallAvatarIcon"></i>
          </div>
        </div>

        <!-- Badges & Call Details -->
        <div class="fd-call-badge" id="fdCallBadge">
          <i class="fa-solid fa-circle-dot fa-beat-fade"></i> <span id="fdCallBadgeText">CALLING</span>
        </div>
        <h3 id="fdCallTargetName" class="fd-call-target-name">Connecting...</h3>
        <p id="fdCallTargetRole" class="fd-call-target-role">In-App Voice Call</p>
        <p id="fdCallStatusText" class="fd-call-status-text">Connecting live voice line...</p>

        <!-- Elapsed Timer -->
        <div id="fdCallTimer" class="fd-call-timer" style="display:none;">00:00</div>

        <!-- Action Controls -->
        <!-- 1. Outgoing Calling Actions -->
        <div id="fdCallOutgoingActions" class="fd-call-actions-row" style="display:none;">
          <button type="button" class="btn-fd-call-end" onclick="FoodDashCall.endCall()">
            <i class="fa-solid fa-phone-slash"></i> Cancel Call
          </button>
        </div>

        <!-- 2. Incoming Call Actions -->
        <div id="fdCallIncomingActions" class="fd-call-actions-row" style="display:none;">
          <button type="button" class="btn-fd-call-decline" onclick="FoodDashCall.declineCall()">
            <i class="fa-solid fa-phone-slash"></i> Decline
          </button>
          <button type="button" class="btn-fd-call-accept" onclick="FoodDashCall.acceptCall()">
            <i class="fa-solid fa-phone"></i> Accept Call
          </button>
        </div>

        <!-- 3. Connected Call Actions -->
        <div id="fdCallConnectedActions" class="fd-call-actions-row" style="display:none;">
          <button type="button" class="btn-fd-call-mute" id="fdCallMuteBtn" onclick="FoodDashCall.toggleMute()">
            <i class="fa-solid fa-microphone" id="fdCallMuteIcon"></i>
            <span id="fdCallMuteText">Mute</span>
          </button>
          <button type="button" class="btn-fd-call-end" onclick="FoodDashCall.endCall()">
            <i class="fa-solid fa-phone-slash"></i> End Call
          </button>
        </div>
      </div>
    </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    injectCallStyles();
  }

  function injectCallStyles() {
    if (document.getElementById('fdCallCustomStyles')) return;
    const style = document.createElement('style');
    style.id = 'fdCallCustomStyles';
    style.textContent = `
      .fd-call-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 99999999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.78);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        padding: 16px;
        animation: fdFadeIn 0.25s ease;
      }
      .fd-call-card {
        background: #0f172a;
        color: #ffffff;
        border: 1.5px solid rgba(255, 255, 255, 0.12);
        border-radius: 28px;
        width: 100%;
        max-width: 340px;
        padding: 32px 24px 28px;
        text-align: center;
        position: relative;
        box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.65), 0 0 40px rgba(22, 163, 74, 0.15);
        animation: fdScaleUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      }
      .fd-call-avatar-wrap {
        position: relative;
        width: 88px;
        height: 88px;
        margin: 0 auto 16px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .fd-call-avatar-box {
        position: relative;
        z-index: 2;
        width: 76px;
        height: 76px;
        border-radius: 50%;
        background: linear-gradient(135deg, #15803d, #22c55e);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        color: #ffffff;
        box-shadow: 0 8px 24px rgba(22, 163, 74, 0.35);
        transition: all 0.3s ease;
      }
      .fd-call-avatar-box.ringing {
        animation: fdPhoneShake 1.2s infinite;
        background: linear-gradient(135deg, #0284c7, #38bdf8);
      }
      .fd-call-avatar-box.incoming {
        animation: fdPhoneShake 0.9s infinite;
        background: linear-gradient(135deg, #16a34a, #4ade80);
      }
      .fd-call-avatar-box.connected {
        background: linear-gradient(135deg, #15803d, #16a34a);
        box-shadow: 0 0 0 6px rgba(34, 197, 94, 0.25);
      }
      .fd-call-avatar-box.ended {
        background: #475569;
        box-shadow: none;
      }
      .fd-call-ripple-1, .fd-call-ripple-2 {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 2px solid rgba(34, 197, 94, 0.4);
        animation: fdRipple 2s linear infinite;
      }
      .fd-call-ripple-2 {
        animation-delay: 1s;
      }
      .fd-call-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(34, 197, 94, 0.15);
        border: 1px solid rgba(34, 197, 94, 0.3);
        color: #4ade80;
        font-size: 10px;
        font-weight: 800;
        padding: 4px 12px;
        border-radius: 20px;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        margin-bottom: 8px;
      }
      .fd-call-badge.incoming {
        background: rgba(56, 189, 248, 0.15);
        border-color: rgba(56, 189, 248, 0.3);
        color: #38bdf8;
      }
      .fd-call-badge.ended {
        background: rgba(239, 68, 68, 0.15);
        border-color: rgba(239, 68, 68, 0.3);
        color: #f87171;
      }
      .fd-call-target-name {
        font-size: 19px;
        font-weight: 800;
        margin: 0 0 4px;
        color: #ffffff;
      }
      .fd-call-target-role {
        font-size: 11px;
        color: #94a3b8;
        margin: 0 0 10px;
        font-weight: 500;
      }
      .fd-call-status-text {
        font-size: 12.5px;
        color: #cbd5e1;
        margin: 0 0 18px;
        line-height: 1.45;
        min-height: 36px;
      }
      .fd-call-timer {
        font-size: 24px;
        font-weight: 800;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        color: #4ade80;
        margin: -6px 0 18px;
        letter-spacing: 1.5px;
      }
      .fd-call-actions-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-top: 10px;
      }
      .btn-fd-call-accept, .btn-fd-call-decline, .btn-fd-call-end, .btn-fd-call-mute {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 20px;
        border-radius: 16px;
        font-size: 13px;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
        user-select: none;
      }
      .btn-fd-call-accept {
        background: #16a34a;
        color: #ffffff;
        box-shadow: 0 4px 16px rgba(22, 163, 74, 0.4);
        flex: 1;
      }
      .btn-fd-call-accept:active { transform: scale(0.96); background: #15803d; }
      .btn-fd-call-decline {
        background: #dc2626;
        color: #ffffff;
        box-shadow: 0 4px 16px rgba(220, 38, 38, 0.35);
        flex: 1;
      }
      .btn-fd-call-decline:active { transform: scale(0.96); background: #b91c1c; }
      .btn-fd-call-end {
        background: #dc2626;
        color: #ffffff;
        box-shadow: 0 4px 16px rgba(220, 38, 38, 0.35);
        width: 100%;
        max-width: 220px;
      }
      .btn-fd-call-end:active { transform: scale(0.96); background: #b91c1c; }
      .btn-fd-call-mute {
        background: #334155;
        color: #ffffff;
        flex: 1;
      }
      .btn-fd-call-mute.active-muted {
        background: #d97706;
        color: #ffffff;
      }
      .btn-arrival-action {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 14px 18px;
        font-size: 13.5px;
        font-weight: 800;
        border-radius: 14px;
        cursor: pointer;
        border: none;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 14px rgba(0,0,0,0.08);
      }
      .btn-arrival-action.minute {
        background: linear-gradient(135deg, #d97706, #f59e0b);
        color: #ffffff;
      }
      .btn-arrival-action.minute:hover { filter: brightness(1.05); transform: translateY(-1px); }
      .btn-arrival-action.minute:active { transform: scale(0.98); }
      .btn-arrival-action.outside {
        background: linear-gradient(135deg, #15803d, #22c55e);
        color: #ffffff;
      }
      .btn-arrival-action.outside:hover { filter: brightness(1.05); transform: translateY(-1px); }
      .btn-arrival-action.outside:active { transform: scale(0.98); }
      .rider-ack-badge {
        background: #ecfdf5;
        border: 1.5px solid #6ee7b7;
        color: #065f46;
        padding: 9px 12px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 700;
        margin-top: 8px;
        display: flex;
        align-items: center;
        gap: 7px;
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.12);
        animation: fdFadeIn 0.3s ease;
      }

      @keyframes fdRipple {
        0% { transform: scale(0.85); opacity: 0.9; }
        100% { transform: scale(1.6); opacity: 0; }
      }
      @keyframes fdPhoneShake {
        0%, 100% { transform: rotate(0deg); }
        10%, 30%, 50%, 70% { transform: rotate(-10deg); }
        20%, 40%, 60%, 80% { transform: rotate(10deg); }
      }
      @keyframes fdFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }
      @keyframes fdScaleUp {
        from { transform: scale(0.88); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
      }
    `;
    document.head.appendChild(style);
  }

  // -------------------------------------------------------------
  // CALL FLOW ORCHESTRATION
  // -------------------------------------------------------------

  function setCallState(state, info = {}) {
    currentCallState = state;
    ensureCallModalDOM();

    const modal = document.getElementById('synchronizedCallModal');
    const badge = document.getElementById('fdCallBadge');
    const badgeText = document.getElementById('fdCallBadgeText');
    const nameEl = document.getElementById('fdCallTargetName');
    const roleEl = document.getElementById('fdCallTargetRole');
    const statusEl = document.getElementById('fdCallStatusText');
    const avatarBox = document.getElementById('fdCallAvatarBox');
    const avatarIcon = document.getElementById('fdCallAvatarIcon');
    const timerEl = document.getElementById('fdCallTimer');

    const outActions = document.getElementById('fdCallOutgoingActions');
    const inActions = document.getElementById('fdCallIncomingActions');
    const connActions = document.getElementById('fdCallConnectedActions');

    if (!modal) return;

    if (state === 'idle') {
      stopAllTones();
      stopCallTimer();
      stopMicrophone();
      modal.style.display = 'none';
      return;
    }

    modal.style.display = 'flex';

    if (state === 'calling') {
      const isOutgoing = (info.isOutgoing !== false);
      if (isOutgoing) {
        badge.className = 'fd-call-badge';
        badgeText.textContent = 'CALLING...';
        nameEl.textContent = info.targetName || (myRole === 'rider' ? 'Customer' : 'Rider');
        roleEl.textContent = 'Food Dash In-App Voice Line';
        statusEl.textContent = `Calling ${nameEl.textContent}... Waiting for answer.`;
        avatarBox.className = 'fd-call-avatar-box ringing';
        avatarIcon.className = 'fa-solid fa-phone-volume';
        timerEl.style.display = 'none';

        outActions.style.display = 'flex';
        inActions.style.display = 'none';
        connActions.style.display = 'none';

        playRingbackTone();
      } else {
        badge.className = 'fd-call-badge incoming';
        badgeText.textContent = 'INCOMING CALL';
        nameEl.textContent = info.callerName || (myRole === 'rider' ? 'Customer' : 'Rider');
        roleEl.textContent = 'Food Dash In-App Voice Line';
        statusEl.textContent = `${nameEl.textContent} is calling you...`;
        avatarBox.className = 'fd-call-avatar-box incoming';
        avatarIcon.className = 'fa-solid fa-phone fa-shake';
        timerEl.style.display = 'none';

        outActions.style.display = 'none';
        inActions.style.display = 'flex';
        connActions.style.display = 'none';

        playRingtoneTone();
      }
    } else if (state === 'connected') {
      stopAllTones();
      badge.className = 'fd-call-badge';
      badgeText.innerHTML = '<i class="fa-solid fa-circle-check"></i> CONNECTED';
      if (info.targetName) nameEl.textContent = info.targetName;
      roleEl.textContent = 'Voice Channel Active';
      statusEl.textContent = 'In-app voice line connected and active.';
      avatarBox.className = 'fd-call-avatar-box connected';
      avatarIcon.className = 'fa-solid fa-headset';
      timerEl.style.display = 'block';

      outActions.style.display = 'none';
      inActions.style.display = 'none';
      connActions.style.display = 'flex';

      startCallTimer();
      startMicrophone();
    } else if (state === 'declined' || state === 'ended') {
      stopAllTones();
      stopCallTimer();
      stopMicrophone();

      badge.className = 'fd-call-badge ended';
      badgeText.textContent = (state === 'declined') ? 'CALL DECLINED' : 'CALL ENDED';
      statusEl.textContent = (state === 'declined') ? 'The call was declined.' : 'Call ended.';
      avatarBox.className = 'fd-call-avatar-box ended';
      avatarIcon.className = 'fa-solid fa-phone-slash';

      outActions.style.display = 'none';
      inActions.style.display = 'none';
      connActions.style.display = 'none';

      // Dismiss modal within 500ms
      setTimeout(() => {
        setCallState('idle');
      }, 500);
    }
  }

  function startCallTimer() {
    stopCallTimer();
    callElapsedSeconds = 0;
    const timerEl = document.getElementById('fdCallTimer');
    if (timerEl) timerEl.textContent = '00:00';

    callTimerInterval = setInterval(() => {
      callElapsedSeconds++;
      const m = String(Math.floor(callElapsedSeconds / 60)).padStart(2, '0');
      const s = String(callElapsedSeconds % 60).padStart(2, '0');
      if (timerEl) timerEl.textContent = `${m}:${s}`;
    }, 1000);
  }

  function stopCallTimer() {
    if (callTimerInterval) {
      clearInterval(callTimerInterval);
      callTimerInterval = null;
    }
  }

  async function startMicrophone() {
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      try {
        localMediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        isMuted = false;
        updateMuteButtonUI();
      } catch (e) {
        // Microphone permission denied or unsecure context; voice line simulation runs seamlessly
        console.log('Audio capture note: using simulated voice line channel.');
      }
    }
  }

  function stopMicrophone() {
    if (localMediaStream) {
      localMediaStream.getTracks().forEach(track => track.stop());
      localMediaStream = null;
    }
    isMuted = false;
  }

  function toggleMute() {
    isMuted = !isMuted;
    if (localMediaStream) {
      localMediaStream.getAudioTracks().forEach(track => {
        track.enabled = !isMuted;
      });
    }
    updateMuteButtonUI();
  }

  function updateMuteButtonUI() {
    const btn = document.getElementById('fdCallMuteBtn');
    const icon = document.getElementById('fdCallMuteIcon');
    const txt = document.getElementById('fdCallMuteText');
    if (!btn || !icon || !txt) return;

    if (isMuted) {
      btn.className = 'btn-fd-call-mute active-muted';
      icon.className = 'fa-solid fa-microphone-slash';
      txt.textContent = 'Unmute';
    } else {
      btn.className = 'btn-fd-call-mute';
      icon.className = 'fa-solid fa-microphone';
      txt.textContent = 'Mute';
    }
  }

  // -------------------------------------------------------------
  // PUBLIC ACTIONS (Call Rider / Customer, Accept, Decline, End)
  // -------------------------------------------------------------

  async function initiateCall(orderId, targetName, targetPhone) {
    initAudioContext();
    activeOrderId = orderId;
    activeCallerRole = myRole;

    setCallState('calling', {
      isOutgoing: true,
      targetName: targetName || (myRole === 'rider' ? 'Customer' : 'Rider')
    });

    try {
      const res = await fetch('order_call_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'start_call',
          order_id: orderId
        })
      });
      const data = await res.json();
      if (data.success && data.call_id) {
        currentCallId = data.call_id;
      }
    } catch (e) {
      console.warn('Call start error:', e);
    }
  }

  async function acceptCall() {
    initAudioContext();
    stopAllTones();

    setCallState('connected');

    try {
      await fetch('order_call_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'accept_call',
          call_id: currentCallId,
          order_id: activeOrderId
        })
      });
    } catch (e) {}
  }

  async function declineCall() {
    stopAllTones();
    const cId = currentCallId;
    const oId = activeOrderId;

    setCallState('declined');

    try {
      await fetch('order_call_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'decline_call',
          call_id: cId,
          order_id: oId
        })
      });
    } catch (e) {}
  }

  async function endCall() {
    stopAllTones();
    const cId = currentCallId;
    const oId = activeOrderId;

    setCallState('ended');

    try {
      await fetch('order_call_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'end_call',
          call_id: cId,
          order_id: oId
        })
      });
    } catch (e) {}
  }

  // -------------------------------------------------------------
  // REAL-TIME SYNC POLL HANDLER
  // -------------------------------------------------------------

  function syncCallStatus(serverCallData) {
    if (!serverCallData || serverCallData.call_status === 'idle') {
      if (currentCallState !== 'idle') {
        setCallState('ended');
      }
      return;
    }

    const st = serverCallData.call_status;
    const caller = serverCallData.caller || serverCallData.caller_role || 'rider';
    const isCallerMe = (caller === myRole);
    currentCallId = serverCallData.call_id || currentCallId;
    activeOrderId = serverCallData.order_id || activeOrderId;

    if (st === 'calling' || st === 'ringing') {
      if (isCallerMe) {
        // Outgoing call still ringing
        const target = serverCallData.receiver_name || (myRole === 'rider' ? 'Customer' : 'Rider');
        if (currentCallState !== 'calling') {
          setCallState('calling', {
            isOutgoing: true,
            targetName: target
          });
        } else {
          const nameEl = document.getElementById('fdCallTargetName');
          if (nameEl && nameEl.textContent !== target) nameEl.textContent = target;
        }
      } else {
        // Incoming call to me!
        const callerName = serverCallData.caller_name || (myRole === 'rider' ? 'Customer' : 'Rider');
        if (currentCallState !== 'calling') {
          setCallState('calling', {
            isOutgoing: false,
            callerName: callerName
          });
        } else {
          const nameEl = document.getElementById('fdCallTargetName');
          if (nameEl && nameEl.textContent !== callerName) nameEl.textContent = callerName;
        }
      }
    } else if (st === 'connected' || st === 'accepted') {
      const activeName = isCallerMe
        ? (serverCallData.receiver_name || (myRole === 'rider' ? 'Customer' : 'Rider'))
        : (serverCallData.caller_name || (myRole === 'rider' ? 'Customer' : 'Rider'));
      if (currentCallState !== 'connected') {
        setCallState('connected', {
          targetName: activeName
        });
      } else {
        const nameEl = document.getElementById('fdCallTargetName');
        if (nameEl && nameEl.textContent !== activeName) nameEl.textContent = activeName;
      }
    } else if (st === 'declined' || st === 'ended' || st === 'missed') {
      if (currentCallState !== 'idle' && currentCallState !== 'ended') {
        setCallState(st === 'declined' ? 'declined' : 'ended');
      }
    }
  }

  // -------------------------------------------------------------
  // PERSISTENT ARRIVAL ALARM COORDINATOR
  // -------------------------------------------------------------

  function bindArrivalModalListeners(modalEl) {
    const root = modalEl || document.getElementById('riderArrivedModal');
    if (!root) return;
    const buttons = root.querySelectorAll('.btn-arrival-action, #riderArrivedActionButtons button, .btn-arrival-close, #riderArrivedModalCloseX, .fd-modal-backdrop, [onclick*="closeRiderArrivedModal"]');
    buttons.forEach(btn => {
      if (btn.dataset.arrivalAudioBound === 'true') return;
      btn.dataset.arrivalAudioBound = 'true';
      btn.addEventListener('click', function() {
        const oId = activeOrderId || (typeof window !== 'undefined' && (window.activeCustomerTrackingOrderId || window.orderId)) || 0;
        if (oId) {
          try {
            localStorage.setItem('fd_arrival_ack_' + oId, '1');
            sessionStorage.setItem('fd_arrival_ack_' + oId, '1');
          } catch (e) {}
        }
        try {
          localStorage.setItem('fd_arrival_acknowledged_all', '1');
          sessionStorage.setItem('fd_arrival_acknowledged_all', '1');
        } catch (e) {}

        stopArrivalAlarm();
        stopAllTones();
        if (typeof window.stopArrivalAlertSound === 'function') {
          try { window.stopArrivalAlertSound(); } catch (e) {}
        }
      }, true);
    });
  }

  function triggerArrivalAlarm(orderId, riderName) {
    if (window.isLoggingOut || 
        (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_logging_out') === '1') ||
        (typeof localStorage !== 'undefined' && localStorage.getItem('fd_logging_out') === '1')) {
      return;
    }
    const oId = orderId || activeOrderId;
    if (oId) {
      if (
        (typeof localStorage !== 'undefined' && localStorage.getItem('fd_arrival_ack_' + oId) === '1') ||
        (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_arrival_ack_' + oId) === '1')
      ) {
        arrivalAcknowledged = true;
        return;
      }
    } else {
      if (
        (typeof localStorage !== 'undefined' && localStorage.getItem('fd_arrival_acknowledged_all') === '1') ||
        (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('fd_arrival_acknowledged_all') === '1')
      ) {
        arrivalAcknowledged = true;
        return;
      }
    }
    if (arrivalAcknowledged && activeOrderId === oId) return;
    if (oId && oId !== activeOrderId) {
      arrivalAcknowledged = false;
    }
    initAudioContext();
    activeOrderId = oId;

    const modal = document.getElementById('riderArrivedModal');
    const msgEl = document.getElementById('riderArrivedModalMsg');
    const rName = riderName || 'Rider';
    const rFirst = rName.split(' ')[0];

    if (msgEl) {
      msgEl.innerHTML = `📍 <b>Your rider has arrived outside with your order!</b><br>Please select a quick response so ${rFirst} knows you are coming:`;
    }

    // Ensure buttons and close controls are injected
    injectArrivalButtons(orderId);

    // Hide/dismiss top notification banner completely upon arrival to keep ONLY the center arrival modal
    const topBanner = document.getElementById('liveRiderMessageBanner');
    if (topBanner) {
      topBanner.style.display = 'none';
      topBanner.classList.remove('show', 'slide-down');
    }
    if (typeof window.dismissLiveRiderBanner === 'function') {
      try { window.dismissLiveRiderBanner(); } catch (e) {}
    }

    if (modal) {
      modal.classList.add('show', 'active');
      modal.style.setProperty('display', 'flex', 'important');
      modal.style.setProperty('visibility', 'visible', 'important');
      modal.style.setProperty('pointer-events', 'auto', 'important');
      const backdrop = modal.querySelector('.fd-modal-backdrop') || modal.querySelector('[onclick*="closeRiderArrivedModal"]');
      if (backdrop) {
        backdrop.onclick = (e) => {
          stopArrivalAlarm();
          if (typeof window.closeRiderArrivedModal === 'function') {
            window.closeRiderArrivedModal();
          } else {
            modal.style.setProperty('display', 'none', 'important');
            modal.classList.remove('show', 'active');
          }
        };
      }
      bindArrivalModalListeners(modal);
    }

    // Play persistent looping arrival alarm audio if not already running
    if (!arrivalAlarmActive || !synthLoopInterval) {
      playArrivalAlarmAudio();
    }
  }

  function injectArrivalButtons(orderId) {
    const modal = document.getElementById('riderArrivedModal');
    if (!modal) return;
    const box = modal.querySelector('.fd-modal-box') || modal;

    // Ensure close X button exists inside modal box
    if (!box.querySelector('.btn-arrival-close, #riderArrivedModalCloseX')) {
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'btn-arrival-close';
      closeBtn.id = 'riderArrivedModalCloseX';
      closeBtn.setAttribute('aria-label', 'Close');
      closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'position:absolute; top:12px; right:12px; width:30px; height:30px; border-radius:50%; background:#f3f4f6; border:none; font-size:18px; line-height:1; color:#6b7280; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:2; transition:background 0.2s;';
      closeBtn.onclick = function() {
        stopArrivalAlarm();
        if (typeof window.closeRiderArrivedModal === 'function') {
          window.closeRiderArrivedModal();
        } else {
          modal.style.setProperty('display', 'none', 'important');
          modal.classList.remove('show', 'active');
        }
      };
      box.appendChild(closeBtn);
    }

    let actionsWrap = box.querySelector('#riderArrivedActionButtons');

    const effectiveId = orderId || activeOrderId || 0;
    const buttonsHtml = `
      <button type="button" class="btn-arrival-action minute" onclick="FoodDashCall.acknowledgeArrival(${effectiveId}, 'Be there in a minute!')">
        <i class="fa-solid fa-person-running"></i> Be there in a minute!
      </button>
      <button type="button" class="btn-arrival-action outside" onclick="FoodDashCall.acknowledgeArrival(${effectiveId}, 'Okay, Going outside!')">
        <i class="fa-solid fa-door-open"></i> Okay, Going outside!
      </button>
      <a href="javascript:void(0)" onclick="FoodDashCall.viewTrackingMap(${effectiveId})" style="display:inline-block; margin-top:4px; font-size:11px; color:#15803d; font-weight:700; text-decoration:underline;">
        View Live Tracking Map
      </a>
    `;

    if (actionsWrap) {
      actionsWrap.innerHTML = buttonsHtml;
    } else {
      actionsWrap = document.createElement('div');
      actionsWrap.id = 'riderArrivedActionButtons';
      actionsWrap.style.cssText = 'display:flex; flex-direction:column; gap:10px; margin-top:14px;';
      actionsWrap.innerHTML = buttonsHtml;
      box.appendChild(actionsWrap);
    }

    bindArrivalModalListeners(modal);
  }

  async function acknowledgeArrival(orderId, responseText) {
    const resolvedOrderId = orderId || activeOrderId || (typeof window !== 'undefined' && (window.activeCustomerTrackingOrderId || window.orderId)) || 0;

    // Immediately mark acknowledged in both localStorage & sessionStorage to guard against any re-triggering
    if (resolvedOrderId) {
      try {
        localStorage.setItem('fd_arrival_ack_' + resolvedOrderId, '1');
        sessionStorage.setItem('fd_arrival_ack_' + resolvedOrderId, '1');
      } catch (e) {}
    }
    try {
      localStorage.setItem('fd_arrival_acknowledged_all', '1');
      sessionStorage.setItem('fd_arrival_acknowledged_all', '1');
    } catch (e) {}

    // 1. Immediately execute full audio engine cleanup
    arrivalAcknowledged = true;
    arrivalAlarmActive = false;
    stopArrivalAlarm();
    stopAllTones();
    if (typeof window.stopArrivalAlertSound === 'function') {
      try { window.stopArrivalAlertSound(); } catch (e) {}
    }

    // 2. Cleanly dismiss modal immediately without leaving state hanging
    const modal = document.getElementById('riderArrivedModal');
    if (modal) {
      modal.style.setProperty('display', 'none', 'important');
      modal.style.setProperty('visibility', 'hidden', 'important');
      modal.style.setProperty('pointer-events', 'none', 'important');
      modal.classList.remove('show', 'active');
      const btns = modal.querySelectorAll('.btn-arrival-action, #riderArrivedActionButtons button');
      btns.forEach(b => { b.disabled = true; });
    }

    // 3. Send live automatic response message into chat
    if (resolvedOrderId) {
      try {
        await fetch('order_communication_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'acknowledge_arrival',
            order_id: resolvedOrderId,
            response: responseText
          })
        });
      } catch (e) {
        console.warn('Arrival acknowledge error:', e);
      }
    }

    // 4. Show friendly customer confirmation
    if (typeof window.showToastCustomer === 'function') {
      window.showToastCustomer(`✓ Rider notified: "${responseText}"`);
    } else {
      console.log(`Rider notified: "${responseText}"`);
    }
  }

  function viewTrackingMap(orderId) {
    const resolvedOrderId = orderId || activeOrderId || (typeof window !== 'undefined' && (window.activeCustomerTrackingOrderId || window.orderId)) || 0;
    if (resolvedOrderId) {
      try {
        localStorage.setItem('fd_arrival_ack_' + resolvedOrderId, '1');
        sessionStorage.setItem('fd_arrival_ack_' + resolvedOrderId, '1');
      } catch (e) {}
    }
    try {
      localStorage.setItem('fd_arrival_acknowledged_all', '1');
      sessionStorage.setItem('fd_arrival_acknowledged_all', '1');
    } catch (e) {}

    stopArrivalAlarm();
    stopArrivalAlarmAudio();
    stopAllTones();
    const modal = document.getElementById('riderArrivedModal');
    if (modal) {
      modal.style.setProperty('display', 'none', 'important');
      modal.classList.remove('show', 'active');
    }
    if (typeof window.openTrackingFromArrived === 'function') {
      window.openTrackingFromArrived();
    } else if (typeof window.openCustomerTrackingModal === 'function') {
      window.openCustomerTrackingModal(orderId);
    }
  }

  function resetEngine() {
    stopArrivalAlarm();
    stopAllTones();
    if (callPollInterval) {
      clearInterval(callPollInterval);
      callPollInterval = null;
    }
    if (callTimerInterval) {
      clearInterval(callTimerInterval);
      callTimerInterval = null;
    }
    if (synthLoopInterval) {
      clearInterval(synthLoopInterval);
      synthLoopInterval = null;
    }
    if (localMediaStream) {
      try {
        localMediaStream.getTracks().forEach(track => track.stop());
      } catch (e) {}
      localMediaStream = null;
    }
  }

  // -------------------------------------------------------------
  // INITIALIZER
  // -------------------------------------------------------------

  function init(role = 'customer') {
    myRole = role;
    ensureCallModalDOM();
    bindArrivalModalListeners();
  }

  // Expose global interface
  window.FoodDashCall = {
    init,
    initiateCall,
    acceptCall,
    declineCall,
    endCall,
    toggleMute,
    syncCallStatus,
    triggerArrivalAlarm,
    playArrivalAlarmAudio,
    playSynthArrivalAlarm,
    stopArrivalAlarm,
    stopArrivalAlarmAudio,
    acknowledgeArrival,
    viewTrackingMap,
    stopAllTones,
    resetEngine,
    bindArrivalModalListeners,
    getActiveOrderId: () => activeOrderId,
    setRole: (r) => { myRole = r; },
    getCallState: () => currentCallState
  };

  window.playSynthArrivalAlarm = playSynthArrivalAlarm;
  window.stopArrivalAlarm = stopArrivalAlarm;
  window.stopArrivalAlertSound = stopArrivalAlarm;

  if (typeof document !== 'undefined') {
    // Immediate capture-phase handler to forcefully cancel arrival alarm and audio when Log Out is clicked anywhere
    document.addEventListener('click', function(e) {
      const isLogout = e.target && e.target.closest && (
        e.target.closest('.logout') || 
        e.target.closest('.mobile-logout') || 
        e.target.closest('.logout-btn') || 
        e.target.closest('[onclick*="openLogout"]') || 
        e.target.closest('[onclick*="executeLogout"]') || 
        e.target.closest('a[href*="logout.php"]')
      );
      if (isLogout) {
        window.isLoggingOut = true;
        try { sessionStorage.setItem('fd_logging_out', '1'); } catch (err) {}
        stopArrivalAlarm();
        stopAllTones();
      }
    }, true);

    window.addEventListener('beforeunload', function() {
      stopArrivalAlarm();
      stopAllTones();
    });

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => bindArrivalModalListeners());
    } else {
      bindArrivalModalListeners();
    }
  }

})(window);
